<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 * @author Semenov Alexander <semenov@skeeks.com>
 */

namespace skeeks\cms\job\assets;

use skeeks\cms\backend\assets\BackendUiAsset;
use yii\web\AssetBundle;

/**
 * Оформление карточки фоновой операции.
 *
 * Регистрируется только карточкой прогона: расширять безусловный граф
 * ресурсов ради одной страницы не нужно.
 */
class CmsJobAsset extends AssetBundle
{
    public $sourcePath = '@skeeks/cms/job/assets/src';

    public $css = [
        'job-run.css',
    ];

    public $js = ['job-log-stream.js'];

    public $depends = [
        BackendUiAsset::class,
    ];
}
