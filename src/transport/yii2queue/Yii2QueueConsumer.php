<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 * @author Semenov Alexander <semenov@skeeks.com>
 */

namespace skeeks\cms\job\transport\yii2queue;

use skeeks\cms\job\contracts\JobConsumerInterface;
use skeeks\cms\job\models\CmsJobRun;
use skeeks\cms\job\runtime\CmsJobRunner;
use skeeks\cms\job\transport\WorkerOptions;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Exception\ProcessSignaledException;
use Symfony\Component\Process\Process;
use yii\base\Component;
use yii\di\Instance;
use yii\queue\cli\Queue as CliQueue;
use yii\queue\ExecEvent;
use yii\queue\Queue as BaseQueue;

/**
 * Потребление сообщений через yii2-queue.
 *
 * Это единственное место во всём пакете, где вызывается `cli\Queue::run()`.
 * Метод относится к внутреннему устройству библиотеки: он сам держит цикл
 * опроса, резервирует сообщение, зовёт обработчик и подтверждает доставку.
 * Ни команда `cms-job/worker`, ни доменный слой не должны его видеть — иначе
 * замена транспорта потянет за собой правки в публичной части пакета.
 *
 * Собственного резервирования поверх библиотеки здесь нет намеренно: два
 * механизма резервирования на одну очередь неизбежно разойдутся.
 */
class Yii2QueueConsumer extends Component implements JobConsumerInterface
{
    /**
     * Коды возврата дочернего процесса, совпадают с yii\queue\cli\Command.
     */
    const EXEC_DONE = 0;
    const EXEC_RETRY = 3;

    /**
     * @var QueueFactory|string|array
     */
    public $factory = 'jobQueueFactory';

    /**
     * @var int На сколько секунд отодвинуть повторную доставку запуска,
     *          дочерний процесс которого упал до захвата.
     *
     * Без задержки воспроизводимая ошибка загрузки давала бы плотный цикл:
     * сообщение возвращается в очередь и тут же доставляется снова.
     */
    public $crashRedeliveryDelay = 30;

    public function init()
    {
        parent::init();

        $this->factory = Instance::ensure($this->factory, QueueFactory::class);
    }

    /**
     * @inheritdoc
     */
    public function consume(WorkerOptions $options): int
    {
        $queue = $this->factory->get($options->queue);

        if (!$queue instanceof CliQueue) {
            throw new \RuntimeException(
                "Драйвер очереди '{$options->queue}' не поддерживает консольного воркера."
            );
        }

        $loop = new WorkerLoop($queue, ['options' => $options]);
        $previousLoop = $queue->loopConfig;

        // Подменяем цикл библиотеки своим: сигналы обрабатывает он же
        // (унаследовано), плюс плановые пределы по числу заданий, памяти и
        // времени работы.
        $queue->loopConfig = function () use ($loop) {
            return $loop;
        };

        // Счётчик обработанных ведём по событию библиотеки, а не внутри
        // доменного кода: домен не должен знать о существовании транспорта.
        $count = function (ExecEvent $event) use ($loop) {
            $loop->processed++;
        };
        $previousHandler = $queue->messageHandler;
        try {
            if ($options->isolate) {
                $this->enableIsolation($queue, $options);
                $isolatedHandler = $queue->messageHandler;
                $queue->messageHandler = function (...$args) use ($isolatedHandler, $loop) {
                    try {
                        return $isolatedHandler(...$args);
                    } finally {
                        $loop->processed++;
                    }
                };
            } else {
                $queue->messageHandler = null;
                $queue->on(BaseQueue::EVENT_AFTER_EXEC, $count);
                $queue->on(BaseQueue::EVENT_AFTER_ERROR, $count);
            }
            $exitCode = $queue->run(!$options->once, $options->timeout);
        } finally {
            $queue->messageHandler = $previousHandler;
            $queue->loopConfig = $previousLoop;
            $queue->off(BaseQueue::EVENT_AFTER_EXEC, $count);
            $queue->off(BaseQueue::EVENT_AFTER_ERROR, $count);
        }

        if ($options->verbose && $loop->getStopReason()) {
            \Yii::info(
                "Воркер очереди '{$options->queue}' остановлен: ".$loop->getStopReason(),
                'skeeks/job'
            );
        }

        return $exitCode === null ? 0 : (int)$exitCode;
    }

    /**
     * Выполнять каждое задание в дочернем процессе.
     *
     * `cli\Queue::run()`, вызванный напрямую, изоляцию не включает: она живёт
     * не в очереди, а в `cli\Command`, который подменяет `messageHandler`.
     * Раз собственная команда пакета не наследует `cli\Command`, изоляцию надо
     * включить самим — иначе фатальная ошибка в обработчике уносит воркер
     * целиком, а утечка памяти копится между заданиями.
     *
     * Дочерний процесс заодно даёт единственный надёжный способ соблюсти TTR:
     * жёсткий предел по времени, не зависящий от того, проверяет ли обработчик
     * отмену.
     */
    protected function enableIsolation(CliQueue $queue, WorkerOptions $options)
    {
        $script = $this->resolveScriptPath();

        $queue->messageHandler = function ($id, $message, $ttr, $attempt) use ($queue, $script, $options) {
            $executionToken = bin2hex(random_bytes(16));
            $command = [
                PHP_BINARY,
                $script,
                'cms-job/worker/exec',
                (string)$id,
                (string)$ttr,
                (string)$attempt,
                (string)($queue->getWorkerPid() ?: 0),
                $executionToken,
                '--queue='.$options->queue,
            ];

            $process = new Process($command, dirname($script), null, $message, $ttr);

            $output = function ($type, $buffer) use ($options) {
                if (!$options->verbose) {
                    return;
                }

                if ($type === Process::ERR) {
                    fwrite(STDERR, $buffer);
                } else {
                    fwrite(STDOUT, $buffer);
                }
            };

            // Token identifies this attempt across hosts/PID namespaces.
            $process->start($output);
            $childPid = $process->getPid();

            try {
                $exitCode = $process->wait($output);
            } catch (ProcessTimedOutException $e) {
                return $this->handleHardTimeout($queue, $message, (int)$ttr, $executionToken, $id, $attempt);
            } catch (ProcessSignaledException $e) {
                return $this->handleChildCrash($queue, $message, 128 + $process->getTermSignal(), $childPid,
                    $process->getErrorOutput(), $id, $attempt);
            }

            // Прочие коды возврата означают, что дочерний процесс умер, не
            // дойдя до штатного завершения: сегфолт, OOM-killer, fatal error.
            if (!in_array($exitCode, [self::EXEC_DONE, self::EXEC_RETRY], true)) {
                return $this->handleChildCrash($queue, $message, $exitCode, $childPid, $process->getErrorOutput(), $id, $attempt);
            }

            return $exitCode === self::EXEC_DONE;
        };
    }

    /**
     * Дочерний процесс не уложился в TTR и был убит.
     */
    protected function handleHardTimeout(CliQueue $queue, $message, int $ttr, string $executionToken, $id, $attempt): bool
    {
        list($job) = $queue->unserializeMessage($message);

        if ($job instanceof CmsJobEnvelope && $job->runId) {
            $outcome = \Yii::$app->jobRunner->failHardTimeout((int)$job->runId, $ttr, $executionToken);
            if ($outcome === CmsJobRunner::OUTCOME_NOT_STARTED) {
                \Yii::error("Запуск #{$job->runId}: дочерний процесс превысил TTR до захвата; доставка будет повторена.", 'skeeks/job');
                $this->deferRedelivery($queue, $id, $attempt);
                return false;
            }
        } else {
            \Yii::error('Задание превысило TTR, но номер запуска определить не удалось.', 'skeeks/job');
        }

        // Дальнейшую судьбу запуска решил домен: он либо завершил его, либо
        // вернул в очередь новым сообщением. Прежнее сообщение подтверждаем,
        // иначе транспорт доставит его повторно и создаст дубль.
        return true;
    }

    /**
     * Дочерний процесс умер, не дойдя до штатного завершения.
     *
     * Подтверждать сообщение вслепую нельзя. Если процесс упал на загрузке
     * приложения, до захвата запуска, то строка осталась `queued`, аренды у
     * неё нет, и уборка по истёкшей аренде её не увидит: восстанавливать
     * нечего. Сообщение при этом было бы удалено, и операция исчезла бы
     * молча — навсегда ожидающей и никем не разбуженной.
     *
     * Поэтому решение принимается по фактическому состоянию запуска.
     */
    protected function handleChildCrash(CliQueue $queue, $message, $exitCode, $childPid, $stderr, $id, $attempt): bool
    {
        \Yii::error(
            "Дочерний процесс задания (pid {$childPid}) завершился с кодом "
            .var_export($exitCode, true).': '.$stderr,
            'skeeks/job'
        );

        list($job) = $queue->unserializeMessage($message);

        if (!$job instanceof CmsJobEnvelope || !$job->runId) {
            // Разобрать сообщение не удалось — повторная доставка его не
            // исправит, подтверждаем, чтобы не зациклиться.
            return true;
        }

        $run = CmsJobRun::findOne(['id' => (int)$job->runId]);

        if (!$run || $run->getIsFinished()) {
            return true;
        }

        if ($run->status === CmsJobRun::STATUS_QUEUED) {
            // Запуск ожидает: ребёнок мог упасть до захвата или после requeue.
            // Сообщение не подтверждаем — транспорт доставит его снова. Чтобы
            // не получить плотный цикл на воспроизводимой ошибке, отодвигаем
            // повторную доставку.
            $this->deferRedelivery($queue, $id, $attempt);

            return false;
        }

        // Запуск захвачен и остался работающим: аренда истечёт, и его штатно
        // разберёт уборка, которая умеет отличать идемпотентный тип от
        // неидемпотентного. Наблюдаемость при этом сохраняется — строка видна
        // как выполняющаяся, а не исчезает.
        return true;
    }

    /**
     * Отодвинуть повторную доставку упавшего до захвата запуска.
     */
    protected function deferRedelivery(CliQueue $queue, $id, $attempt)
    {
        if (!$queue instanceof \yii\queue\db\Queue) {
            throw new \RuntimeException('Delayed crash redelivery requires the DB transport.');
        }
        // Release only our reservation, never a newer delivery. Returning false
        // prevents the driver's normal acknowledgement from deleting this row.
        $queue->db->createCommand()->update($queue->tableName, [
            'reserved_at' => null,
            'pushed_at' => time(),
            'delay' => max(1, (int)$this->crashRedeliveryDelay),
        ], ['and', ['id' => $id, 'channel' => $queue->channel, 'attempt' => $attempt],
            ['not', ['reserved_at' => null]], ['done_at' => null]])->execute();
    }

    /**
     * @return string
     */
    protected function resolveScriptPath()
    {
        // Консольная точка входа приложения, а не текущий выполняемый файл.
        //
        // Брать `$_SERVER['SCRIPT_FILENAME']`, как это делает `cli\Command`,
        // здесь нельзя: воркер может быть запущен не из `yii` — например из
        // теста или служебного скрипта, — и тогда дочерний процесс
        // рекурсивно перезапустит этот скрипт вместо выполнения задания.
        if (defined('ROOT_DIR') && is_file(ROOT_DIR.'/yii')) {
            return ROOT_DIR.'/yii';
        }

        $alias = \Yii::getAlias('@root/yii', false);
        if ($alias && is_file($alias)) {
            return $alias;
        }

        // Последняя попытка — текущий файл, и только если это действительно
        // консольная точка входа.
        $script = isset($_SERVER['SCRIPT_FILENAME']) ? $_SERVER['SCRIPT_FILENAME'] : null;
        if ($script && is_file($script) && basename($script) === 'yii') {
            return $script;
        }

        throw new \RuntimeException(
            'Не найдена консольная точка входа приложения для запуска задания в'
            .' дочернем процессе. Ожидался файл `yii` в корне проекта.'
        );
    }

    /**
     * @inheritdoc
     */
    public function size(string $queue): int
    {
        $target = $this->factory->get($queue);

        if (!method_exists($target, 'getStatsProvider') && !method_exists($target, 'count')) {
            return -1;
        }

        try {
            return (int)$target->count();
        } catch (\Throwable $e) {
            return -1;
        }
    }
}
