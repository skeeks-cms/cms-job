<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 * @author Semenov Alexander <semenov@skeeks.com>
 */

namespace skeeks\cms\job\contracts;

use skeeks\cms\job\models\CmsJobRun;

/**
 * Транспорт очереди.
 *
 * Единственная реализация — {@see \skeeks\cms\job\transport\DbTransport} на той
 * же базе, что и приложение: постановка задания входит в транзакцию бизнес-
 * события, поэтому outbox не нужен. Интерфейс существует, чтобы позже можно
 * было подставить брокер, не трогая обработчики; тогда outbox понадобится.
 */
interface JobTransportInterface
{
    /**
     * Сохранить задание в очередь. Вызывается внутри транзакции вызывающего.
     */
    public function push(CmsJobRun $run): void;

    /**
     * Захватить до $limit заданий из указанных полос.
     *
     * @param string[] $queues
     * @return CmsJobRun[]
     */
    public function claim(array $queues, int $limit, string $workerId): array;

    public function extendLease(CmsJobRun $run, int $seconds): bool;

    /**
     * Вернуть задание в очередь, не считая попытку неудачной.
     */
    public function release(CmsJobRun $run, int $delay = 0): void;

    /**
     * Разобрать задания с истёкшей арендой.
     *
     * Идемпотентные типы возвращаются в очередь, остальные помечаются
     * `timed_out`: воркер мог упасть уже после внешнего вызова.
     *
     * @return int сколько записей обработано
     */
    public function reapExpired(): int;
}
