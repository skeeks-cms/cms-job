<?php
namespace skeeks\cms\job\runtime;

use skeeks\cms\job\models\CmsJobRun;

/** Bounded maintenance, shared by worker polling and the explicit reap command. */
final class JobRecovery
{
    private $lastRun = null;

    public function tick(int $interval = 60): void
    {
        $now = microtime(true);
        if ($this->lastRun !== null && $now - $this->lastRun < $interval) return;
        $this->lastRun = $now;
        try {
            $result = $this->run();
            if (array_sum($result)) \Yii::info($result, 'skeeks/job/recovery');
        } catch (\Throwable $e) {
            // A maintenance outage must neither kill the worker nor turn into a tight retry loop.
            \Yii::error('Восстановление фоновых задач: '.$e->getMessage(), 'skeeks/job/recovery');
        }
    }

    public function run(): array
    {
        $registry = \Yii::$app->jobs->getRegistry();
        $result = \Yii::$app->jobRunStore->reapExpired(static function (CmsJobRun $run) use ($registry) {
            return $registry->has($run->job_type) && $registry->get($run->job_type)->idempotent;
        });
        $result['released'] = \Yii::$app->jobLockManager->reapExpired();
        return $result;
    }
}
