<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 * @author Semenov Alexander <semenov@skeeks.com>
 */

namespace skeeks\cms\job\transport\yii2queue;

use skeeks\cms\job\contracts\JobPublisherInterface;
use skeeks\cms\job\transport\JobTransportMessage;
use yii\base\Component;
use yii\di\Instance;

/**
 * Публикация через yii2-queue.
 *
 * Атомарность постановки обеспечивается не здесь, а выбором соединения:
 * DB-драйвер пишет сообщение через `$this->db->createCommand()->insert(...)`,
 * поэтому при общем экземпляре `yii\db\Connection` вставка попадает в
 * транзакцию, открытую доменным слоем. Строка запуска и транспортное
 * сообщение фиксируются или откатываются вместе, и transactional outbox не
 * нужен.
 *
 * Это свойство ломается при переходе на брокер вне базы (Redis, RabbitMQ):
 * там публикация не участвует в транзакции БД, и outbox придётся вводить.
 */
class Yii2QueuePublisher extends Component implements JobPublisherInterface
{
    /**
     * @var QueueFactory|string|array
     */
    public $factory = 'jobQueueFactory';

    public function init()
    {
        parent::init();

        $this->factory = Instance::ensure($this->factory, QueueFactory::class);
    }

    /**
     * @inheritdoc
     */
    public function publish(
        JobTransportMessage $message,
        string $queue,
        int $delay = 0,
        int $priority = 0,
        int $ttr = 0
    ): ?string {
        $target = $this->factory->get($queue);

        $envelope = new CmsJobEnvelope([
            'runId' => $message->runId,
            'v' => $message->version,
        ]);

        if ($delay > 0) {
            $target = $target->delay($delay);
        }

        $target = $target->ttr($ttr > 0 ? $ttr : $target->ttr);

        $target = $target->priority($priority);

        $id = $target->push($envelope);

        return $id === null ? null : (string)$id;
    }

    /**
     * @inheritdoc
     */
    public function supports(string $queue): bool
    {
        return $this->factory->has($queue);
    }
}
