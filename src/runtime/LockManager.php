<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 * @author Semenov Alexander <semenov@skeeks.com>
 */

namespace skeeks\cms\job\runtime;

use skeeks\cms\job\models\CmsJobLock;
use skeeks\cms\job\models\CmsJobRun;
use yii\base\Component;
use yii\db\Exception as DbException;

/**
 * Блокировки ресурсов.
 *
 * Единица взаимного исключения — данные, а не строка расписания. Полное и
 * инкрементальное обновление одного поставщика это разные задания, но один
 * ресурс, поэтому флаг вида `is_running` на строке агента их не разводит.
 */
class LockManager extends Component
{
    /**
     * @var int Через сколько секунд считать брошенную блокировку протухшей,
     *          если задание не продлевает аренду.
     */
    public $defaultTtl = 300;

    /**
     * Захватить ресурс.
     *
     * Захват — INSERT: конкурент получает ошибку уникальности, а не выигрывает
     * гонку чтения и записи.
     *
     * @return bool
     */
    public function acquire($resourceKey, CmsJobRun $run, $ttl = null)
    {
        if (!$resourceKey) {
            return true;
        }

        $this->reapExpired();

        $now = time();
        $ttl = $ttl === null ? $this->defaultTtl : (int)$ttl;

        try {
            \Yii::$app->db->createCommand()->insert(CmsJobLock::tableName(), [
                'resource_key' => $resourceKey,
                'cms_job_run_id' => $run->id,
                'worker_id' => $run->worker_id,
                'acquired_at' => $now,
                'expires_at' => $now + $ttl,
            ])->execute();
        } catch (DbException $e) {
            return false;
        }

        return true;
    }

    /**
     * @return bool
     */
    public function extend($resourceKey, $ttl = null)
    {
        if (!$resourceKey) {
            return true;
        }

        $ttl = $ttl === null ? $this->defaultTtl : (int)$ttl;

        $affected = CmsJobLock::updateAll(
            ['expires_at' => time() + $ttl],
            ['resource_key' => $resourceKey]
        );

        return $affected === 1;
    }

    /**
     * Освободить ресурс. Снимается только своя блокировка.
     */
    public function release($resourceKey, CmsJobRun $run = null)
    {
        if (!$resourceKey) {
            return;
        }

        $condition = ['resource_key' => $resourceKey];
        if ($run !== null) {
            $condition['cms_job_run_id'] = $run->id;
        }

        CmsJobLock::deleteAll($condition);
    }

    /**
     * Снять блокировки, оставшиеся после падения процесса.
     *
     * @return int
     */
    public function reapExpired()
    {
        return CmsJobLock::deleteAll(['<', 'expires_at', time()]);
    }
}
