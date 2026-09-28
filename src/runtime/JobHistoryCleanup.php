<?php
namespace skeeks\cms\job\runtime;

use skeeks\cms\job\models\CmsJobRun;

/** Shared by scheduled maintenance and the retained cleanup CLI. */
class JobHistoryCleanup
{
    public function cleanup(int $limit = 500, ?callable $checkpoint = null): int
    {
        $condition = [
            'and',
            ['status' => CmsJobRun::finishedStatuses()],
            ['not', ['retention_until' => null]],
            ['<', 'retention_until', time()],
        ];
        $deleted = 0;
        foreach (CmsJobRun::find()->where($condition)->orderBy(['id' => SORT_ASC])
            ->limit(max(1, $limit))->all() as $run) {
            if ($checkpoint) { $checkpoint(); }
            // Remove private files first; a failure must leave the owning history.
            foreach ($run->artifacts as $artifact) {
                if ($checkpoint) { $checkpoint(); }
                if ($artifact->log_path) { \Yii::$app->jobLogs->remove($artifact->log_path); }
            }
            $deleted += CmsJobRun::deleteAll(['and', $condition, ['id' => $run->id]]);
        }
        return $deleted;
    }
}
