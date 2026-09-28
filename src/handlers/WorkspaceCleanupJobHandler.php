<?php
namespace skeeks\cms\job\handlers;

use skeeks\cms\job\contracts\JobReporterInterface;
use skeeks\cms\job\exceptions\JobCancelledException;
use skeeks\cms\job\runtime\JobContext;

class WorkspaceCleanupJobHandler extends AbstractJobHandler
{
    public function run(JobContext $context, JobReporterInterface $reporter): void
    {
        if ($context->getPayload()) { throw new \InvalidArgumentException('Очистка рабочих папок не принимает параметры.'); }
        $checkpoint = static function () use ($reporter): void {
            if ($reporter->isCancelled()) { throw new JobCancelledException('Очистка отменена.'); }
            $reporter->heartbeat();
        };
        $checkpoint();
        $cursor = $context->getCursor();
        $reporter->setStage('cleanup', 'Очистка завершённых рабочих папок');
        $page = \Yii::$app->jobWorkspaces->sweep(false, 100, (int)($cursor['after'] ?? 0), $checkpoint);
        if ($page['unmanaged_entries'] && !isset($cursor['unmanaged_entries'])) {
            $reporter->warning('Неизвестные элементы хранилища сохранены.', ['samples' => $page['unmanaged_samples']]);
            $reporter->countWarning();
        }
        $cursor['unmanaged_entries'] = $page['unmanaged_entries'];
        $cursor['after'] = $page['after'];
        $cursor['deleted'] = (int)($cursor['deleted'] ?? 0) + $page['deleted'];
        $cursor['bytes'] = (int)($cursor['bytes'] ?? 0) + $page['bytes'];
        foreach ($page['items'] as $item) {
            $reason = $item['reason'];
            $cursor['reasons'][$reason] = (int)($cursor['reasons'][$reason] ?? 0) + 1;
            if (in_array($reason, ['unsafe_or_error', 'missing_run', 'missing_or_unfinished_run', 'unknown_completion'], true)) {
                $reporter->warning('Рабочая папка требует проверки.', $item);
                $reporter->countWarning();
            }
        }
        $context->setCursor($cursor);
        $reporter->advance(count($page['items']));
        $reporter->countSuccess($page['deleted']);
        $reporter->setResult($cursor + ['_job_execution' => ['state' => $page['more'] ? 'awaiting_continuation' : 'complete']]);
        if ($page['more']) { $context->requestRequeue(1); }
        $reporter->setStage('complete', 'Удалено папок: '.$cursor['deleted'].', байт: '.$cursor['bytes']);
    }
}
