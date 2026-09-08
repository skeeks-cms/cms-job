<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 * @author Semenov Alexander <semenov@skeeks.com>
 */

namespace skeeks\cms\job\contracts;

use skeeks\cms\job\transport\WorkerOptions;

/**
 * Потребление сообщений одной полосы.
 *
 * Весь жизненный цикл воркера конкретной библиотеки — цикл опроса,
 * резервирование, подтверждение, реакция на сигналы — спрятан за этим
 * интерфейсом. Публичная команда `cms-job/worker` является фасадом над ним и
 * не должна знать, чем именно доставлены сообщения.
 */
interface JobConsumerInterface
{
    /**
     * Обрабатывать сообщения, пока не выполнено условие остановки.
     *
     * @return int код возврата процесса
     */
    public function consume(WorkerOptions $options): int;

    /**
     * Сколько сообщений ждёт доставки. -1 — транспорт не умеет отвечать.
     */
    public function size(string $queue): int;
}
