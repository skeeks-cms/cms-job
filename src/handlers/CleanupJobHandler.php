<?php
namespace skeeks\cms\job\handlers;

use skeeks\cms\job\contracts\JobReporterInterface;
use skeeks\cms\job\exceptions\JobCancelledException;
use skeeks\cms\job\runtime\JobContext;
use skeeks\cms\job\runtime\JobHistoryCleanup;

/** Bounded deliveries drain expired history/diagnostics without a daily backlog cap. */
class CleanupJobHandler extends AbstractJobHandler
{
    /** Selected by the registered type, never by user payload. */
    public $operation = 'history';
    public $batchSize = 500;

    public function run(JobContext $context, JobReporterInterface $reporter): void
    {
        if ($context->getPayload()) {
            throw new \InvalidArgumentException('Очистка заданий не принимает параметры запуска.');
        }
        if (!in_array($this->operation, ['history', 'logs'], true)) {
            throw new \LogicException('Неизвестный вид очистки заданий.');
        }
        $checkpoint = static function () use ($reporter): void {
            if ($reporter->isCancelled()) { throw new JobCancelledException('Очистка отменена.'); }
            $reporter->heartbeat();
        };
        $checkpoint();
        $limit = max(1, min(500, (int)$this->batchSize));
        $reporter->setTotal(null);
        $reporter->setStage($this->operation, $this->operation === 'history'
            ? 'Удаление просроченной истории заданий' : 'Удаление просроченных логов и отчётов');
        if ($this->operation === 'history') {
            $batch = ['deleted_runs' => (new JobHistoryCleanup())->cleanup($limit, $checkpoint)];
        } else {
            $batch = [
                'expired_logs' => \Yii::$app->jobLogs->cleanup($limit, $checkpoint),
                'orphan_logs' => \Yii::$app->jobLogs->cleanupOrphans($limit, $checkpoint),
            ];
        }
        $totals = $context->getCursor();
        foreach ($batch as $key => $count) { $totals[$key] = (int)($totals[$key] ?? 0) + $count; }
        $context->setCursor($totals);
        $reporter->advance(array_sum($batch));
        $reporter->countSuccess(array_sum($batch));
        $more = max($batch) >= $limit;
        $result = $totals;
        $result['_job_execution'] = ['state' => $more ? 'awaiting_continuation' : 'complete'];
        $reporter->setResult($result);
        if ($more) { $context->requestRequeue(1); }
        $reporter->setStage('complete', 'Очистка завершена. Удалено: '.array_sum($totals).'.');
    }
}
