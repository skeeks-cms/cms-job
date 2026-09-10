<?php
namespace skeeks\cms\job\widgets;

use yii\base\Widget;
use yii\helpers\Html;
use yii\helpers\Url;

/**
 * Job-specific control; the owning controller authorizes both URLs.
 * No handler, command, permission or payload is accepted from the browser.
 * Uses the incumbent backend button and text primitives; no page redesign.
 */
class JobButton extends Widget
{
    public $startUrl;
    public $statusUrl;
    public $label = 'Запустить';
    /** Semantic backend button variant; existing consumers stay secondary. */
    public $primary = false;

    public function run()
    {
        if (!$this->startUrl || !$this->statusUrl) {
            throw new \yii\base\InvalidConfigException('JobButton requires startUrl and statusUrl.');
        }
        \skeeks\cms\job\assets\JobButtonAsset::register($this->view);
        return Html::tag('div',
            Html::button(Html::encode($this->label), [
                'type' => 'button', 'class' => $this->primary ? 'sx-button sx-button--primary' : 'sx-button sx-button--secondary',
                'data-sx-job-start' => '', 'disabled' => true,
            ]).Html::tag('div', 'Проверка статуса…', [
                'data-sx-job-status' => '', 'role' => 'status', 'aria-live' => 'polite',
                'class' => 'sx-collection-cell__secondary',
            ]).Html::tag('div', Html::a('Открыть результат', '#', [
                'data-sx-job-result' => '', 'data-pjax' => '0', 'hidden' => true,
            ])),
            [
                'id' => $this->id, 'data-sx-job-button' => '',
                'data-start-url' => Url::to($this->startUrl),
                'data-status-url' => Url::to($this->statusUrl),
                'data-label' => $this->label,
            ]
        );
    }
}
