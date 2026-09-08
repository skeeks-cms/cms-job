<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 * @author Semenov Alexander <semenov@skeeks.com>
 */

namespace skeeks\cms\job\contracts;

use skeeks\cms\job\transport\JobTransportMessage;

/**
 * Публикация технического сообщения в очередь.
 *
 * Контракт принадлежит пакету и намеренно не упоминает типы конкретной
 * библиотеки очередей: смена транспорта не должна затрагивать доменный слой.
 *
 * Реализация обязана выполнять запись в рамках текущей транзакции вызывающего,
 * если транспорт это позволяет. Для DB-драйвера на том же соединении это так,
 * поэтому постановка задания и создание строки запуска либо фиксируются
 * вместе, либо вместе откатываются, и outbox не нужен.
 */
interface JobPublisherInterface
{
    /**
     * @param string $queue  имя полосы из реестра типов
     * @param int    $delay  задержка перед доставкой, сек
     * @param int    $priority меньше — важнее
     * @param int    $ttr    сколько секунд сообщение считается взятым в работу,
     *                       прежде чем транспорт вернёт его в очередь
     * @return string|null идентификатор сообщения, если транспорт его выдаёт
     */
    public function publish(
        JobTransportMessage $message,
        string $queue,
        int $delay = 0,
        int $priority = 0,
        int $ttr = 0
    ): ?string;

    /**
     * Настроена ли полоса.
     */
    public function supports(string $queue): bool;
}
