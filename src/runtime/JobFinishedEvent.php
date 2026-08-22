<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 * @author Semenov Alexander <semenov@skeeks.com>
 */

namespace skeeks\cms\job\runtime;

use skeeks\cms\job\models\CmsJobRun;
use yii\base\Event;

/**
 * Задание завершилось — любым исходом.
 *
 * Ядро не знает ничего о каналах доставки: оно публикует доменное событие,
 * а решение «кому и куда сообщить» принимает служба уведомлений. Так очередь
 * не зависит от транспорта уведомлений, а уведомления — от воркера.
 */
class JobFinishedEvent extends Event
{
    /**
     * @var CmsJobRun
     */
    public $run;

    /**
     * @var string
     */
    public $status;
}
