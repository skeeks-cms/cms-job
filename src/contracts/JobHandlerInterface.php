<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 * @author Semenov Alexander <semenov@skeeks.com>
 */

namespace skeeks\cms\job\contracts;

use skeeks\cms\job\runtime\JobContext;

/**
 * Предметный обработчик фонового задания.
 *
 * Успех — нормальный возврат, отказ — исключение. Итоговый статус вычисляет
 * ядро по счётчикам репортера, обработчик его не выставляет: смысл
 * `succeeded_with_warnings` в том, что бизнес-результат и код возврата
 * процесса — разные вещи.
 */
interface JobHandlerInterface
{
    /**
     * @throws \Throwable
     */
    public function run(JobContext $context, JobReporterInterface $reporter): void;
}
