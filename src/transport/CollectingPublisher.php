<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 * @author Semenov Alexander <semenov@skeeks.com>
 */

namespace skeeks\cms\job\transport;

use skeeks\cms\job\contracts\JobPublisherInterface;
use yii\base\Component;

/**
 * Публикатор, складывающий сообщения в память.
 *
 * Нужен, чтобы проверять доменный слой отдельно от транспорта: правила
 * пересечения, дедупликацию, повторы, отмену и подсчёт итогов можно и нужно
 * проверять, не поднимая очередь.
 *
 * Это не замена транспорта и не средство обойти его отсутствие. Сообщения
 * никуда не доставляются, а после завершения процесса теряются, поэтому в
 * приложении, которое реально выполняет задания, такой публикатор
 * использовать нельзя.
 */
class CollectingPublisher extends Component implements JobPublisherInterface
{
    /**
     * @var array Опубликованные сообщения в порядке публикации.
     */
    private $_messages = [];

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
        $this->_messages[] = [
            'runId' => $message->runId,
            'version' => $message->version,
            'queue' => $queue,
            'delay' => $delay,
            'priority' => $priority,
            'ttr' => $ttr,
            'availableAt' => time() + $delay,
        ];

        return (string)count($this->_messages);
    }

    /**
     * @inheritdoc
     */
    public function supports(string $queue): bool
    {
        return true;
    }

    /**
     * Все накопленные сообщения.
     */
    public function all(): array
    {
        return $this->_messages;
    }

    /**
     * Забрать готовые к доставке сообщения указанных полос.
     *
     * @param string[] $queues пустой список — любые полосы
     * @param bool     $respectDelay учитывать отложенную доставку
     * @return int[] номера запусков
     */
    public function drain(array $queues = [], bool $respectDelay = true): array
    {
        $now = time();
        $ready = [];
        $kept = [];

        foreach ($this->_messages as $message) {
            $laneMatches = !$queues || in_array($message['queue'], $queues, true);
            $timeMatches = !$respectDelay || $message['availableAt'] <= $now;

            if ($laneMatches && $timeMatches) {
                $ready[] = (int)$message['runId'];
            } else {
                $kept[] = $message;
            }
        }

        $this->_messages = $kept;

        return $ready;
    }

    public function clear(): void
    {
        $this->_messages = [];
    }
}
