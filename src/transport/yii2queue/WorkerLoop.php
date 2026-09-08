<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 * @author Semenov Alexander <semenov@skeeks.com>
 */

namespace skeeks\cms\job\transport\yii2queue;

use skeeks\cms\job\transport\WorkerOptions;
use yii\queue\cli\SignalLoop;

/**
 * Условие продолжения работы воркера.
 *
 * Наследует обработку сигналов у библиотеки — SIGTERM, SIGINT и SIGHUP
 * останавливают цикл между сообщениями, поэтому текущая операция
 * доводится до конца, а новая не берётся. Сверх этого добавлены плановые
 * пределы: перезапуск по расписанию дешевле, чем расследование утечки памяти
 * в процессе, живущем неделями.
 *
 * Пределы проверяются между сообщениями. Прервать выполняющуюся операцию они
 * не могут и не должны: за это отвечает отмена и таймаут задания.
 */
class WorkerLoop extends SignalLoop
{
    /**
     * @var WorkerOptions
     */
    public $options;

    /**
     * @var int
     */
    public $processed = 0;

    /**
     * @var int
     */
    private $_startedAt;

    /**
     * @var string|null Причина плановой остановки.
     */
    private $_stopReason;

    public function init()
    {
        parent::init();

        $this->_startedAt = time();
    }

    /**
     * @return string|null
     */
    public function getStopReason()
    {
        return $this->_stopReason;
    }

    /**
     * @inheritdoc
     */
    public function canContinue()
    {
        if (!parent::canContinue()) {
            $this->_stopReason = 'получен сигнал остановки';

            return false;
        }

        $options = $this->options;

        if (!$options) {
            return true;
        }

        if ($options->maxJobs > 0 && $this->processed >= $options->maxJobs) {
            $this->_stopReason = "обработано {$this->processed} заданий, достигнут предел";

            return false;
        }

        if ($options->memoryLimit > 0) {
            $usedMib = (int)round(memory_get_usage(true) / 1048576);

            if ($usedMib >= $options->memoryLimit) {
                $this->_stopReason = "занято {$usedMib} МиБ, достигнут предел памяти";

                return false;
            }
        }

        if ($options->maxRuntime > 0 && (time() - $this->_startedAt) >= $options->maxRuntime) {
            $this->_stopReason = 'достигнут предел времени работы';

            return false;
        }

        return true;
    }
}
