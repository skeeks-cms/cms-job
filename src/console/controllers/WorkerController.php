<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 * @author Semenov Alexander <semenov@skeeks.com>
 */

namespace skeeks\cms\job\console\controllers;

use skeeks\cms\job\contracts\JobConsumerInterface;
use skeeks\cms\job\models\CmsJobRun;
use skeeks\cms\job\runtime\JobRunStore;
use skeeks\cms\job\runtime\LockManager;
use skeeks\cms\job\runtime\CronWorkerLock;
use skeeks\cms\job\transport\WorkerOptions;
use skeeks\cms\job\transport\yii2queue\QueueFactory;
use skeeks\cms\job\transport\yii2queue\Yii2QueueConsumer;
use yii\base\InvalidConfigException;
use yii\console\Controller;
use yii\console\ExitCode;
use yii\helpers\Console;

/**
 * Воркер очереди заданий.
 *
 * Заменяет прежнюю схему, где агенты запускались синхронным shell-вызовом
 * прямо на веб-хите.
 */
class WorkerController extends Controller
{
    /**
     * @var string Имя полосы. Один процесс слушает ровно одну полосу — так
     *             тяжёлый импорт не блокирует отправку уведомлений, и каждую
     *             полосу можно масштабировать отдельно.
     */
    public $queue = '';

    /**
     * @var string Устаревший синоним --queue, оставлен для совместимости.
     *             Список через запятую не поддерживается.
     */
    public $queues = '';

    /**
     * @var int Сколько заданий выполнить и завершиться. 0 — без ограничения.
     *          Периодический перезапуск — дешёвая защита от утечек памяти.
     */
    public $maxJobs = 0;

    /**
     * @var int Сколько секунд работать и завершиться. 0 — без ограничения.
     */
    public $maxSeconds = 0;

    /**
     * @var int Предел памяти процесса, МиБ. 0 — без ограничения.
     */
    public $memoryLimit = 0;

    /**
     * @var int Пауза, когда очередь пуста, сек.
     */
    public $idleDelay = 3;

    /**
     * @var bool Выполнить доступное и выйти, не ожидая новых заданий.
     */
    public $once = false;

    /**
     * @var bool Выполнять каждое задание в дочернем процессе.
     *
     * Выключать только для отладки: без изоляции фатальная ошибка обработчика
     * уносит воркер, а TTR перестаёт быть жёстким.
     */
    public $isolate = true;

    /** @var string Private, persistent local directory shared by cron invocations. */
    public $cronLockPath = '@root/console/runtime/cms-jobs/locks';

    /** @var bool Machine-readable output for worker/queues. */
    public $json = false;

    /** List effective configuration only; never connect to or consume a queue. */
    public function actionQueues()
    {
        $factory = \Yii::$app->get('jobQueueFactory');
        $lanes = [];
        foreach ($factory->names() as $name) {
            $lanes[$name] = ['name' => $name, 'types' => [], 'max_ttr' => null];
        }
        $missing = [];
        foreach (\Yii::$app->get('jobRegistry')->all() as $definition) {
            if (!isset($lanes[$definition->queue])) {
                $missing[] = ['type' => $definition->type, 'queue' => $definition->queue];
                continue;
            }
            $ttr = (int)$definition->timeout + (int)$definition->leaseSeconds;
            $lanes[$definition->queue]['types'][] = [
                'type' => $definition->type,
                'title' => $definition->title,
                'timeout' => (int)$definition->timeout,
                'ttr' => $ttr,
            ];
            $lanes[$definition->queue]['max_ttr'] = max($lanes[$definition->queue]['max_ttr'] ?? 0, $ttr);
        }
        ksort($lanes, SORT_STRING);
        foreach ($lanes as &$lane) {
            usort($lane['types'], static function ($a, $b) { return strcmp($a['type'], $b['type']); });
        }
        unset($lane);
        usort($missing, static function ($a, $b) { return strcmp($a['type'], $b['type']); });
        if ($this->json) {
            $this->stdout(\yii\helpers\Json::encode([
                'schema_version' => 1,
                'queues' => array_values($lanes),
                'unconfigured_types' => $missing,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n");
        } else {
            $this->stdout("Полосы из итоговой конфигурации проекта (не состояние воркеров):\n");
            foreach ($lanes as $lane) {
                $this->stdout($lane['name'].' — типов: '.count($lane['types'])
                    .', максимальный TTR: '.($lane['max_ttr'] === null ? '—' : $lane['max_ttr'].' сек')."\n");
                foreach ($lane['types'] as $type) {
                    $this->stdout('  '.$type['type'].' — '.$type['title'].' (timeout: '.$type['timeout']." сек)\n");
                }
            }
            if (!$lanes) { $this->stdout("Полосы не зарегистрированы.\n"); }
            foreach ($missing as $type) {
                $this->stderr("Тип {$type['type']}: полоса {$type['queue']} не зарегистрирована.\n");
            }
        }
        return $missing ? ExitCode::CONFIG : ExitCode::OK;
    }

    /**
     * Обработать доступные задания из cron, не ожидая новых.
     * Один cron-процесс на полосу и каталог блокировок. Лимиты проверяются
     * между заданиями: текущая операция не обрывается по maxSeconds.
     */
    public function actionCron()
    {
        try {
            $queue = $this->resolveQueue();
        } catch (\Throwable $e) {
            $this->stderr($e->getMessage()."\n", Console::FG_RED);
            return ExitCode::USAGE;
        }
        $previous = [$this->once, $this->maxJobs, $this->maxSeconds, $this->memoryLimit];
        $lock = null;
        try {
            foreach (['maxJobs' => 100, 'maxSeconds' => 50, 'memoryLimit' => 256] as $property => $default) {
                if (filter_var($this->$property, FILTER_VALIDATE_INT) === false || (int)$this->$property < 0) {
                    $this->stderr("--{$property}: ожидается целое неотрицательное число.\n");
                    return ExitCode::USAGE;
                }
                // Zero means use the bounded cron default, never unlimited.
                $this->$property = (int)$this->$property ?: $default;
            }
            $this->once = true;
            $lock = new CronWorkerLock($this->cronLockPath);
            if (!$lock->acquire($queue)) {
                $this->stdout("Cron пропущен: полоса {$queue} уже обслуживается другим cron-процессом.\n");
                return ExitCode::OK;
            }
            return $this->actionIndex();
        } catch (InvalidConfigException $e) {
            $this->stderr($e->getMessage()."\n", Console::FG_RED);
            return ExitCode::CONFIG;
        } finally {
            if ($lock !== null) { $lock->release(); }
            [$this->once, $this->maxJobs, $this->maxSeconds, $this->memoryLimit] = $previous;
        }
    }

    /**
     * @inheritdoc
     */
    public function options($actionID)
    {
        if ($actionID === 'queues') {
            return array_merge(parent::options($actionID), ['json']);
        }
        return array_merge(parent::options($actionID), [
            'queue',
            'queues',
            'maxJobs',
            'maxSeconds',
            'memoryLimit',
            'idleDelay',
            'once',
            'isolate',
        ]);
    }

    /**
     * Разбирать очередь.
     *
     * Команда — фасад над потребителем. Она не знает, чем доставлены
     * сообщения, и не должна знать: имена внутренних команд транспорта
     * публичным контрактом SkeekS не являются.
     */
    public function actionIndex()
    {
        try {
            $queue = $this->resolveQueue();
        } catch (\Throwable $e) {
            $this->stderr($e->getMessage()."\n", Console::FG_RED);

            return ExitCode::USAGE;
        }

        $workerId = $this->workerId();

        $options = new WorkerOptions([
            'queue' => $queue,
            'once' => $this->once,
            'timeout' => max(1, (int)$this->idleDelay),
            'maxJobs' => (int)$this->maxJobs,
            'maxRuntime' => (int)$this->maxSeconds,
            'memoryLimit' => (int)$this->memoryLimit,
            'verbose' => true,
            'workerId' => $workerId,
            'isolate' => (bool)$this->isolate,
        ]);

        $this->stdout("Воркер {$workerId} запущен, полоса: {$queue}\n", Console::BOLD);
        \Yii::info("Воркер {$workerId} запущен, полоса: {$queue}", 'skeeks/job');

        try {
            /** @var JobConsumerInterface $consumer */
            $consumer = \Yii::$app->get('jobConsumer');
            $exitCode = $consumer->consume($options);
        } catch (InvalidConfigException $e) {
            $this->stderr($e->getMessage()."\n", Console::FG_RED);

            return ExitCode::CONFIG;
        }

        $this->stdout("Воркер {$workerId} остановлен, полоса: {$queue}\n", Console::BOLD);
        \Yii::info("Воркер {$workerId} остановлен, полоса: {$queue}", 'skeeks/job');

        return $exitCode;
    }

    /**
     * Выполнить одно задание. Внутреннее действие.
     *
     * Запускается только воркером в режиме изоляции: сообщение приходит на
     * stdin, чтобы не проходить через аргументы командной строки. Вручную
     * вызывать не нужно — публичный контракт пакета это `cms-job/worker`.
     *
     * @internal
     * @param string $id      идентификатор транспортного сообщения
     * @param int    $ttr     предельное время выполнения, сек
     * @param int    $attempt номер попытки доставки
     * @param int    $pid     идентификатор процесса-воркера
     * @return int 0 — обработано, 3 — вернуть в очередь
     */
    public function actionExec($id, $ttr, $attempt, $pid, $executionToken = null)
    {
        $queueName = trim((string)$this->queue) !== '' ? trim((string)$this->queue) : null;

        if ($queueName === null) {
            $this->stderr("Для cms-job/worker/exec обязателен --queue.\n", Console::FG_RED);

            return ExitCode::USAGE;
        }

        /** @var QueueFactory $factory */
        $factory = \Yii::$app->get('jobQueueFactory');
        $queue = $factory->get($queueName);

        $message = file_get_contents('php://stdin');

        if ($executionToken !== null && !preg_match('/^[a-f0-9]{32}$/D', $executionToken)) {
            return ExitCode::USAGE;
        }
        \Yii::$app->jobRunner->executionToken = $executionToken;

        return $queue->execute($id, $message, (int)$ttr, (int)$attempt, $pid ?: null)
            ? Yii2QueueConsumer::EXEC_DONE
            : Yii2QueueConsumer::EXEC_RETRY;
    }

    /**
     * Подсказка вместо списка полос: какие процессы запустить.
     *
     * @return string
     */
    protected function multiQueueHint($list)
    {
        $commands = array_map(
            function ($name) {
                return 'php yii cms-job/worker --queue='.trim($name);
            },
            array_filter(array_map('trim', explode(',', $list)), 'strlen')
        );

        return "Один процесс слушает одну полосу: так тяжёлый импорт не задерживает"
            ." уведомления, и полосы масштабируются независимо.\n"
            ."Запустите отдельный воркер на каждую:\n  ".implode("\n  ", $commands);
    }

    /**
     * Определить полосу из опций.
     *
     * @throws InvalidConfigException
     */
    protected function resolveQueue()
    {
        $value = trim((string)$this->queue);

        // Список через запятую отвергается для обеих опций. Молча взять первое
        // имя означало бы, что остальные полосы никто не разбирает, а заметят
        // это только по накопившейся очереди.
        if (strpos($value, ',') !== false) {
            throw new InvalidConfigException($this->multiQueueHint($value));
        }

        if ($value === '') {
            $legacy = trim((string)$this->queues);

            if ($legacy === '') {
                throw new InvalidConfigException(
                    'Не указана полоса. Пример: php yii cms-job/worker --queue=imports'
                );
            }

            if (strpos($legacy, ',') !== false) {
                throw new InvalidConfigException($this->multiQueueHint($legacy));
            }

            $value = $legacy;
        }

        /** @var QueueFactory $factory */
        $factory = \Yii::$app->get('jobQueueFactory');

        if (!$factory->has($value)) {
            throw new InvalidConfigException(
                "Полоса '{$value}' не настроена. Доступны: ".implode(', ', $factory->names()).'.'
            );
        }

        return $value;
    }

    /**
     * Вернуть в очередь задания с истёкшей арендой и снять брошенные блокировки.
     */
    public function actionReap()
    {
        /** @var JobRunStore $store */
        $store = \Yii::$app->get('jobRunStore');
        /** @var LockManager $locks */
        $locks = \Yii::$app->get('jobLockManager');
        $registry = \Yii::$app->jobs->getRegistry();

        $result = $store->reapExpired(function (CmsJobRun $run) use ($registry) {
            return $registry->has($run->job_type) && $registry->get($run->job_type)->idempotent;
        });

        $released = $locks->reapExpired();

        $this->stdout(
            "Возвращено в очередь: {$result['requeued']}, "
            ."помечено timed_out: {$result['timed_out']}, "
            ."блокировок снято: {$released}\n"
        );

        // Публиковать здесь нечего: `reapExpired()` уже создал по одному
        // сообщению на каждый возвращённый запуск, в той же транзакции, что и
        // смена статуса. Прежняя перепубликация проходила по всем ожидающим
        // строкам и не могла отличить потерявшую сообщение от той, чьё
        // сообщение просто ещё не доставлено, — то есть штатно создавала
        // дубликаты.
        return ExitCode::OK;
    }

    /**
     * Удалить прогоны, у которых истёк срок хранения.
     */
    public function actionCleanup()
    {
        $condition = [
            'and',
            ['status' => CmsJobRun::finishedStatuses()],
            ['not', ['retention_until' => null]],
            ['<', 'retention_until', time()],
        ];
        $deleted = 0;
        foreach (CmsJobRun::find()->where($condition)->orderBy(['id' => SORT_ASC])->limit(500)->all() as $run) {
            foreach ($run->artifacts as $artifact) {
                if ($artifact->log_path) { \Yii::$app->jobLogs->remove($artifact->log_path); }
            }
            $deleted += CmsJobRun::deleteAll(['and', $condition, ['id' => $run->id]]);
        }

        $this->stdout("Удалено прогонов: {$deleted}\n");

        return ExitCode::OK;
    }

    /** Separate short-lived diagnostics from the longer run history. */
    public function actionCleanupLogs()
    {
        $deleted = \Yii::$app->jobLogs->cleanup();
        $orphans = \Yii::$app->jobLogs->cleanupOrphans();
        $this->stdout("Удалено логов: {$deleted}, забытых файлов: {$orphans}\n");
        return ExitCode::OK;
    }

    /**
     * Поставить задание вручную.
     *
     * @param string $type зарегистрированный тип
     * @param string $payload JSON с параметрами
     */
    public function actionPush($type, $payload = '{}')
    {
        $data = json_decode($payload, true);
        if (!is_array($data)) {
            $this->stderr("Некорректный JSON в payload\n", Console::FG_RED);

            return ExitCode::DATAERR;
        }

        $run = \Yii::$app->jobs->push($type, $data, [
            'triggerType' => CmsJobRun::TRIGGER_SYSTEM,
        ]);

        if (!$run) {
            $this->stdout("Постановка отброшена политикой пересечения\n", Console::FG_YELLOW);

            return ExitCode::OK;
        }

        $this->stdout("Поставлено задание #{$run->id}\n", Console::FG_GREEN);

        return ExitCode::OK;
    }

    /**
     * Запросить отмену.
     */
    public function actionCancel($id)
    {
        $run = CmsJobRun::findOne($id);
        if (!$run) {
            $this->stderr("Задание #{$id} не найдено\n", Console::FG_RED);

            return ExitCode::DATAERR;
        }

        $result = \Yii::$app->jobs->cancel($run);
        $this->stdout($result ? "Отмена запрошена\n" : "Отменить нельзя\n");

        return ExitCode::OK;
    }

    /**
     * @return string
     */
    protected function workerId()
    {
        $host = function_exists('gethostname') ? gethostname() : 'unknown';
        $pid = function_exists('getmypid') ? getmypid() : 0;

        return substr($host.':'.$pid, 0, 64);
    }
}
