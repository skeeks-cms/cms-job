<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 * @author Semenov Alexander <semenov@skeeks.com>
 */

namespace skeeks\cms\job\handlers;

use skeeks\cms\job\contracts\JobReporterInterface;
use skeeks\cms\job\exceptions\JobCancelledException;
use skeeks\cms\job\exceptions\JobPermanentException;
use skeeks\cms\job\models\CmsJobRunArtifact;
use skeeks\cms\job\runtime\JobContext;

/**
 * Мост для существующих консольных команд.
 *
 * Даёт историю, раздельные stdout и stderr, код возврата, отмену и общий
 * интерфейс сразу всем консольным командам, не требуя правок в них. Тонкого
 * прогресса здесь быть не может: команда о нём ничего не знает. Прогресс
 * появляется только у переписанных на JobReporterInterface обработчиков.
 *
 * Отличия от прежнего запуска агентов:
 *
 *  - команда собирается массивом и запускается без оболочки, поэтому
 *    метасимволы в аргументах не интерпретируются; прежняя схема склеивала
 *    строку из базы и отдавала её в system();
 *  - маршрут проверяется по списку разрешённых, а не берётся из свободного
 *    поля;
 *  - процесс получает SIGTERM при отмене и по таймауту;
 *  - аренда продлевается во время работы, поэтому долгая команда не считается
 *    зависшей.
 */
class ConsoleCommandJobHandler extends AbstractJobHandler
{
    /**
     * @var string Путь к интерпретатору.
     */
    public $phpBinary;

    /**
     * @var string Путь к консольному скрипту приложения.
     */
    public $scriptPath;

    /**
     * @var string[] Разрешённые маршруты. Поддерживается хвостовая звёздочка:
     *               `shop/*`. Пустой список означает, что разрешено всё
     *               синтаксически корректное, — так работать можно только
     *               там, где ставить задания способен лишь администратор.
     */
    public $allowedCommands = [];

    /**
     * @var int Сколько секунд ждать после SIGTERM, прежде чем послать SIGKILL.
     */
    public $terminateGrace = 10;

    /**
     * @var int Сколько последних строк stderr класть в текст ошибки.
     */
    public $errorTailLines = 20;

    private $_logLimit;
    private $_logBytes = 0;
    private $_logTruncated = false;

    /**
     * @inheritdoc
     */
    public function run(JobContext $context, JobReporterInterface $reporter): void
    {
        $route = (string)$context->get('command');
        $args = (array)$context->get('args', []);

        $this->assertRouteAllowed($route);

        $command = $this->buildCommand($route, $args);
        $env = $this->buildEnv($context);

        $reporter->setStage('run', $route);
        $reporter->info('Запуск команды: '.$route.($args ? ' '.implode(' ', $args) : ''));

        $logPath = $this->createLogPath($context);
        $this->_logLimit = max(1024, (int)\Yii::$app->jobLogs->maxBytes);
        $this->_logBytes = 0;
        $this->_logTruncated = false;
        $log = fopen($logPath, 'w');

        if ($log === false) {
            throw new JobPermanentException('Не удалось открыть файл журнала команды.');
        }

        try {
            // Register before execution: even a hard-killed child leaves a discoverable log.
            $artifact = $reporter->addArtifact(CmsJobRunArtifact::TYPE_LOG, $logPath, [
                'name' => 'console-'.$context->getRun()->id.'.log', 'mime_type' => 'text/plain',
            ]);
            $result = $this->execute($command, $env, $log, $context, $reporter);
        } finally {
            fclose($log);
            if (isset($artifact)) {
                clearstatcache(true, $logPath);
                $artifact->updateAttributes(['size' => filesize($logPath)]);
            }
        }

        $this->interpretResult($result, $reporter);
    }

    /**
     * Запуск процесса с продлением аренды и реакцией на отмену.
     *
     * @return array
     */
    protected function execute(array $command, array $env, $log, JobContext $context, JobReporterInterface $reporter)
    {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $cwd = dirname($this->resolveScriptPath());

        // Команда передаётся массивом: оболочка не участвует, поэтому
        // подставить в аргумент `; rm -rf` невозможно.
        $process = @proc_open($command, $descriptors, $pipes, $cwd, $env);

        if (!is_resource($process)) {
            throw new JobPermanentException('Не удалось запустить процесс команды.');
        }

        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $startedAt = time();
        $timeout = (int)$context->getDefinition()->timeout;
        $stderrTail = [];
        $exitCode = null;
        $terminatedAt = null;
        $cancelled = false;
        $timedOut = false;

        while (true) {
            $status = proc_get_status($process);

            $this->drain($pipes[1], $log);
            $this->drain($pipes[2], $log, $stderrTail);

            if (!$status['running']) {
                $exitCode = $status['exitcode'];
                break;
            }

            if ($terminatedAt === null) {
                if ($reporter->isCancelled()) {
                    $cancelled = true;
                    $reporter->info('Получен запрос отмены, посылаем SIGTERM.');
                    proc_terminate($process, defined('SIGTERM') ? SIGTERM : 15);
                    $terminatedAt = time();
                } elseif ($timeout > 0 && (time() - $startedAt) > $timeout) {
                    $timedOut = true;
                    $reporter->warning("Превышен таймаут {$timeout} с, посылаем SIGTERM.");
                    proc_terminate($process, defined('SIGTERM') ? SIGTERM : 15);
                    $terminatedAt = time();
                }
            } elseif ((time() - $terminatedAt) > $this->terminateGrace) {
                $reporter->warning('Процесс не завершился по SIGTERM, посылаем SIGKILL.');
                proc_terminate($process, defined('SIGKILL') ? SIGKILL : 9);
                $terminatedAt = time() + 3600;
            }

            // Продление аренды: долгая команда не должна выглядеть зависшей.
            $reporter->heartbeat();

            usleep(200000);
        }

        $this->drain($pipes[1], $log);
        $this->drain($pipes[2], $log, $stderrTail);

        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        return [
            'exitCode' => $exitCode,
            'stderrTail' => $stderrTail,
            'cancelled' => $cancelled,
            'timedOut' => $timedOut,
        ];
    }

    /**
     * Итог по коду возврата и содержимому stderr.
     */
    protected function interpretResult(array $result, JobReporterInterface $reporter)
    {
        if ($result['cancelled']) {
            throw new JobCancelledException('Команда остановлена по запросу отмены.');
        }

        $exitCode = $result['exitCode'];
        $stderrTail = $result['stderrTail'];

        if ($result['timedOut']) {
            throw new JobPermanentException('Команда прервана по таймауту.');
        }

        if ($exitCode !== 0) {
            $message = 'Команда завершилась с кодом '.var_export($exitCode, true);

            if ($stderrTail) {
                $message .= ': '.trim(implode('', $stderrTail));
            }

            throw new JobPermanentException($message);
        }

        $reporter->countSuccess();
        $reporter->setResult(['exit_code' => 0]);

        // Нулевой код возврата ещё не означает, что всё получилось: старые
        // команды печатают ошибки отдельных элементов в stderr и завершаются
        // успешно. Пометим такой прогон замечанием, чтобы он не выглядел
        // безупречным.
        if ($stderrTail) {
            $reporter->warning('Команда завершилась успешно, но писала в stderr; подробности в журнале.');
        }
    }

    /**
     * Прочитать доступное из потока в журнал.
     */
    protected function drain($pipe, $log, array &$tail = null)
    {
        while (($chunk = fread($pipe, 8192)) !== false && $chunk !== '') {
            // Keep draining pipes after reaching the cap so the child cannot deadlock.
            $marker = "\n[Лог сокращён: достигнут лимит размера.]\n";
            $room = max(0, $this->_logLimit - strlen($marker) - $this->_logBytes);
            if ($room > 0) {
                $written = fwrite($log, substr($chunk, 0, $room));
                $this->_logBytes += (int)$written;
            }
            if (strlen($chunk) > $room && !$this->_logTruncated) {
                fwrite($log, $marker);
                $this->_logTruncated = true;
            }

            if ($tail !== null) {
                $tail[] = $chunk;

                if (count($tail) > $this->errorTailLines) {
                    array_shift($tail);
                }
            }
        }
    }

    /**
     * @throws JobPermanentException
     */
    protected function assertRouteAllowed($route)
    {
        if (!preg_match('~^[a-z0-9][a-z0-9\-]*(/[a-z0-9][a-z0-9\-]*){0,2}$~i', $route)) {
            throw new JobPermanentException("Недопустимый маршрут команды: '{$route}'.");
        }

        if (!$this->allowedCommands) {
            return;
        }

        foreach ($this->allowedCommands as $pattern) {
            if ($pattern === $route) {
                return;
            }

            if (substr($pattern, -1) === '*' && strpos($route, rtrim($pattern, '*')) === 0) {
                return;
            }
        }

        throw new JobPermanentException("Команда '{$route}' не входит в список разрешённых.");
    }

    /**
     * @return array
     */
    protected function buildCommand($route, array $args)
    {
        $command = [
            $this->phpBinary ? $this->phpBinary : PHP_BINARY,
            $this->resolveScriptPath(),
            $route,
        ];

        foreach ($args as $arg) {
            if (is_array($arg) || is_object($arg)) {
                throw new JobPermanentException('Аргументы команды должны быть скалярными.');
            }

            $arg = (string)$arg;

            if (!preg_match('~^-{0,2}[a-zA-Z0-9][a-zA-Z0-9\-_]*(=.*)?$~s', $arg)) {
                throw new JobPermanentException("Недопустимый аргумент команды: '{$arg}'.");
            }

            $command[] = $arg;
        }

        return $command;
    }

    /**
     * @return array
     */
    protected function buildEnv(JobContext $context)
    {
        $env = getenv();

        // Прежний запуск агентов задавал сайт префиксом CMS_SITE=... в строке
        // оболочки; здесь то же самое передаётся окружением процесса.
        if ($context->getRun()->cms_site_id) {
            $env['CMS_SITE'] = (string)$context->getRun()->cms_site_id;
        }

        return $env;
    }

    /**
     * @return string
     */
    protected function resolveScriptPath()
    {
        if ($this->scriptPath) {
            return $this->scriptPath;
        }

        if (defined('ROOT_DIR')) {
            return ROOT_DIR.'/yii';
        }

        return \Yii::getAlias('@root/yii');
    }

    /**
     * @return string
     */
    protected function createLogPath(JobContext $context)
    {
        return \Yii::$app->jobLogs->create($context->getRun());
    }
}
