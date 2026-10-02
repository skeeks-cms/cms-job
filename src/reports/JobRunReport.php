<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 * @author Semenov Alexander <semenov@skeeks.com>
 */

namespace skeeks\cms\job\reports;

use skeeks\cms\job\contracts\JobRunReportInterface;
use skeeks\cms\job\models\CmsJobRun;
use yii\base\BaseObject;
use yii\web\View;

/**
 * Отчёт, который рисует один файл представления.
 *
 * Представлению передаются `model`, `details` (начальные данные из
 * {@see details()}) и `detailsUrl`.
 */
abstract class JobRunReport extends BaseObject implements JobRunReportInterface
{
    /**
     * @var string Алиас файла представления отчёта.
     */
    public $view;

    /**
     * @var string|null Право, без которого отчёт не показывается.
     */
    public $permission;

    public function canView(CmsJobRun $run): bool
    {
        return $this->permission === null || \Yii::$app->user->can($this->permission);
    }

    public function render(CmsJobRun $run, View $view, array $context): string
    {
        return $view->render($this->view, array_merge($context, [
            'model' => $run,
            'details' => $this->details($run),
        ]));
    }
}
