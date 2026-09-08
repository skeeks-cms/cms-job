<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 * @author Semenov Alexander <semenov@skeeks.com>
 */

namespace skeeks\cms\job\transport\yii2queue;

use skeeks\cms\job\transport\JobTransportMessage;
use yii\base\BaseObject;
use yii\queue\RetryableJobInterface;

/**
 * Единственный класс задания, известный yii2-queue.
 *
 * Доменные обработчики SkeekS не реализуют `yii\queue\JobInterface` и вообще
 * не знают о существовании библиотеки: их контракт —
 * `JobHandlerInterface::run(JobContext, JobReporterInterface)`. Через очередь
 * ходит только этот конверт с номером запуска.
 *
 * Свойства публичные и скалярные: сериализатор JSON кладёт их как есть, без
 * сериализации объектов, ActiveRecord и замыканий.
 */
class CmsJobEnvelope extends BaseObject implements RetryableJobInterface
{
    /**
     * @var int
     */
    public $runId;

    /**
     * @var int Версия формата конверта.
     */
    public $v = JobTransportMessage::VERSION;

    public function getTtr()
    {
        // Publisher supplies the per-type TTR; this is only the library fallback.
        return 300;
    }

    public function canRetry($attempt, $error)
    {
        // A delivery attempt is not a business attempt. The runner owns the
        // latter and checks status, ownership, idempotency and max_attempts.
        return true;
    }

    /**
     * Выполнить запуск.
     *
     * Сюда не попадает никакой прикладной логики: конверт лишь переводит
     * управление в доменный сервис. Благодаря этому замена транспорта не
     * затрагивает выполнение заданий.
     *
     * @param \yii\queue\Queue $queue
     */
    public function execute($queue)
    {
        $message = new JobTransportMessage((int)$this->runId, (int)$this->v);

        if (!$message->isSupported()) {
            // Формат конверта старше или новее текущего кода. Выполнять такое
            // сообщение нельзя: неизвестно, что оно означает.
            //
            // Но и просто отбросить его нельзя. Сообщение подтверждается, а
            // строка запуска остаётся ожидающей навсегда: разбудить её больше
            // нечем, в интерфейсе она выглядит как «в очереди», и никто не
            // узнает, что операция никогда не выполнится. Поэтому фиксируем
            // наблюдаемый отказ.
            \Yii::error(
                "Конверт задания версии {$this->v} не поддерживается (текущая — "
                .JobTransportMessage::VERSION.'). Перед несовместимым обновлением'
                .' транспортные очереди нужно осушить.',
                'skeeks/job'
            );

            \Yii::$app->jobRunner->failUnsupportedEnvelope((int)$this->runId, (int)$this->v);

            return;
        }

        \Yii::$app->jobRunner->execute($message->runId);
    }
}
