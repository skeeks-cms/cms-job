<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 * @author Semenov Alexander <semenov@skeeks.com>
 */

namespace skeeks\cms\job\models;

use skeeks\cms\job\models\queries\CmsJobRunQuery;
use skeeks\cms\models\CmsSite;
use skeeks\cms\models\CmsUser;
use Yii;
use yii\behaviors\TimestampBehavior;
use yii\db\ActiveQuery;
use yii\helpers\Json;

/**
 * Прогон фонового задания: одновременно сообщение очереди и журнал операции.
 *
 * Единственный источник истины намеренно один. Отдельное хранилище сообщений
 * очереди породило бы два независимых состояния одной задачи и целый класс
 * расхождений между ними; в одной базе постановка задания входит в транзакцию
 * бизнес-события, поэтому outbox не нужен.
 *
 * Модель наследует `yii\db\ActiveRecord`, а не `skeeks\cms\base\ActiveRecord`,
 * сознательно:
 *  - `HasTableCache` сбрасывал бы тег кеша при каждом сохранении прогресса,
 *    то есть примерно раз в секунду на каждое работающее задание;
 *  - `BlameableBehavior` перезаписывал бы `created_by` текущей сессией, а
 *    инициатора задания нужно задавать явно, в том числе из консоли.
 *
 * @property int         $id
 * @property string      $uid
 * @property int|null    $cms_site_id
 * @property string      $job_type
 * @property int         $job_version
 * @property string      $queue_name
 * @property string      $visibility
 * @property string|null $title
 * @property string      $trigger_type
 * @property string|null $trigger_ref
 * @property int|null    $created_by
 * @property string|null $correlation_id
 * @property int|null    $parent_id
 * @property int|null    $root_id
 * @property int|null    $retry_of_id
 * @property string      $status
 * @property int         $priority
 * @property int         $attempt
 * @property int         $max_attempts
 * @property int         $available_at
 * @property int|null    $lease_until
 * @property string|null $execution_token
 * @property string|null $worker_id
 * @property int|null    $worker_pid
 * @property int|null    $cancel_requested_at
 * @property int|null    $cancel_requested_by
 * @property string|null $dedup_key
 * @property string|null $dedup_active
 * @property string|null $resource_key
 * @property string      $overlap_policy
 * @property string|null $stage
 * @property int         $progress_current
 * @property int|null    $progress_total
 * @property string|null $progress_message
 * @property int         $success_count
 * @property int         $warning_count
 * @property int         $error_count
 * @property int         $skipped_count
 * @property int         $skipped_runs
 * @property string|null $payload_json
 * @property string|null $cursor_json
 * @property string|null $result_json
 * @property string|null $error_code
 * @property string|null $error_message
 * @property int         $created_at
 * @property int         $updated_at
 * @property int|null    $started_at
 * @property int|null    $finished_at
 * @property int|null    $retention_until
 * @property int         $lock_version
 *
 * ***
 *
 * @property array       $payload
 * @property array       $cursor
 * @property array       $result
 * @property bool        $isFinished
 * @property bool        $isCancelRequested
 * @property float|null  $progressPercent
 * @property string      $statusText
 * @property CmsSite     $cmsSite
 * @property CmsUser     $createdBy
 * @property CmsJobRun   $parent
 * @property CmsJobRunEvent[]    $events
 * @property CmsJobRunArtifact[] $artifacts
 */
class CmsJobRun extends \yii\db\ActiveRecord
{
    const STATUS_QUEUED = 'queued';
    const STATUS_RUNNING = 'running';
    const STATUS_SUCCEEDED = 'succeeded';
    const STATUS_SUCCEEDED_WITH_WARNINGS = 'succeeded_with_warnings';
    const STATUS_FAILED = 'failed';
    const STATUS_CANCELLED = 'cancelled';
    const STATUS_TIMED_OUT = 'timed_out';

    const TRIGGER_MANUAL = 'manual';
    const TRIGGER_SCHEDULE = 'schedule';
    const TRIGGER_EVENT = 'event';
    const TRIGGER_API = 'api';
    const TRIGGER_SYSTEM = 'system';
    const TRIGGER_CHILD = 'child';

    const VISIBILITY_VISIBLE = 'visible';
    const VISIBILITY_TRANSIENT = 'transient';

    const OVERLAP_SKIP = 'skip';
    const OVERLAP_COALESCE = 'coalesce';
    const OVERLAP_QUEUE = 'queue';
    const OVERLAP_REPLACE = 'replace';

    const QUEUE_DEFAULT = 'default';
    const QUEUE_NOTIFICATIONS = 'notifications';
    const QUEUE_MAIL = 'mail';
    const QUEUE_IMPORTS = 'imports';
    const QUEUE_EXPORTS = 'exports';
    const QUEUE_BULK = 'bulk';
    const QUEUE_HOSTING = 'hosting';
    const QUEUE_MAINTENANCE = 'maintenance';

    /**
     * @inheritdoc
     */
    public static function tableName()
    {
        return '{{%cms_job_run}}';
    }

    /**
     * @return CmsJobRunQuery
     */
    public static function find()
    {
        return new CmsJobRunQuery(static::class);
    }

    /**
     * Статусы, при которых задание ещё не завершено.
     *
     * @return string[]
     */
    public static function activeStatuses()
    {
        return [self::STATUS_QUEUED, self::STATUS_RUNNING];
    }

    /**
     * @return string[]
     */
    public static function finishedStatuses()
    {
        return [
            self::STATUS_SUCCEEDED,
            self::STATUS_SUCCEEDED_WITH_WARNINGS,
            self::STATUS_FAILED,
            self::STATUS_CANCELLED,
            self::STATUS_TIMED_OUT,
        ];
    }

    /**
     * @return array
     */
    public static function getStatuses()
    {
        return [
            self::STATUS_QUEUED => Yii::t('skeeks/job', 'В очереди'),
            self::STATUS_RUNNING => Yii::t('skeeks/job', 'Выполняется'),
            self::STATUS_SUCCEEDED => Yii::t('skeeks/job', 'Выполнено'),
            self::STATUS_SUCCEEDED_WITH_WARNINGS => Yii::t('skeeks/job', 'Выполнено с замечаниями'),
            self::STATUS_FAILED => Yii::t('skeeks/job', 'Ошибка'),
            self::STATUS_CANCELLED => Yii::t('skeeks/job', 'Отменено'),
            self::STATUS_TIMED_OUT => Yii::t('skeeks/job', 'Прервано по таймауту'),
        ];
    }

    /**
     * @inheritdoc
     */
    public function behaviors()
    {
        return [
            TimestampBehavior::class => [
                'class' => TimestampBehavior::class,
            ],
        ];
    }

    /**
     * @inheritdoc
     */
    public function optimisticLock()
    {
        return 'lock_version';
    }

    /**
     * @inheritdoc
     */
    public function rules()
    {
        return [
            [['job_type'], 'required'],
            [['job_type'], 'string', 'max' => 128],

            [['uid'], 'string', 'max' => 36],
            [['uid'], 'unique'],
            [['uid'], 'default', 'value' => function () {
                return $this->generateUid();
            }],

            [
                [
                    'cms_site_id',
                    'job_version',
                    'created_by',
                    'parent_id',
                    'root_id',
                    'retry_of_id',
                    'priority',
                    'attempt',
                    'max_attempts',
                    'available_at',
                    'lease_until',
                    'worker_pid',
                    'cancel_requested_at',
                    'cancel_requested_by',
                    'progress_current',
                    'progress_total',
                    'success_count',
                    'warning_count',
                    'error_count',
                    'skipped_count',
                    'skipped_runs',
                    'started_at',
                    'finished_at',
                    'retention_until',
                    'lock_version',
                ],
                'integer',
            ],

            [['status'], 'in', 'range' => array_keys(self::getStatuses())],
            [['status'], 'default', 'value' => self::STATUS_QUEUED],

            [['visibility'], 'in', 'range' => [self::VISIBILITY_VISIBLE, self::VISIBILITY_TRANSIENT]],
            [['visibility'], 'default', 'value' => self::VISIBILITY_VISIBLE],

            [
                ['overlap_policy'],
                'in',
                'range' => [self::OVERLAP_SKIP, self::OVERLAP_COALESCE, self::OVERLAP_QUEUE, self::OVERLAP_REPLACE],
            ],
            [['overlap_policy'], 'default', 'value' => self::OVERLAP_QUEUE],

            [
                ['trigger_type'],
                'in',
                'range' => [
                    self::TRIGGER_MANUAL,
                    self::TRIGGER_SCHEDULE,
                    self::TRIGGER_EVENT,
                    self::TRIGGER_API,
                    self::TRIGGER_SYSTEM,
                    self::TRIGGER_CHILD,
                ],
            ],
            [['trigger_type'], 'default', 'value' => self::TRIGGER_MANUAL],

            [['queue_name'], 'string', 'max' => 32],
            [['queue_name'], 'default', 'value' => self::QUEUE_DEFAULT],

            [['title', 'progress_message'], 'string', 'max' => 255],
            [['stage', 'worker_id', 'correlation_id', 'error_code'], 'string', 'max' => 64],
            [['execution_token'], 'string', 'max' => 32],
            [['trigger_ref', 'dedup_key', 'dedup_active', 'resource_key'], 'string', 'max' => 190],
            [['payload_json', 'cursor_json', 'result_json', 'error_message'], 'string'],

            [['job_version'], 'default', 'value' => 1],
            [['priority'], 'default', 'value' => 100],
            [['attempt'], 'default', 'value' => 0],
            [['max_attempts'], 'default', 'value' => 1],
            [['lock_version'], 'default', 'value' => 0],
            [
                ['progress_current', 'success_count', 'warning_count', 'error_count', 'skipped_count', 'skipped_runs'],
                'default',
                'value' => 0,
            ],
            [['available_at'], 'default', 'value' => function () {
                return time();
            }],
        ];
    }

    /**
     * @inheritdoc
     */
    public function attributeLabels()
    {
        return [
            'id' => Yii::t('skeeks/job', 'ID'),
            'uid' => Yii::t('skeeks/job', 'Идентификатор'),
            'job_type' => Yii::t('skeeks/job', 'Тип операции'),
            'queue_name' => Yii::t('skeeks/job', 'Очередь'),
            'title' => Yii::t('skeeks/job', 'Операция'),
            'trigger_type' => Yii::t('skeeks/job', 'Источник запуска'),
            'created_by' => Yii::t('skeeks/job', 'Инициатор'),
            'status' => Yii::t('skeeks/job', 'Статус'),
            'priority' => Yii::t('skeeks/job', 'Приоритет'),
            'attempt' => Yii::t('skeeks/job', 'Попытка'),
            'max_attempts' => Yii::t('skeeks/job', 'Максимум попыток'),
            'available_at' => Yii::t('skeeks/job', 'Запуск не раньше'),
            'lease_until' => Yii::t('skeeks/job', 'Аренда до'),
            'worker_id' => Yii::t('skeeks/job', 'Обработчик'),
            'stage' => Yii::t('skeeks/job', 'Этап'),
            'progress_current' => Yii::t('skeeks/job', 'Обработано'),
            'progress_total' => Yii::t('skeeks/job', 'Всего'),
            'success_count' => Yii::t('skeeks/job', 'Успешно'),
            'warning_count' => Yii::t('skeeks/job', 'Замечаний'),
            'error_count' => Yii::t('skeeks/job', 'Ошибок'),
            'skipped_count' => Yii::t('skeeks/job', 'Пропущено'),
            'skipped_runs' => Yii::t('skeeks/job', 'Отброшено постановок'),
            'error_message' => Yii::t('skeeks/job', 'Ошибка'),
            'created_at' => Yii::t('skeeks/job', 'Создано'),
            'started_at' => Yii::t('skeeks/job', 'Начато'),
            'finished_at' => Yii::t('skeeks/job', 'Завершено'),
        ];
    }

    /**
     * @return string
     */
    public function generateUid()
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    /**
     * @return string
     */
    public function getStatusText()
    {
        $statuses = self::getStatuses();
        $status = $this->getDisplayStatus();
        if ($status === \skeeks\cms\job\helpers\JobDisplayStatus::CONTINUATION) {
            return Yii::t('skeeks/job', 'Ожидает продолжения');
        }
        if ($status === \skeeks\cms\job\helpers\JobDisplayStatus::STALE) {
            return Yii::t('skeeks/job', 'Статус уточняется');
        }
        return isset($statuses[$status]) ? $statuses[$status] : $status;
    }

    public function getDisplayStatus(): string
    {
        return \skeeks\cms\job\helpers\JobDisplayStatus::resolve((string)$this->status, $this->getResult());
    }

    /**
     * @return bool
     */
    public function getIsFinished()
    {
        return in_array($this->status, self::finishedStatuses(), true);
    }

    /**
     * Отмена — признак, а не статус: задание в этот момент ещё выполняется.
     *
     * @return bool
     */
    public function getIsCancelRequested()
    {
        return $this->cancel_requested_at !== null;
    }

    /**
     * Процент не хранится, а вычисляется.
     *
     * @return float|null
     */
    public function getProgressPercent()
    {
        if (!$this->progress_total) {
            return null;
        }

        return round(min(100, $this->progress_current * 100 / $this->progress_total), 1);
    }

    /**
     * @return array
     */
    public function getPayload()
    {
        return $this->decodeJson($this->payload_json);
    }

    /**
     * @return $this
     */
    public function setPayload(array $value)
    {
        $this->payload_json = Json::encode($value);

        return $this;
    }

    /**
     * @return array
     */
    public function getCursor()
    {
        return $this->decodeJson($this->cursor_json);
    }

    /**
     * @return $this
     */
    public function setCursor(array $value)
    {
        $this->cursor_json = Json::encode($value);

        return $this;
    }

    /**
     * @return array
     */
    public function getResult()
    {
        return $this->decodeJson($this->result_json);
    }

    /**
     * @return $this
     */
    public function setResult(array $value)
    {
        $this->result_json = Json::encode($value);

        return $this;
    }

    /**
     * @return array
     */
    protected function decodeJson($raw)
    {
        if (!$raw) {
            return [];
        }

        try {
            $data = Json::decode((string)$raw);
        } catch (\Exception $e) {
            return [];
        }

        return is_array($data) ? $data : [];
    }

    /**
     * @return ActiveQuery
     */
    public function getCmsSite()
    {
        return $this->hasOne(CmsSite::class, ['id' => 'cms_site_id']);
    }

    /**
     * @return ActiveQuery
     */
    public function getCreatedBy()
    {
        // Явно CmsUser, а не identityClass: воркер работает в консоли,
        // где компонент user может быть не настроен.
        return $this->hasOne(CmsUser::class, ['id' => 'created_by']);
    }

    /**
     * @return ActiveQuery
     */
    public function getParent()
    {
        return $this->hasOne(self::class, ['id' => 'parent_id']);
    }

    /**
     * @return ActiveQuery
     */
    public function getEvents()
    {
        return $this->hasMany(CmsJobRunEvent::class, ['cms_job_run_id' => 'id'])
            ->orderBy(['id' => SORT_ASC]);
    }

    /**
     * @return ActiveQuery
     */
    public function getArtifacts()
    {
        return $this->hasMany(CmsJobRunArtifact::class, ['cms_job_run_id' => 'id'])
            ->orderBy(['id' => SORT_ASC]);
    }
}
