<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 * @author Semenov Alexander <semenov@skeeks.com>
 */

namespace skeeks\cms\job\models;

/**
 * Блокировка ресурса на время выполнения задания.
 *
 * Отдельная таблица, а не колонка в прогоне: захват через INSERT атомарен
 * благодаря первичному ключу, поэтому проверка «нет ли другой работающей
 * задачи по этому ресурсу» обходится без гонки и без глобального мьютекса.
 *
 * @property string      $resource_key
 * @property int         $cms_job_run_id
 * @property string|null $worker_id
 * @property int         $acquired_at
 * @property int         $expires_at
 */
class CmsJobLock extends \yii\db\ActiveRecord
{
    /**
     * @inheritdoc
     */
    public static function tableName()
    {
        return '{{%cms_job_lock}}';
    }

    /**
     * @inheritdoc
     */
    public static function primaryKey()
    {
        return ['resource_key'];
    }

    /**
     * @inheritdoc
     */
    public function rules()
    {
        return [
            [['resource_key', 'cms_job_run_id', 'acquired_at', 'expires_at'], 'required'],
            [['cms_job_run_id', 'acquired_at', 'expires_at'], 'integer'],
            [['resource_key'], 'string', 'max' => 190],
            [['worker_id'], 'string', 'max' => 64],
        ];
    }
}
