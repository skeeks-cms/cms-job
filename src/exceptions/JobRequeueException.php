<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 * @author Semenov Alexander <semenov@skeeks.com>
 */

namespace skeeks\cms\job\exceptions;

/**
 * Обработчик сохранил курсор и просит продолжить в новом процессе.
 *
 * Это не отказ: попытка не расходуется, задание возвращается в очередь.
 * Так курсорные задания переживают исчерпание памяти или выход из окна
 * выполнения.
 */
class JobRequeueException extends JobException
{
    /**
     * @var int Задержка перед продолжением, сек.
     */
    public $delay = 0;
}
