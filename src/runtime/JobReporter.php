<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 * @author Semenov Alexander <semenov@skeeks.com>
 */

namespace skeeks\cms\job\runtime;

use skeeks\cms\job\contracts\JobReporterInterface;
use skeeks\cms\job\JobTypeDefinition;
use skeeks\cms\job\models\CmsJobRun;
use skeeks\cms\job\models\CmsJobRunArtifact;
use skeeks\cms\job\models\CmsJobRunEvent;
use yii\base\BaseObject;
use yii\helpers\FileHelper;

/**
 * Буферизованный отчёт о ходе выполнения.
 *
 * Обработчик вправе звать advance() на каждый элемент. Прямое сохранение
 * означало бы 20 тыс. UPDATE одной строки при импорте 20 тыс. товаров, причём
 * эту же строку параллельно опрашивает интерфейс. Поэтому счётчики копятся в
 * памяти и уходят в базу одним запросом не чаще раза в flushIntervalMs; тем же
 * запросом продлевается аренда, отдельного heartbeat нет.
 */
class JobReporter extends BaseObject implements JobReporterInterface
{
    /**
     * @var CmsJobRun
     */
    public $run;

    /**
     * @var JobTypeDefinition
     */
    public $definition;

    /**
     * @var JobContext|null
     */
    public $context;

    /**
     * @var int Минимальный интервал между сохранениями прогресса, мс.
     */
    public $flushIntervalMs = 1000;

    /**
     * @var int Минимальный интервал между перечитываниями признака отмены, мс.
     */
    public $cancelCheckIntervalMs = 2000;

    /**
     * @var array Накопленные, ещё не сохранённые изменения.
     */
    private $_pending = [];

    /**
     * @var float
     */
    private $_lastFlushAt = 0.0;

    /**
     * @var float
     */
    private $_lastCancelCheckAt = 0.0;

    /**
     * @var bool
     */
    private $_cancelled = false;

    /**
     * @var int Сколько событий уже записано в ленту этого прогона.
     */
    private $_eventCount = 0;

    /**
     * @var bool
     */
    private $_eventLimitAnnounced = false;

    /**
     * @var resource|null Открытый CSV с ошибками по элементам.
     */
    private $_errorCsv;

    /**
     * @var string|null
     */
    private $_errorCsvPath;

    /**
     * @var int
     */
    private $_errorCsvRows = 0;

    public function init()
    {
        parent::init();

        $this->_lastFlushAt = microtime(true);
        $this->_lastCancelCheckAt = microtime(true);
        $this->_eventCount = (int)CmsJobRunEvent::find()
            ->andWhere(['cms_job_run_id' => $this->run->id])
            ->count();
    }

    /**
     * @inheritdoc
     */
    public function setStage(string $stage, ?string $message = null): void
    {
        $this->run->stage = $stage;
        if ($message !== null) {
            $this->run->progress_message = $message;
        }

        $this->writeEvent(CmsJobRunEvent::LEVEL_INFO, $message === null ? $stage : $message, [], $stage);

        // Смена этапа — точка, в которой состояние должно быть видно сразу.
        $this->flush(true);
    }

    /**
     * @inheritdoc
     */
    public function setTotal(?int $total): void
    {
        $this->run->progress_total = $total;
        $this->flush(true);
    }

    /**
     * @inheritdoc
     */
    public function advance(int $by = 1): void
    {
        $this->bump('progress_current', $by);
    }

    /**
     * @inheritdoc
     */
    public function countSuccess(int $by = 1): void
    {
        $this->bump('success_count', $by);
    }

    /**
     * @inheritdoc
     */
    public function countWarning(int $by = 1): void
    {
        $this->bump('warning_count', $by);
    }

    /**
     * @inheritdoc
     */
    public function countError(int $by = 1): void
    {
        $this->bump('error_count', $by);
    }

    /**
     * @inheritdoc
     */
    public function countSkipped(int $by = 1): void
    {
        $this->bump('skipped_count', $by);
    }

    /**
     * @inheritdoc
     */
    public function info(string $message, array $context = []): void
    {
        $this->writeEvent(CmsJobRunEvent::LEVEL_INFO, $message, $context);
    }

    /**
     * @inheritdoc
     */
    public function warning(string $message, array $context = []): void
    {
        $this->bump('warning_count', 1);
        $this->writeEvent(CmsJobRunEvent::LEVEL_WARNING, $message, $context);
    }

    /**
     * @inheritdoc
     */
    public function error(string $message, array $context = []): void
    {
        $this->bump('error_count', 1);
        $this->writeEvent(CmsJobRunEvent::LEVEL_ERROR, $message, $context);
    }

    /**
     * @inheritdoc
     */
    public function itemError(string $itemType, $itemId, string $message, array $row = []): void
    {
        $this->bump('error_count', 1);
        $this->appendErrorCsv($itemType, $itemId, $message, $row);

        // В ленту попадают только первые ошибки: она для быстрого взгляда,
        // полный перечень живёт в CSV.
        $this->writeEvent(CmsJobRunEvent::LEVEL_ERROR, $message, [
            'item_type' => $itemType,
            'item_id' => $itemId,
        ]);
    }

    /**
     * @inheritdoc
     */
    public function heartbeat(): void
    {
        $this->flush();
    }

    /**
     * @inheritdoc
     */
    public function isCancelled(): bool
    {
        if ($this->_cancelled) {
            return true;
        }

        $now = microtime(true);
        if (($now - $this->_lastCancelCheckAt) * 1000 < $this->cancelCheckIntervalMs) {
            return false;
        }

        $this->_lastCancelCheckAt = $now;

        // Прогресс сбрасывается перед проверкой, чтобы отменяющий видел
        // актуальное состояние в момент запроса отмены.
        $this->flush(true);

        $value = CmsJobRun::find()
            ->select(['cancel_requested_at'])
            ->andWhere(['id' => $this->run->id])
            ->scalar();

        if ($value) {
            $this->_cancelled = true;
            $this->run->cancel_requested_at = (int)$value;
        }

        return $this->_cancelled;
    }

    /**
     * @inheritdoc
     */
    public function addArtifact(string $type, string $path, array $options = []): CmsJobRunArtifact
    {
        $artifact = new CmsJobRunArtifact([
            'cms_job_run_id' => $this->run->id,
            'type' => $type,
            'name' => isset($options['name']) ? $options['name'] : basename($path),
            'mime_type' => isset($options['mime_type']) ? $options['mime_type'] : null,
            'size' => is_file($path) ? filesize($path) : null,
            'expires_at' => isset($options['expires_at']) ? $options['expires_at'] : $this->defaultExpiresAt(),
        ]);

        if (is_file($path)) {
            $artifact->cms_storage_file_id = $this->storeFile($path, $artifact->name);
        }

        if (!$artifact->save()) {
            throw new \RuntimeException('Unable to save job artifact: '.print_r($artifact->errors, true));
        }

        return $artifact;
    }

    /**
     * @inheritdoc
     */
    public function setResult(array $result): void
    {
        $this->run->setResult($result);
        $this->flush(true);
    }

    /**
     * Сохранить накопленное и закрыть открытые файлы.
     *
     * Вызывается ядром при завершении попытки.
     */
    public function finalize()
    {
        $this->closeErrorCsv();
        $this->flush(true);
    }

    /**
     * Прибавить к счётчику в буфере.
     */
    protected function bump($attribute, $by)
    {
        if (!$by) {
            return;
        }

        if (!isset($this->_pending[$attribute])) {
            $this->_pending[$attribute] = 0;
        }

        $this->_pending[$attribute] += $by;
        $this->run->{$attribute} = (int)$this->run->{$attribute} + $by;

        $this->flush();
    }

    /**
     * Один UPDATE: счётчики, прогресс, курсор и продление аренды.
     *
     * @param bool $force сохранить, не дожидаясь истечения интервала
     */
    public function flush($force = false)
    {
        $now = microtime(true);

        if (!$force && ($now - $this->_lastFlushAt) * 1000 < $this->flushIntervalMs) {
            return;
        }

        $this->_lastFlushAt = $now;

        $values = [
            'stage' => $this->run->stage,
            'progress_current' => (int)$this->run->progress_current,
            'progress_total' => $this->run->progress_total,
            'progress_message' => $this->run->progress_message,
            'success_count' => (int)$this->run->success_count,
            'warning_count' => (int)$this->run->warning_count,
            'error_count' => (int)$this->run->error_count,
            'skipped_count' => (int)$this->run->skipped_count,
            'cursor_json' => $this->run->cursor_json,
            'result_json' => $this->run->result_json,
            'lease_until' => time() + $this->definition->leaseSeconds,
            'updated_at' => time(),
        ];

        CmsJobRun::updateAll($values, ['id' => $this->run->id]);

        $this->_pending = [];
    }

    /**
     * Записать событие с учётом потолка.
     */
    protected function writeEvent($level, $message, array $context = [], $stage = null)
    {
        if ($this->_eventCount >= $this->definition->maxEvents) {
            if (!$this->_eventLimitAnnounced) {
                $this->_eventLimitAnnounced = true;

                $event = new CmsJobRunEvent([
                    'cms_job_run_id' => $this->run->id,
                    'level' => CmsJobRunEvent::LEVEL_WARNING,
                    'stage' => $this->run->stage,
                    'message' => 'Достигнут предел записей в ленте; дальнейшие подробности только в артефактах.',
                ]);
                $event->save(false);
                $this->_eventCount++;
            }

            return;
        }

        $event = new CmsJobRunEvent([
            'cms_job_run_id' => $this->run->id,
            'level' => $level,
            'stage' => $stage === null ? $this->run->stage : $stage,
            'message' => $message,
        ]);
        $event->setContext($context);

        if ($event->save(false)) {
            $this->_eventCount++;
        }
    }

    /**
     * Дописать строку в потоковый CSV с ошибками.
     */
    protected function appendErrorCsv($itemType, $itemId, $message, array $row)
    {
        if ($this->_errorCsv === null) {
            $dir = \Yii::getAlias('@runtime/cms-job');
            FileHelper::createDirectory($dir);

            $this->_errorCsvPath = $dir.'/job-'.$this->run->id.'-errors.csv';
            $this->_errorCsv = fopen($this->_errorCsvPath, 'w');

            if ($this->_errorCsv === false) {
                $this->_errorCsv = null;
                $this->_errorCsvPath = null;

                return;
            }

            // BOM, чтобы Excel не ломал кириллицу.
            fwrite($this->_errorCsv, "\xEF\xBB\xBF");
            fputcsv($this->_errorCsv, ['item_type', 'item_id', 'message', 'data'], ';');
        }

        fputcsv($this->_errorCsv, [
            $itemType,
            (string)$itemId,
            $message,
            $row ? json_encode($row, JSON_UNESCAPED_UNICODE) : '',
        ], ';');

        $this->_errorCsvRows++;
    }

    /**
     * Закрыть CSV и приложить его к прогону.
     */
    protected function closeErrorCsv()
    {
        if ($this->_errorCsv === null) {
            return;
        }

        fclose($this->_errorCsv);
        $this->_errorCsv = null;

        if ($this->_errorCsvRows > 0 && $this->_errorCsvPath && is_file($this->_errorCsvPath)) {
            try {
                $this->addArtifact(CmsJobRunArtifact::TYPE_ERROR_REPORT, $this->_errorCsvPath, [
                    'name' => 'errors-'.$this->run->id.'.csv',
                    'mime_type' => 'text/csv',
                ]);
            } catch (\Throwable $e) {
                \Yii::error('Не удалось приложить отчёт об ошибках: '.$e->getMessage(), 'skeeks/job');
            }
        }

        if ($this->_errorCsvPath && is_file($this->_errorCsvPath)) {
            @unlink($this->_errorCsvPath);
        }

        $this->_errorCsvPath = null;
    }

    /**
     * @return int|null
     */
    protected function storeFile($path, $name)
    {
        try {
            // Storage::upload() перемещает локальный файл в хранилище,
            // поэтому размер вычисляется до вызова.
            $file = \Yii::$app->storage->upload($path);

            return $file ? $file->id : null;
        } catch (\Throwable $e) {
            \Yii::error('Не удалось сохранить артефакт в хранилище: '.$e->getMessage(), 'skeeks/job');

            return null;
        }
    }

    /**
     * @return int|null
     */
    protected function defaultExpiresAt()
    {
        return $this->definition->retentionDays > 0
            ? time() + $this->definition->retentionDays * 86400
            : null;
    }
}
