<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 * @author Semenov Alexander <semenov@skeeks.com>
 */

namespace skeeks\cms\job;

use yii\base\Component;
use yii\base\InvalidConfigException;

/**
 * Реестр типов заданий.
 *
 * Ключевое свойство: запускать можно только зарегистрированный тип. Строка из
 * HTTP-параметра или из редактируемого поля в базе обработчиком не становится.
 * Это же закрывает нынешнее поведение `cms_agent`, где `name` — свободный
 * текст, попадающий в system().
 */
class JobRegistry extends Component
{
    /**
     * @var array Определения типов: ключ — тип, значение — конфигурация
     *            {@see JobTypeDefinition}.
     */
    public $types = [];

    /**
     * @var JobTypeDefinition[]
     */
    private $_definitions = [];

    /**
     * @var bool
     */
    private $_initialized = false;

    /**
     * Зарегистрировать тип во время выполнения.
     *
     * @param array|JobTypeDefinition $definition
     */
    public function add($definition)
    {
        $definition = $this->normalize($definition);
        $this->_definitions[$definition->type] = $definition;

        return $this;
    }

    /**
     * @return JobTypeDefinition[]
     */
    public function all()
    {
        $this->ensureInitialized();

        return $this->_definitions;
    }

    /**
     * @return bool
     */
    public function has($type)
    {
        $this->ensureInitialized();

        return isset($this->_definitions[$type]);
    }

    /**
     * @return JobTypeDefinition
     * @throws InvalidConfigException когда тип не зарегистрирован
     */
    public function get($type)
    {
        $this->ensureInitialized();

        if (!isset($this->_definitions[$type])) {
            throw new InvalidConfigException("Job type '{$type}' is not registered.");
        }

        return $this->_definitions[$type];
    }

    /**
     * Полосы очередей, встречающиеся среди зарегистрированных типов.
     *
     * @return string[]
     */
    public function queues()
    {
        $this->ensureInitialized();

        $queues = [];
        foreach ($this->_definitions as $definition) {
            $queues[$definition->queue] = $definition->queue;
        }

        return array_values($queues);
    }

    protected function ensureInitialized()
    {
        if ($this->_initialized) {
            return;
        }

        $this->_initialized = true;

        foreach ($this->types as $type => $config) {
            if (is_array($config) && !isset($config['type'])) {
                $config['type'] = $type;
            }

            $definition = $this->normalize($config);
            $this->_definitions[$definition->type] = $definition;
        }
    }

    /**
     * @param array|JobTypeDefinition $definition
     * @return JobTypeDefinition
     * @throws InvalidConfigException
     */
    protected function normalize($definition)
    {
        if ($definition instanceof JobTypeDefinition) {
            return $definition;
        }

        if (!is_array($definition)) {
            throw new InvalidConfigException('Job type definition must be an array or JobTypeDefinition.');
        }

        if (!isset($definition['class'])) {
            $definition['class'] = JobTypeDefinition::class;
        }

        $object = \Yii::createObject($definition);

        if (!$object instanceof JobTypeDefinition) {
            throw new InvalidConfigException('Job type definition must resolve to JobTypeDefinition.');
        }

        return $object;
    }
}
