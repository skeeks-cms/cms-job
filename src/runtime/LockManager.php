<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 * @author Semenov Alexander <semenov@skeeks.com>
 */

namespace skeeks\cms\job\runtime;

use skeeks\cms\job\models\CmsJobLock;
use yii\base\Component;
use yii\db\Exception as DbException;

/**
 * Блокировки ресурсов.
 *
 * Единица взаимного исключения — данные, а не строка расписания. Полное и
 * инкрементальное обновление одного поставщика это разные задания, но один
 * ресурс, поэтому флаг вида `is_running` на строке агента их не разводит.
 *
 * Блокировка принадлежит конкретному захвату, а не запуску: идентификатор
 * запуска переживает перехват, маркер владения — нет. Иначе прежний воркер
 * снял бы блокировку, которую уже держит новый владелец того же запуска.
 */
class LockManager extends Component
{
    /**
     * @var int Через сколько секунд считать брошенную блокировку протухшей,
     *          если владелец не продлевает её.
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
    public function acquire($resourceKey, $runId, $executionToken, $workerId = null, $ttl = null)
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
                'cms_job_run_id' => $runId,
                'execution_token' => $executionToken,
                'worker_id' => $workerId,
                'acquired_at' => $now,
                'expires_at' => $now + $ttl,
            ])->execute();
        } catch (DbException $e) {
            return false;
        }

        return true;
    }

    /**
     * Продлить свою блокировку.
     *
     * Вызывается вместе с продлением аренды запуска. Без этого длинная
     * операция теряла бы блокировку на середине: TTL истекал, уборка снимала
     * запись, и параллельная задача заходила на тот же ресурс.
     *
     * @return bool false — блокировка уже не наша
     */
    public function extend($resourceKey, $executionToken, $ttl = null)
    {
        if (!$resourceKey) {
            return true;
        }

        $ttl = $ttl === null ? $this->defaultTtl : (int)$ttl;

        $condition = ['resource_key' => $resourceKey, 'execution_token' => $executionToken];

        $affected = CmsJobLock::updateAll(['expires_at' => time() + $ttl], $condition);

        if ($affected >= 1) {
            return true;
        }

        // Ноль изменённых строк не означает потерю блокировки: MySQL считает
        // изменённые строки, а не совпавшие, и повторное продление в ту же
        // секунду не меняет `expires_at`. Проверяем владение явно.
        return CmsJobLock::find()->where($condition)->exists();
    }

    /**
     * Освободить ресурс. Снимается только блокировка этого захвата.
     */
    public function release($resourceKey, $executionToken)
    {
        if (!$resourceKey) {
            return;
        }

        CmsJobLock::deleteAll([
            'resource_key' => $resourceKey,
            'execution_token' => $executionToken,
        ]);
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
