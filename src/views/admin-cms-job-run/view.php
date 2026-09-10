<?php
/**
 * Карточка фоновой операции.
 *
 * @var \yii\web\View                          $this
 * @var \skeeks\cms\job\models\CmsJobRun       $model
 * @var bool                                   $canCancel
 * @var bool                                   $canRetry
 * @var string                                 $progressUrl
 */

use skeeks\cms\backend\widgets\BackendSurfaceWidget;
use skeeks\cms\job\assets\CmsJobAsset;
use skeeks\cms\job\models\CmsJobRun;
use skeeks\cms\job\models\CmsJobRunEvent;
use yii\helpers\Html;
use yii\helpers\Json;

$statusVariants = [
    CmsJobRun::STATUS_QUEUED => 'info',
    CmsJobRun::STATUS_RUNNING => 'info',
    CmsJobRun::STATUS_SUCCEEDED => 'success',
    CmsJobRun::STATUS_SUCCEEDED_WITH_WARNINGS => 'warning',
    CmsJobRun::STATUS_FAILED => 'danger',
    CmsJobRun::STATUS_CANCELLED => 'default',
    CmsJobRun::STATUS_TIMED_OUT => 'danger',
];

$eventVariants = [
    CmsJobRunEvent::LEVEL_DEBUG => 'default',
    CmsJobRunEvent::LEVEL_INFO => 'info',
    CmsJobRunEvent::LEVEL_WARNING => 'warning',
    CmsJobRunEvent::LEVEL_ERROR => 'danger',
];

CmsJobAsset::register($this);

$statusVariant = isset($statusVariants[$model->status]) ? $statusVariants[$model->status] : 'default';
$percent = $model->getProgressPercent();
$isActive = !$model->getIsFinished();
?>

<div class="sx-detail-layout" data-sx-job-run="<?= (int)$model->id ?>">

    <div class="sx-detail-layout__main sx-surface-stack">

        <?php
        $summary = Html::tag(
            'div',
            Html::tag('span', Html::encode($model->statusText), [
                'class' => 'sx-status sx-status--'.$statusVariant,
                'data-sx-job-status' => '1',
            ])
            .($model->getIsCancelRequested() && $isActive
                ? ' '.Html::tag('span', Yii::t('skeeks/job', 'запрошена отмена'), ['class' => 'sx-status sx-status--warning'])
                : ''),
            ['class' => 'sx-detail-section']
        );

        $progressBlock = '';
        if ($percent !== null || $model->progress_current) {
            $progressBlock = Html::tag(
                'div',
                Html::tag('div', '', [
                    'class' => 'sx-job-progress__bar',
                    'data-sx-job-progress-bar' => '1',
                    'style' => 'width: '.($percent === null ? 0 : $percent).'%',
                ]),
                ['class' => 'sx-job-progress']
            );
        }

        $progressText = Html::tag(
            'div',
            Html::tag('span', $percent === null
                ? Yii::t('skeeks/job', 'обработано: {n}', ['n' => (int)$model->progress_current])
                : $percent.'%', ['data-sx-job-percent' => '1'])
            .Html::tag('span', Html::encode(
                ($model->stage ? ' · '.Yii::t('skeeks/job', 'этап: {stage}', ['stage' => $model->stage]) : '')
            ), ['data-sx-job-stage' => '1'])
            .Html::tag('span', Html::encode(
                ($model->progress_message ? ' · '.$model->progress_message : '')
            ), ['data-sx-job-message' => '1']),
            ['class' => 'sx-detail-section']
        );

        echo BackendSurfaceWidget::widget([
            'title' => $model->title ? $model->title : $model->job_type,
            'hint' => '#'.$model->id.' · '.$model->job_type,
            'headerBordered' => true,
            // Кнопки отмены и повтора рисует стандартный механизм действий
            // контроллера в шапке карточки: у него уже есть подтверждение,
            // POST, ajax и проверка доступа. Своя пара кнопок здесь только
            // дублировала бы их.
            'content' => $summary.$progressBlock.$progressText,
        ]);
        ?>

        <?php if ($model->error_message): ?>
            <?= BackendSurfaceWidget::widget([
                'title' => Yii::t('skeeks/job', 'Ошибка'),
                'headerBordered' => true,
                'content' => Html::tag('div', Html::encode($model->error_code), ['class' => 'sx-detail-section__title'])
                    .Html::tag('pre', Html::encode($model->error_message)),
            ]) ?>
        <?php endif; ?>

        <?php
        $events = $model->events;
        $timeline = '';

        if ($events) {
            $rows = '';
            foreach ($events as $event) {
                $variant = isset($eventVariants[$event->level]) ? $eventVariants[$event->level] : 'default';

                $rows .= Html::tag(
                    'tr',
                    Html::tag('td', Yii::$app->formatter->asDatetime($event->created_at))
                    .Html::tag('td', Html::tag('span', Html::encode($event->level), [
                        'class' => 'sx-status sx-status--'.$variant,
                    ]))
                    .Html::tag('td', Html::encode((string)$event->stage))
                    .Html::tag('td', Html::encode($event->message))
                );
            }

            $timeline = Html::tag(
                'div',
                Html::tag(
                    'table',
                    Html::tag(
                        'thead',
                        Html::tag(
                            'tr',
                            Html::tag('th', Yii::t('skeeks/job', 'Время'))
                            .Html::tag('th', Yii::t('skeeks/job', 'Уровень'))
                            .Html::tag('th', Yii::t('skeeks/job', 'Этап'))
                            .Html::tag('th', Yii::t('skeeks/job', 'Сообщение'))
                        )
                    ).Html::tag('tbody', $rows),
                    ['class' => 'sx-data-table']
                ),
                ['class' => 'sx-data-table-wrapper']
            );
        } else {
            $timeline = Html::tag('div', Yii::t('skeeks/job', 'Записей нет.'), ['class' => 'sx-detail-section']);
        }

        echo BackendSurfaceWidget::widget([
            'title' => Yii::t('skeeks/job', 'Ход выполнения'),
            'hint' => Yii::t('skeeks/job', 'Этапы и замечания. Ошибки по элементам — в отчёте.'),
            'headerBordered' => true,
            'bodyFlush' => (bool)$events,
            'clip' => true,
            'content' => $timeline,
        ]);
        ?>

    </div>

    <div class="sx-detail-layout__aside sx-surface-stack">

        <?php
        $facts = [
            Yii::t('skeeks/job', 'Очередь') => $model->queue_name,
            Yii::t('skeeks/job', 'Источник запуска') => $model->trigger_type
                .($model->trigger_ref ? ' ('.$model->trigger_ref.')' : ''),
            Yii::t('skeeks/job', 'Инициатор') => $model->createdBy ? $model->createdBy->displayName : '—',
            Yii::t('skeeks/job', 'Попытка') => $model->attempt.' / '.$model->max_attempts,
            Yii::t('skeeks/job', 'Создано') => Yii::$app->formatter->asDatetime($model->created_at),
            Yii::t('skeeks/job', 'Начато') => $model->started_at
                ? Yii::$app->formatter->asDatetime($model->started_at) : '—',
            Yii::t('skeeks/job', 'Завершено') => $model->finished_at
                ? Yii::$app->formatter->asDatetime($model->finished_at) : '—',
            Yii::t('skeeks/job', 'Ресурс') => $model->resource_key ? $model->resource_key : '—',
        ];

        $factRows = '';
        foreach ($facts as $label => $value) {
            $factRows .= Html::tag(
                'tr',
                Html::tag('th', Html::encode($label)).Html::tag('td', Html::encode((string)$value))
            );
        }

        echo BackendSurfaceWidget::widget([
            'title' => Yii::t('skeeks/job', 'Сведения'),
            'headerBordered' => true,
            'bodyFlush' => true,
            'clip' => true,
            'content' => Html::tag(
                'div',
                Html::tag('table', Html::tag('tbody', $factRows), ['class' => 'sx-data-table']),
                ['class' => 'sx-data-table-wrapper']
            ),
        ]);
        ?>

        <?php
        $counters = [
            Yii::t('skeeks/job', 'Успешно') => (int)$model->success_count,
            Yii::t('skeeks/job', 'Замечаний') => (int)$model->warning_count,
            Yii::t('skeeks/job', 'Ошибок') => (int)$model->error_count,
            Yii::t('skeeks/job', 'Пропущено') => (int)$model->skipped_count,
            Yii::t('skeeks/job', 'Отброшено постановок') => (int)$model->skipped_runs,
        ];

        $counterRows = '';
        foreach ($counters as $label => $value) {
            $counterRows .= Html::tag(
                'tr',
                Html::tag('th', Html::encode($label))
                .Html::tag('td', Html::tag('span', (string)$value, [
                    'data-sx-job-counter' => mb_strtolower($label),
                ]))
            );
        }

        echo BackendSurfaceWidget::widget([
            'title' => Yii::t('skeeks/job', 'Итоги'),
            'headerBordered' => true,
            'bodyFlush' => true,
            'clip' => true,
            'content' => Html::tag(
                'div',
                Html::tag('table', Html::tag('tbody', $counterRows), ['class' => 'sx-data-table']),
                ['class' => 'sx-data-table-wrapper']
            ),
        ]);
        ?>

        <?php if ($artifacts = $model->artifacts): ?>
            <?php
            $items = '';
            foreach ($artifacts as $artifact) {
                $label = Html::encode($artifact->name);
                $file = $artifact->storageFile;

                if ($artifact->type === \skeeks\cms\job\models\CmsJobRunArtifact::TYPE_ERROR_REPORT && !$file) {
                    $available = $artifact->log_path && (!$model->isFinished || !$artifact->expires_at || $artifact->expires_at > time());
                    $items .= Html::tag('div', ($available
                        ? Html::a($label, ['log', 'id' => $artifact->id], ['data-pjax' => '0'])
                        : Html::tag('span', $label.' — отчёт недоступен или срок хранения истёк.'))
                        .Html::tag('div', 'Отчёт об ошибках · '.Yii::$app->formatter->asShortSize((int)$artifact->size),
                            ['class' => 'sx-collection-cell__secondary']), ['class' => 'sx-detail-section']);
                    continue;
                }

                if ($artifact->type === \skeeks\cms\job\models\CmsJobRunArtifact::TYPE_LOG && !$file) {
                    $available = $artifact->log_path && (!$model->isFinished || !$artifact->expires_at || $artifact->expires_at > time());
                    $previewHtml = '';
                    if ($available) {
                        ob_start();
                        $lazy = \skeeks\cms\widgets\PjaxLazyLoad::begin([
                            'id' => 'job-log-'.$artifact->id,
                            'enablePushState' => false, 'enableReplaceState' => false,
                            'enabledLoadAssets' => false,
                            'linkSelector' => '#job-log-'.$artifact->id.' [data-sx-log-refresh]', 'formSelector' => false,
                        ]);
                        echo Html::a(Yii::t('skeeks/job', 'Обновить лог'), \yii\helpers\Url::current(), [
                            'data-sx-log-refresh' => '1', 'class' => 'sx-job-log-refresh',
                        ]);
                        echo $lazy->isPjax ? $this->context->renderLogPreview($artifact->id)
                            : Html::tag('p', Yii::t('skeeks/job', 'Загрузка лога…'), ['role' => 'status']);
                        \skeeks\cms\widgets\PjaxLazyLoad::end();
                        $previewHtml = ob_get_clean();
                    }
                    $items .= Html::tag('div', ($available
                        ? Html::a($label, ['log', 'id' => $artifact->id], ['data-pjax' => '0'])
                        : Html::tag('span', $label.' — срок хранения лога истёк.')).$previewHtml,
                        ['class' => 'sx-detail-section']);
                    continue;
                }

                $items .= Html::tag(
                    'div',
                    ($file
                        ? Html::a($label, $file->src, ['class' => 'sx-interactive-surface', 'target' => '_blank'])
                        : Html::tag('span', $label))
                    .Html::tag(
                        'div',
                        Html::encode($artifact->type
                            .($artifact->size ? ' · '.Yii::$app->formatter->asShortSize($artifact->size) : '')),
                        ['class' => 'sx-collection-cell__secondary']
                    ),
                    ['class' => 'sx-detail-section']
                );
            }

            echo BackendSurfaceWidget::widget([
                'title' => Yii::t('skeeks/job', 'Файлы'),
                'headerBordered' => true,
                'content' => $items,
            ]);
            ?>
        <?php endif; ?>

        <?php if ($payload = $model->getPayload()): ?>
            <?= BackendSurfaceWidget::widget([
                'title' => Yii::t('skeeks/job', 'Параметры'),
                'headerBordered' => true,
                'content' => Html::tag('pre', Html::encode(Json::encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE))),
            ]) ?>
        <?php endif; ?>

        <?php if ($result = $model->getResult()): ?>
            <?= BackendSurfaceWidget::widget([
                'title' => Yii::t('skeeks/job', 'Результат'),
                'headerBordered' => true,
                'content' => Html::tag('pre', Html::encode(Json::encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE))),
            ]) ?>
        <?php endif; ?>

    </div>
</div>

<?php
$this->registerJs(<<<JS
(function ($) {
    $(document).off('pjax:error.jobLogPreview').on('pjax:error.jobLogPreview', function (event) {
        if (event.target.id && event.target.id.indexOf('job-log-') === 0) {
            event.preventDefault();
            if (!$(event.target).find('[role="status"]').length) {
                $('<p role="status"></p>').appendTo(event.target);
            }
            $(event.target).find('[role="status"]').text('Не удалось загрузить лог. Нажмите «Обновить лог».');
        }
    });
})(jQuery);
JS
);
// Опрос только пока операция активна: у завершённой обновлять нечего.
if ($isActive) {
    $options = Json::encode([
        'url' => $progressUrl,
        'id' => (int)$model->id,
    ]);

    $this->registerJs(<<<JS
(function (\$) {
    var options = {$options};
    var \$root = \$('[data-sx-job-run="' + options.id + '"]');
    if (!\$root.length) {
        return;
    }

    var timer = setInterval(function () {
        \$.getJSON(options.url, function (response) {
            if (!response || !response.success) {
                return;
            }

            var data = response.data[options.id];
            if (!data) {
                return;
            }

            if (data.label) { \$root.find('[data-sx-job-status]').text(data.label); }

            \$root.find('[data-sx-job-percent]').text(
                data.percent === null ? data.current : data.percent + '%'
            );
            \$root.find('[data-sx-job-stage]').text(data.stage || '');
            \$root.find('[data-sx-job-message]').text(data.message || '');
            \$root.find('[data-sx-job-progress-bar]').css('width', (data.percent || 0) + '%');

            if (data.finished) {
                clearInterval(timer);
                // Завершённая операция меняет доступные действия и итоги,
                // поэтому проще перечитать карточку целиком.
                window.location.reload();
            }
        });
    }, 3000);
})(sx.\$);
JS
    );
}
?>
