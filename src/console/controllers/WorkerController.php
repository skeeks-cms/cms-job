<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 * @author Semenov Alexander <semenov@skeeks.com>
 */

namespace skeeks\cms\job\console\controllers;

use skeeks\cms\job\contracts\JobTransportInterface;
use skeeks\cms\job\models\CmsJobRun;
use skeeks\cms\job\runtime\JobRunner;
use skeeks\cms\job\runtime\LockManager;
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
     * @var string Полосы через запятую. Пусто — все зарегистрированные.
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
     * @var int Пауза, когда очередь пуста, сек.
     */
    public $idleDelay = 3;

    /**
     * @var int Сколько заданий забирать за один проход.
     */
    public $batch = 1;

    /**
     * @var bool Выполнить доступное и выйти, не ожидая новых заданий.
     */
    public $once = false;

    /**
     * @var bool
     */
    private $_shouldStop = false;

    /**
     * @inheritdoc
     */
    public function options($actionID)
    {
        return array_merge(parent::options($actionID), [
            'queues',
            'maxJobs',
            'maxSeconds',
            'idleDelay',
            'batch',
            'once',
        ]);
    }

    /**
     * Разбирать очередь.
     */
    public function actionIndex()
    {
        $this->registerSignals();

        /** @var JobTransportInterface $transport */
        $transport = \Yii::$app->get('jobTransport');
        /** @var JobRunner $runner */
        $runner = \Yii::$app->get('jobRunner');

        $queues = $this->resolveQueues();
        $workerId = $this->workerId();
        $startedAt = time();
        $done = 0;

        $this->stdout("Воркер {$workerId}, очереди: ".implode(', ', $queues)."\n", Console::BOLD);

        while (!$this->_shouldStop) {
            $transport->reapExpired();

            $runs = $transport->claim($queues, $this->batch, $workerId);

            if (!$runs) {
                if ($this->once) {
                    break;
                }

                $this->pause();
                if ($this->isLimitReached($startedAt, $done)) {
                    break;
                }

                continue;
            }

            foreach ($runs as $run) {
                $this->stdout(" > #{$run->id} {$run->job_type}\n");

                try {
                    $runner->run($run);
                } catch (\Throwable $e) {
                    // Сюда попадают только сбои самого ядра: отказы обработчика
                    // JobRunner разбирает сам.
                    \Yii::error(
                        "Сбой воркера на задании #{$run->id}: ".$e->getMessage()."\n".$e->getTraceAsString(),
                        'skeeks/job'
                    );
                    $this->stderr("   ошибка воркера: ".$e->getMessage()."\n", Console::FG_RED);
                }

                $done++;

                $this->stdout("   {$run->status}\n");

                if ($this->_shouldStop || $this->isLimitReached($startedAt, $done)) {
                    break 2;
                }
            }
        }

        $this->stdout("Остановлен. Выполнено заданий: {$done}\n", Console::BOLD);

        return ExitCode::OK;
    }

    /**
     * Вернуть в очередь задания с истёкшей арендой и снять брошенные блокировки.
     */
    public function actionReap()
    {
        /** @var JobTransportInterface $transport */
        $transport = \Yii::$app->get('jobTransport');
        /** @var LockManager $locks */
        $locks = \Yii::$app->get('jobLockManager');

        $runs = $transport->reapExpired();
        $released = $locks->reapExpired();

        $this->stdout("Заданий обработано: {$runs}, блокировок снято: {$released}\n");

        return ExitCode::OK;
    }

    /**
     * Удалить прогоны, у которых истёк срок хранения.
     */
    public function actionCleanup()
    {
        $deleted = CmsJobRun::deleteAll([
            'and',
            ['status' => CmsJobRun::finishedStatuses()],
            ['not', ['retention_until' => null]],
            ['<', 'retention_until', time()],
        ]);

        $this->stdout("Удалено прогонов: {$deleted}\n");

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
     * @return string[]
     */
    protected function resolveQueues()
    {
        if ($this->queues) {
            return array_values(array_filter(array_map('trim', explode(',', $this->queues))));
        }

        $queues = \Yii::$app->jobs->getRegistry()->queues();

        return $queues ? $queues : [CmsJobRun::QUEUE_DEFAULT];
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

    /**
     * @return bool
     */
    protected function isLimitReached($startedAt, $done)
    {
        if ($this->maxJobs > 0 && $done >= $this->maxJobs) {
            return true;
        }

        if ($this->maxSeconds > 0 && (time() - $startedAt) >= $this->maxSeconds) {
            return true;
        }

        return false;
    }

    /**
     * Пауза с реакцией на сигналы.
     */
    protected function pause()
    {
        for ($i = 0; $i < $this->idleDelay * 10; $i++) {
            if ($this->_shouldStop) {
                return;
            }

            usleep(100000);
            $this->dispatchSignals();
        }
    }

    /**
     * Мягкая остановка: текущее задание дорабатывается, новое не берётся.
     */
    protected function registerSignals()
    {
        if (!extension_loaded('pcntl') || !function_exists('pcntl_signal')) {
            return;
        }

        pcntl_async_signals(true);

        foreach ([SIGTERM, SIGINT, SIGHUP] as $signal) {
            pcntl_signal($signal, function () {
                $this->_shouldStop = true;
                $this->stdout("\nПолучен сигнал остановки, завершаем текущее задание...\n", Console::FG_YELLOW);
            });
        }
    }

    protected function dispatchSignals()
    {
        if (extension_loaded('pcntl') && function_exists('pcntl_signal_dispatch')) {
            pcntl_signal_dispatch();
        }
    }
}
