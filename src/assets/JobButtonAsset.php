<?php
namespace skeeks\cms\job\assets;

class JobButtonAsset extends \yii\web\AssetBundle
{
    public $sourcePath = '@skeeks/cms/job/assets/src';
    public $js = ['job-button.js'];
    public $depends = [
        \yii\web\YiiAsset::class,
        \skeeks\cms\backend\assets\BackendUiAsset::class,
        \skeeks\cms\backend\widgets\assets\ControllerActionsWidgetAsset::class,
    ];
}
