<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 * @author Semenov Alexander <semenov@skeeks.com>
 */

namespace skeeks\cms\job\controllers;

use skeeks\cms\backend\actions\BackendModelAction;
use skeeks\cms\backend\controllers\BackendModelStandartController;
use skeeks\cms\backend\grid\BackendEntityLinkColumn;
use skeeks\cms\job\models\CmsJobRun;
use skeeks\cms\queryfilters\QueryFiltersEvent;
use skeeks\cms\rbac\CmsManager;
use skeeks\yii2\form\fields\SelectField;
use yii\base\Event;
use yii\helpers\ArrayHelper;
use yii\helpers\Html;
use yii\helpers\Url;
use yii\web\Response;

/**
 * Фоновые операции.
 *
 * Создавать и редактировать прогон руками нельзя: он появляется только из
 * зарегистрированного типа задания. Поэтому стандартные `create` и `update`
 * скрыты, а осмысленные действия — просмотр, отмена и повтор.
 */
class AdminCmsJobRunController extends BackendModelStandartController
{
    public function init()
    {
        $this->name = \Yii::t('skeeks/job', 'Фоновые операции');
        $this->modelShowAttribute = 'title';
        $this->modelClassName = CmsJobRun::class;

        $this->generateAccessActions = false;
        $this->permissionName = CmsManager::PERMISSION_ROLE_ADMIN_ACCESS;

        parent::init();
    }

    /**
     * @inheritdoc
     */
    public function actions()
    {
        return ArrayHelper::merge(parent::actions(), [

            'create' => [
                'isVisible' => false,
            ],

            'update' => [
                'isVisible' => false,
            ],

            'view' => [
                'class' => BackendModelAction::class,
                'name' => \Yii::t('skeeks/job', 'Операция'),
                'icon' => 'fa fa-list-ul',
                'priority' => 1,
                'callback' => [$this, 'viewRun'],
            ],

            'cancel' => [
                'class' => BackendModelAction::class,
                'name' => \Yii::t('skeeks/job', 'Отменить'),
                'icon' => 'fa fa-ban',
                'priority' => 50,
                'method' => 'post',
                'request' => 'ajax',
                'confirm' => \Yii::t('skeeks/job', 'Запросить отмену операции?'),
                'callback' => [$this, 'cancelRun'],
                'accessCallback' => function (BackendModelAction $action) {
                    return $this->canCancel($action->model);
                },
            ],

            'retry' => [
                'class' => BackendModelAction::class,
                'name' => \Yii::t('skeeks/job', 'Повторить'),
                'icon' => 'fa fa-redo',
                'priority' => 60,
                'method' => 'post',
                'request' => 'ajax',
                'confirm' => \Yii::t('skeeks/job', 'Запустить операцию заново?'),
                'callback' => [$this, 'retryRun'],
                'accessCallback' => function (BackendModelAction $action) {
                    return $this->canRetry($action->model);
                },
            ],

            'index' => [
                'presentationMode' => 'auto',

                'pageHeader' => [
                    'title' => \Yii::t('skeeks/job', 'Фоновые операции'),
                    'description' => \Yii::t(
                        'skeeks/job',
                        'Импорты, экспорты, массовые изменения и операции с инфраструктурой.'
                    ),
                    'icon' => 'fa fa-tasks',
                    'actions' => false,
                ],

                'emptyState' => [
                    'title' => \Yii::t('skeeks/job', 'Фоновых операций пока не было'),
                    'description' => \Yii::t(
                        'skeeks/job',
                        'Операции появляются здесь после запуска из интерфейса, по расписанию или через API.'
                    ),
                    'icon' => 'fa fa-tasks',
                    'action' => false,
                ],

                'noResultsState' => [
                    'title' => \Yii::t('skeeks/job', 'Операции не найдены'),
                    'description' => \Yii::t('skeeks/job', 'Измените условия отбора.'),
                    'icon' => 'fa fa-search',
                ],

                'filters' => [
                    'visibleFilters' => [
                        'q',
                        'status',
                        'job_type',
                        'queue_name',
                        'created_by',
                    ],

                    'filtersModel' => [
                        'rules' => [
                            [['status', 'job_type', 'queue_name'], 'safe'],
                            [['created_by'], 'integer'],
                        ],

                        'attributeDefines' => [
                            'status',
                            'job_type',
                            'queue_name',
                            'created_by',
                        ],

                        'fields' => [
                            'status' => [
                                'class' => SelectField::class,
                                'multiple' => true,
                                'label' => \Yii::t('skeeks/job', 'Статус'),
                                'items' => CmsJobRun::getStatuses(),
                                'on apply' => function (QueryFiltersEvent $e) {
                                    /** @var \yii\db\ActiveQuery $query */
                                    $query = $e->dataProvider->query;
                                    if ($e->field->value) {
                                        $query->andWhere(['status' => $e->field->value]);
                                    }
                                },
                            ],

                            'job_type' => [
                                'class' => SelectField::class,
                                'multiple' => true,
                                'label' => \Yii::t('skeeks/job', 'Тип операции'),
                                'items' => $this->jobTypeOptions(),
                                'on apply' => function (QueryFiltersEvent $e) {
                                    $query = $e->dataProvider->query;
                                    if ($e->field->value) {
                                        $query->andWhere(['job_type' => $e->field->value]);
                                    }
                                },
                            ],

                            'queue_name' => [
                                'class' => SelectField::class,
                                'multiple' => true,
                                'label' => \Yii::t('skeeks/job', 'Очередь'),
                                'items' => $this->queueOptions(),
                                'on apply' => function (QueryFiltersEvent $e) {
                                    $query = $e->dataProvider->query;
                                    if ($e->field->value) {
                                        $query->andWhere(['queue_name' => $e->field->value]);
                                    }
                                },
                            ],
                        ],
                    ],
                ],

                'grid' => [
                    'on init' => function (Event $e) {
                        /** @var \yii\db\ActiveQuery $query */
                        $query = $e->sender->dataProvider->query;

                        // Технические сообщения — не операции пользователя:
                        // они удаляются при успехе и в журнале появляются
                        // только когда окончательно упали.
                        $query->andWhere(['visibility' => CmsJobRun::VISIBILITY_VISIBLE]);
                    },

                    'defaultOrder' => ['id' => SORT_DESC],
                    'defaultPageSize' => 30,

                    'visibleColumns' => [
                        'checkbox',
                        'actions',
                        'title',
                        'status',
                        'progress',
                        'counters',
                        'created_at',
                    ],

                    'columns' => [
                        'title' => [
                            'class' => BackendEntityLinkColumn::class,
                            'controllerId' => 'cmsJob/admin-cms-job-run',
                            'attribute' => 'title',
                            'label' => \Yii::t('skeeks/job', 'Операция'),
                            'content' => function (CmsJobRun $model) {
                                $title = Html::encode($model->title ? $model->title : $model->job_type);
                                $result = Html::tag('span', $title, ['class' => 'sx-collection-cell__primary']);

                                $meta = '#'.$model->id.' · '.Html::encode($model->job_type);
                                if ($model->queue_name) {
                                    $meta .= ' · '.Html::encode($model->queue_name);
                                }

                                return $result.Html::tag('span', $meta, [
                                        'class' => 'sx-collection-cell__secondary',
                                    ]);
                            },
                        ],

                        'status' => [
                            'label' => \Yii::t('skeeks/job', 'Статус'),
                            'format' => 'raw',
                            'value' => function (CmsJobRun $model) {
                                return $this->renderStatus($model);
                            },
                        ],

                        'progress' => [
                            'label' => \Yii::t('skeeks/job', 'Прогресс'),
                            'format' => 'raw',
                            'value' => function (CmsJobRun $model) {
                                return $this->renderProgress($model);
                            },
                        ],

                        'counters' => [
                            'label' => \Yii::t('skeeks/job', 'Итоги'),
                            'format' => 'raw',
                            'value' => function (CmsJobRun $model) {
                                return $this->renderCounters($model);
                            },
                        ],

                        // Без явной настройки автоколонка выводит целое поле
                        // как есть, то есть сырой timestamp.
                        'created_at' => [
                            'label' => \Yii::t('skeeks/job', 'Создано'),
                            'format' => 'raw',
                            'value' => function (CmsJobRun $model) {
                                $result = Html::tag(
                                    'span',
                                    \Yii::$app->formatter->asRelativeTime($model->created_at),
                                    ['class' => 'sx-collection-cell__primary']
                                );

                                $duration = '';
                                if ($model->started_at && $model->finished_at) {
                                    $duration = \Yii::t('skeeks/job', 'длительность: {n} с', [
                                        'n' => max(0, $model->finished_at - $model->started_at),
                                    ]);
                                }

                                return $result.Html::tag(
                                        'span',
                                        Html::encode(
                                            \Yii::$app->formatter->asDatetime($model->created_at)
                                            .($duration ? ' · '.$duration : '')
                                        ),
                                        ['class' => 'sx-collection-cell__secondary']
                                    );
                            },
                        ],
                    ],
                ],
            ],
        ]);
    }

    /**
     * Карточка операции.
     *
     * @return string
     */
    public function viewRun(BackendModelAction $action)
    {
        /** @var CmsJobRun $model */
        $model = $action->model;

        return $this->render('@skeeks/cms/job/views/admin-cms-job-run/view', [
            'model' => $model,
            'canCancel' => $this->canCancel($model),
            'canRetry' => $this->canRetry($model),
            'progressUrl' => Url::to(['progress', 'id' => $model->id]),
        ]);
    }

    /**
     * Запросить отмену.
     *
     * Отмена кооперативная: обработчик остановится на ближайшей проверке,
     * поэтому ответ говорит именно о запросе, а не о факте остановки.
     *
     * @return array
     */
    public function cancelRun(BackendModelAction $action)
    {
        \Yii::$app->response->format = Response::FORMAT_JSON;

        /** @var CmsJobRun $model */
        $model = $action->model;

        if (!$model || !$this->canCancel($model)) {
            return [
                'success' => false,
                'message' => \Yii::t('skeeks/job', 'Эту операцию отменить нельзя.'),
            ];
        }

        $result = \Yii::$app->jobs->cancel($model, \Yii::$app->user->id);

        return [
            'success' => $result,
            'message' => $result
                ? \Yii::t('skeeks/job', 'Отмена запрошена. Операция остановится на ближайшей проверке.')
                : \Yii::t('skeeks/job', 'Не удалось запросить отмену.'),
        ];
    }

    /**
     * Повторить операцию.
     *
     * Создаётся новый прогон со ссылкой на исходный: история попыток и история
     * перезапусков — разные вещи и не должны сливаться в одной строке.
     *
     * @return array
     */
    public function retryRun(BackendModelAction $action)
    {
        \Yii::$app->response->format = Response::FORMAT_JSON;

        /** @var CmsJobRun $model */
        $model = $action->model;

        if (!$model || !$this->canRetry($model)) {
            return [
                'success' => false,
                'message' => \Yii::t('skeeks/job', 'Эту операцию повторить нельзя.'),
            ];
        }

        try {
            $run = \Yii::$app->jobs->retry($model);
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }

        if (!$run) {
            return [
                'success' => false,
                'message' => \Yii::t('skeeks/job', 'Такая операция уже выполняется.'),
            ];
        }

        return [
            'success' => true,
            'message' => \Yii::t('skeeks/job', 'Операция поставлена в очередь: #{id}', ['id' => $run->id]),
        ];
    }

    /**
     * Лёгкий опрос прогресса для карточки и списка.
     *
     * Отдельная точка, а не перерисовка страницы: прогресс обновляется часто,
     * и тянуть ради него весь грид не нужно.
     *
     * @return array
     */
    public function actionProgress($id = null)
    {
        \Yii::$app->response->format = Response::FORMAT_JSON;

        $ids = $id === null
            ? (array)\Yii::$app->request->get('ids', [])
            : [$id];

        $ids = array_slice(array_filter(array_map('intval', (array)$ids)), 0, 100);

        if (!$ids) {
            return ['success' => true, 'data' => []];
        }

        $rows = CmsJobRun::find()
            ->select([
                'id',
                'status',
                'stage',
                'progress_current',
                'progress_total',
                'progress_message',
                'success_count',
                'warning_count',
                'error_count',
                'skipped_count',
                'attempt',
                'max_attempts',
                'cancel_requested_at',
                'finished_at',
            ])
            ->andWhere(['id' => $ids])
            ->asArray()
            ->all();

        $data = [];
        foreach ($rows as $row) {
            $total = (int)$row['progress_total'];
            $data[$row['id']] = [
                'status' => $row['status'],
                'stage' => $row['stage'],
                'message' => $row['progress_message'],
                'current' => (int)$row['progress_current'],
                'total' => $total ?: null,
                'percent' => $total ? round(min(100, $row['progress_current'] * 100 / $total), 1) : null,
                'success' => (int)$row['success_count'],
                'warning' => (int)$row['warning_count'],
                'error' => (int)$row['error_count'],
                'skipped' => (int)$row['skipped_count'],
                'attempt' => (int)$row['attempt'],
                'maxAttempts' => (int)$row['max_attempts'],
                'cancelRequested' => (bool)$row['cancel_requested_at'],
                'finished' => in_array($row['status'], CmsJobRun::finishedStatuses(), true),
            ];
        }

        return ['success' => true, 'data' => $data];
    }

    /** Download by artifact ID, never by a client-supplied filesystem path. */
    public function actionLog($id)
    {
        [$artifact, $run, $path] = $this->requirePrivateLog($id, true);
        $isReport = $artifact->type === \skeeks\cms\job\models\CmsJobRunArtifact::TYPE_ERROR_REPORT;
        $response = \Yii::$app->response->sendFile($path, $isReport ? 'errors-'.basename($path) : 'console-'.$run->id.'.log', [
            'mimeType' => $isReport ? 'text/csv' : 'text/plain', 'inline' => false,
        ]);
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        return $response;
    }

    /** Called only inside the lazy PJAX block; uses download authorization too. */
    public function actionLogChunk($id, $offset = null)
    {
        \Yii::$app->response->format = Response::FORMAT_JSON;
        \Yii::$app->response->headers->set('Cache-Control', 'private, no-store');
        \Yii::$app->response->headers->set('X-Content-Type-Options', 'nosniff');
        if ($offset !== null && (!is_scalar($offset) || !preg_match('/^[0-9]{1,15}$/D', (string)$offset))) {
            throw new \yii\web\BadRequestHttpException('Некорректная позиция лога.');
        }
        [$artifact, $run] = $this->requirePrivateLog($id);
        $chunk = \Yii::$app->jobLogs->readChunk($artifact->log_path, $offset === null ? null : (int)$offset);
        $chunk['finished'] = $run->isFinished;
        return $chunk;
    }

    /** Called only inside the lazy PJAX block; uses download authorization too. */
    public function renderLogPreview($id): string
    {
        \Yii::$app->response->headers->set('Cache-Control', 'private, no-store');
        try {
            [$artifact] = $this->requirePrivateLog($id);
            $preview = \Yii::$app->jobLogs->preview($artifact->log_path);
        } catch (\yii\web\HttpException $e) {
            return Html::tag('p', Html::encode($e->statusCode === 403
                ? 'Нет доступа к содержимому лога.' : 'Файл лога недоступен или срок хранения истёк.'));
        } catch (\RuntimeException $e) {
            return Html::tag('p', Html::encode('Не удалось прочитать лог. Попробуйте обновить блок.'));
        }
        $notice = $preview['truncated'] ? Html::tag('p',
            'Показаны последние 256 КБ. Полный лог доступен для скачивания.',
            ['class' => 'sx-collection-cell__secondary']) : '';
        return $notice.Html::tag('pre', Html::encode($preview['text'] !== ''
            ? $preview['text'] : 'Лог пока пуст.'), [
                'class' => 'sx-job-log-preview', 'tabindex' => '0',
                'role' => 'region', 'aria-label' => 'Содержимое лога',
                'data-sx-log-stream' => Url::to(['/cmsJob/admin-cms-job-run/log-chunk', 'id' => $id]),
            ]);
    }

    protected function requirePrivateLog($id, bool $allowReport = false): array
    {
        $user = \Yii::$app->user;
        if ($user->isGuest || !$user->can($this->permissionName)) {
            throw new \yii\web\ForbiddenHttpException();
        }
        $artifact = \skeeks\cms\job\models\CmsJobRunArtifact::findOne((int)$id);
        $run = $artifact ? $artifact->cmsJobRun : null;
        $site = \Yii::$app->skeeks->site;
        if (!$run || !$site || (int)$run->cms_site_id !== (int)$site->id
            || !in_array($artifact->type, $allowReport ? ['log', 'error-report'] : ['log'], true)) {
            throw new \yii\web\NotFoundHttpException();
        }
        $registry = \Yii::$app->jobs->getRegistry();
        if ($registry->has($run->job_type)) {
            $permission = $registry->get($run->job_type)->permission;
            if ($permission && !$user->can($permission)) { throw new \yii\web\ForbiddenHttpException(); }
        }
        if (!$artifact->log_path || ($run->isFinished && $artifact->expires_at && $artifact->expires_at <= time())) {
            throw new \yii\web\GoneHttpException('Срок хранения лога истёк.');
        }
        $path = \Yii::$app->jobLogs->resolve($artifact->log_path);
        if (!$path) { throw new \yii\web\GoneHttpException('Файл лога больше не доступен.'); }
        return [$artifact, $run, $path];
    }

    /**
     * @return bool
     */
    protected function canCancel($model)
    {
        if (!$model instanceof CmsJobRun || $model->getIsFinished() || $model->getIsCancelRequested()) {
            return false;
        }

        $registry = \Yii::$app->jobs->getRegistry();

        return !$registry->has($model->job_type) || $registry->get($model->job_type)->cancellable;
    }

    /**
     * @return bool
     */
    protected function canRetry($model)
    {
        if (!$model instanceof CmsJobRun || !$model->getIsFinished()) {
            return false;
        }

        return \Yii::$app->jobs->getRegistry()->has($model->job_type);
    }

    /**
     * @return string
     */
    protected function renderStatus(CmsJobRun $model)
    {
        $variants = [
            CmsJobRun::STATUS_QUEUED => 'info',
            CmsJobRun::STATUS_RUNNING => 'info',
            CmsJobRun::STATUS_SUCCEEDED => 'success',
            CmsJobRun::STATUS_SUCCEEDED_WITH_WARNINGS => 'warning',
            CmsJobRun::STATUS_FAILED => 'danger',
            CmsJobRun::STATUS_CANCELLED => 'default',
            CmsJobRun::STATUS_TIMED_OUT => 'danger',
        ];

        $variant = ArrayHelper::getValue($variants, $model->status, 'default');
        $result = Html::tag('span', Html::encode($model->statusText), [
            'class' => 'sx-status sx-status--'.$variant,
        ]);

        if ($model->getIsCancelRequested() && !$model->getIsFinished()) {
            $result .= Html::tag('span', \Yii::t('skeeks/job', 'запрошена отмена'), [
                'class' => 'sx-collection-cell__secondary',
            ]);
        }

        if ($model->attempt > 1) {
            $result .= Html::tag(
                'span',
                \Yii::t('skeeks/job', 'попытка {n} из {max}', [
                    'n' => $model->attempt,
                    'max' => $model->max_attempts,
                ]),
                ['class' => 'sx-collection-cell__secondary']
            );
        }

        return $result;
    }

    /**
     * @return string
     */
    protected function renderProgress(CmsJobRun $model)
    {
        $percent = $model->getProgressPercent();

        $primary = $percent === null
            ? (string)(int)$model->progress_current
            : $percent.'%';

        $result = Html::tag('span', Html::encode($primary), ['class' => 'sx-collection-cell__primary']);

        $secondary = [];
        if ($model->stage) {
            $secondary[] = $model->stage;
        }
        if ($model->progress_total) {
            $secondary[] = (int)$model->progress_current.' / '.(int)$model->progress_total;
        }

        if ($secondary) {
            $result .= Html::tag('span', Html::encode(implode(' · ', $secondary)), [
                'class' => 'sx-collection-cell__secondary',
            ]);
        }

        return $result;
    }

    /**
     * @return string
     */
    protected function renderCounters(CmsJobRun $model)
    {
        $parts = [];

        if ($model->success_count) {
            $parts[] = \Yii::t('skeeks/job', 'успешно: {n}', ['n' => (int)$model->success_count]);
        }
        if ($model->warning_count) {
            $parts[] = \Yii::t('skeeks/job', 'замечаний: {n}', ['n' => (int)$model->warning_count]);
        }
        if ($model->error_count) {
            $parts[] = \Yii::t('skeeks/job', 'ошибок: {n}', ['n' => (int)$model->error_count]);
        }
        if ($model->skipped_count) {
            $parts[] = \Yii::t('skeeks/job', 'пропущено: {n}', ['n' => (int)$model->skipped_count]);
        }
        if ($model->skipped_runs) {
            $parts[] = \Yii::t('skeeks/job', 'отброшено постановок: {n}', ['n' => (int)$model->skipped_runs]);
        }

        if (!$parts) {
            return '';
        }

        return Html::tag('span', Html::encode(implode(' · ', $parts)), [
            'class' => 'sx-collection-cell__secondary',
        ]);
    }

    /**
     * @return array
     */
    protected function jobTypeOptions()
    {
        $result = [];

        foreach (\Yii::$app->jobs->getRegistry()->all() as $definition) {
            $result[$definition->type] = $definition->title;
        }

        // Типы, которых больше нет в реестре, но которые есть в истории.
        $historic = CmsJobRun::find()
            ->select('job_type')
            ->distinct()
            ->column();

        foreach ($historic as $type) {
            if (!isset($result[$type])) {
                $result[$type] = $type;
            }
        }

        asort($result);

        return $result;
    }

    /**
     * @return array
     */
    protected function queueOptions()
    {
        $queues = CmsJobRun::find()->select('queue_name')->distinct()->column();
        $queues = array_merge($queues, \Yii::$app->jobs->getRegistry()->queues());
        $queues = array_values(array_unique(array_filter($queues)));
        sort($queues);

        return array_combine($queues, $queues);
    }
}
