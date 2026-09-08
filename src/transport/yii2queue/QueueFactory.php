<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 * @author Semenov Alexander <semenov@skeeks.com>
 */

namespace skeeks\cms\job\transport\yii2queue;

use yii\base\Component;
use yii\base\InvalidConfigException;
use yii\helpers\ArrayHelper;

/**
 * Создание объектов очереди по имени полосы.
 *
 * Пакеты добавляют свои полосы без отдельного компонента приложения
 * на каждую. Здесь одна расширяемая карта настроек:
 * общие значения в `defaults`, отличия — в `queues`.
 *
 * Объекты создаются лениво: приложение, которое ничего не ставит в очередь,
 * не платит за инициализацию транспорта.
 */
class QueueFactory extends Component
{
    /**
     * @var array Общие настройки всех полос.
     */
    public $defaults = [];

    /**
     * @var array Настройки по имени полосы, накладываются поверх defaults.
     */
    public $queues = [];

    /**
     * @var array Созданные объекты очередей.
     */
    private $_instances = [];

    /**
     * @return bool
     */
    public function has(string $queue): bool
    {
        return isset($this->queues[$queue]);
    }

    /**
     * @return string[]
     */
    public function names(): array
    {
        return array_keys($this->queues);
    }

    /**
     * @return \yii\queue\db\Queue|\yii\queue\cli\Queue
     * @throws InvalidConfigException
     */
    public function get(string $queue)
    {
        if (isset($this->_instances[$queue])) {
            return $this->_instances[$queue];
        }

        if (!$this->has($queue)) {
            throw new InvalidConfigException(
                "Очередь '{$queue}' не настроена. Доступны: ".implode(', ', $this->names()).'.'
            );
        }

        $this->assertTransportInstalled();

        $config = ArrayHelper::merge($this->defaults, $this->queues[$queue]);

        // Имя канала по умолчанию совпадает с именем полосы: одна таблица,
        // разделение по колонке channel.
        if (!isset($config['channel'])) {
            $config['channel'] = $queue;
        }

        // Ordinary library jobs do not retry. CmsJobEnvelope explicitly allows
        // infrastructure redelivery through RetryableJobInterface; business
        // attempt limits remain exclusively in CmsJobRunner.
        $config['attempts'] = 1;

        return $this->_instances[$queue] = \Yii::createObject($config);
    }

    /**
     * @throws InvalidConfigException
     */
    public function assertTransportInstalled(): void
    {
        if (class_exists('yii\queue\db\Queue')) {
            return;
        }

        throw new InvalidConfigException(
            'Пакет yiisoft/yii2-queue не установлен. Выполните: '
            .'composer update skeeks/cms-job --with-dependencies'
        );
    }
}
