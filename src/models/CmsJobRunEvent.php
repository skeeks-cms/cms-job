<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 * @author Semenov Alexander <semenov@skeeks.com>
 */

namespace skeeks\cms\job\models;

use yii\behaviors\TimestampBehavior;
use yii\helpers\Json;

/**
 * Лента выполнения задания, только на добавление.
 *
 * Жёсткий контракт: сюда пишутся этапы и агрегаты, но не события на каждый
 * обработанный элемент. Ошибки по элементам уходят в CSV-артефакт через
 * JobReporterInterface::itemError(); иначе таблица растёт быстрее всей
 * остальной системы, а читают её один раз.
 *
 * @property int         $id
 * @property int         $cms_job_run_id
 * @property string      $level
 * @property string|null $stage
 * @property string      $message
 * @property string|null $context_json
 * @property int         $created_at
 *
 * @property array       $context
 * @property CmsJobRun   $cmsJobRun
 */
class CmsJobRunEvent extends \yii\db\ActiveRecord
{
    const LEVEL_DEBUG = 'debug';
    const LEVEL_INFO = 'info';
    const LEVEL_WARNING = 'warning';
    const LEVEL_ERROR = 'error';

    /**
     * @inheritdoc
     */
    public static function tableName()
    {
        return '{{%cms_job_run_event}}';
    }

    /**
     * @inheritdoc
     *
     * Время проставляется поведением, а не правилом по умолчанию: события
     * пишутся через save(false), а валидаторы при этом не выполняются.
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
            [['cms_job_run_id', 'message'], 'required'],
            [['cms_job_run_id', 'created_at'], 'integer'],
            [['message', 'context_json'], 'string'],
            [
                ['level'],
                'in',
                'range' => [self::LEVEL_DEBUG, self::LEVEL_INFO, self::LEVEL_WARNING, self::LEVEL_ERROR],
            ],
            [['level'], 'default', 'value' => self::LEVEL_INFO],
            [['stage'], 'string', 'max' => 64],
        ];
    }

    /**
     * @return array
     */
    public function getContext()
    {
        if (!$this->context_json) {
            return [];
        }

        try {
            $data = Json::decode((string)$this->context_json);
        } catch (\Exception $e) {
            return [];
        }

        return is_array($data) ? $data : [];
    }

    /**
     * @return $this
     */
    public function setContext(array $value)
    {
        $this->context_json = $value ? Json::encode($value) : null;

        return $this;
    }

    /**
     * @return \yii\db\ActiveQuery
     */
    public function getCmsJobRun()
    {
        return $this->hasOne(CmsJobRun::class, ['id' => 'cms_job_run_id']);
    }
}
