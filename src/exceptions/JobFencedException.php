<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 * @author Semenov Alexander <semenov@skeeks.com>
 */

namespace skeeks\cms\job\exceptions;

/**
 * Запуск перехвачен другим воркером.
 *
 * Это не отказ задания и не повод для повтора: работу уже ведёт кто-то
 * другой. Текущий процесс обязан остановиться и не трогать ни строку запуска,
 * ни блокировку ресурса — их владелец сменился.
 */
class JobFencedException extends JobException
{
}
