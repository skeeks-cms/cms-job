<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 * @author Semenov Alexander <semenov@skeeks.com>
 */

namespace skeeks\cms\job\contracts;

use skeeks\cms\job\models\CmsJobRunArtifact;

/**
 * Отчёт о ходе выполнения задания.
 *
 * Реализация обязана буферизовать записи и сохранять их пакетно: обработчик
 * вправе вызывать `advance()` на каждый элемент, а прогон на 20 тыс. элементов
 * не должен превращаться в 20 тыс. UPDATE одной строки, которую параллельно
 * опрашивает интерфейс.
 */
interface JobReporterInterface
{
    public function setStage(string $stage, ?string $message = null): void;

    /**
     * @param int|null $total null — общее количество заранее неизвестно
     */
    public function setTotal(?int $total): void;

    public function advance(int $by = 1): void;

    public function countSuccess(int $by = 1): void;

    public function countWarning(int $by = 1): void;

    public function countError(int $by = 1): void;

    public function countSkipped(int $by = 1): void;

    public function info(string $message, array $context = []): void;

    public function warning(string $message, array $context = []): void;

    public function error(string $message, array $context = []): void;

    /**
     * Ошибка по отдельному элементу.
     *
     * Инкрементирует `error_count`, дописывает строку в потоковый CSV-артефакт
     * и кладёт в ленту событий не более `maxEvents` записей. Отдельной таблицы
     * ошибок по элементам нет намеренно: при больших импортах она росла бы
     * быстрее всей остальной системы, а читают её один раз.
     *
     * @param int|string|null $itemId
     */
    public function itemError(string $itemType, $itemId, string $message, array $row = []): void;

    /**
     * Продлить аренду. Отдельного heartbeat нет: аренда продлевается тем же
     * запросом, что сохраняет прогресс.
     */
    public function heartbeat(): void;

    public function isCancelled(): bool;

    public function addArtifact(string $type, string $path, array $options = []): CmsJobRunArtifact;

    public function setResult(array $result): void;
}
