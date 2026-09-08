<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 * @author Semenov Alexander <semenov@skeeks.com>
 */

namespace skeeks\cms\job\models;

use skeeks\cms\models\CmsStorageFile;
use yii\behaviors\TimestampBehavior;

/**
 * Файл, произведённый заданием: результат, отчёт об ошибках, исходник, лог.
 *
 * Отдельной таблицы ошибок по элементам нет намеренно: отчёт об ошибках — это
 * артефакт-CSV, что закрывает и просмотр, и выгрузку, и удаление по сроку.
 *
 * @property int             $id
 * @property int             $cms_job_run_id
 * @property string          $type
 * @property string          $name
 * @property int|null        $cms_storage_file_id
 * @property string|null     $mime_type
 * @property int|null        $size
 * @property int             $created_at
 * @property int|null        $expires_at
 * @property string|null     $log_path Relative private log key, never a public URL.
 *
 * @property CmsJobRun       $cmsJobRun
 * @property CmsStorageFile  $storageFile
 */
class CmsJobRunArtifact extends \yii\db\ActiveRecord
{
    const TYPE_RESULT = 'result';
    const TYPE_ERROR_REPORT = 'error-report';
    const TYPE_SOURCE = 'source';
    const TYPE_LOG = 'log';

    /**
     * @inheritdoc
     */
    public static function tableName()
    {
        return '{{%cms_job_run_artifact}}';
    }

    /**
     * @inheritdoc
     */
    public function behaviors()
    {
        return [
            TimestampBehavior::class => [
                'class' => TimestampBehavior::class,
                'createdAtAttribute' => 'created_at',
                'updatedAtAttribute' => false,
            ],
        ];
    }

    /**
     * @inheritdoc
     */
    public function rules()
    {
        return [
            [['cms_job_run_id', 'type', 'name'], 'required'],
            [['cms_job_run_id', 'cms_storage_file_id', 'size', 'created_at', 'expires_at'], 'integer'],
            [['type'], 'string', 'max' => 32],
            [['name'], 'string', 'max' => 255],
            [['log_path'], 'string', 'max' => 190],
            [['mime_type'], 'string', 'max' => 128],
        ];
    }

    /**
     * @return \yii\db\ActiveQuery
     */
    public function getCmsJobRun()
    {
        return $this->hasOne(CmsJobRun::class, ['id' => 'cms_job_run_id']);
    }

    /**
     * @return \yii\db\ActiveQuery
     */
    public function getStorageFile()
    {
        return $this->hasOne(CmsStorageFile::class, ['id' => 'cms_storage_file_id']);
    }
}
