<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 * @author Semenov Alexander <semenov@skeeks.com>
 */

namespace skeeks\cms\job\runtime;

use yii\base\BaseObject;

/**
 * Экспоненциальная задержка с разбросом.
 *
 * Разброс нужен, чтобы пачка заданий, упавшая из-за одного и того же
 * недоступного сервиса, не возвращалась к нему одновременно.
 *
 * Для внешних служб с лимитами — прежде всего ACME при выпуске SSL — заводится
 * отдельный профиль с большим стартовым интервалом и низким потолком попыток:
 * там агрессивный повтор приводит к блокировке домена, а не к ускорению.
 */
class RetryPolicy extends BaseObject
{
    /**
     * @var int Стартовая задержка, сек.
     */
    public $initialDelay = 10;

    /**
     * @var float
     */
    public $exponent = 2.0;

    /**
     * @var int Потолок задержки, сек.
     */
    public $maxDelay = 3600;

    /**
     * @var float Доля разброса, 0..1.
     */
    public $jitter = 0.2;

    /**
     * @param int $attempt номер завершившейся попытки, начиная с 1
     * @return int секунд до следующей попытки
     */
    public function delayFor($attempt)
    {
        $attempt = max(1, (int)$attempt);

        $delay = $this->initialDelay * pow($this->exponent, $attempt - 1);
        $delay = (int)min($delay, $this->maxDelay);

        if ($this->jitter > 0) {
            $spread = (int)round($delay * $this->jitter);
            if ($spread > 0) {
                $delay += random_int(-$spread, $spread);
            }
        }

        return max(1, $delay);
    }
}
