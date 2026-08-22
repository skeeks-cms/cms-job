<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 * @author Semenov Alexander <semenov@skeeks.com>
 */

namespace skeeks\cms\job\runtime;

use skeeks\cms\job\exceptions\JobCancelledException;
use skeeks\cms\job\exceptions\JobPermanentException;
use skeeks\cms\job\exceptions\JobTransientException;
use skeeks\cms\job\JobTypeDefinition;
use yii\base\BaseObject;
use yii\base\InvalidArgumentException;
use yii\base\InvalidConfigException;
use yii\db\IntegrityException;

/**
 * Классификация отказов: повторять или нет.
 */
class JobErrorClassifier extends BaseObject
{
    const TRANSIENT = 'transient';
    const PERMANENT = 'permanent';
    const CONFLICT = 'conflict';
    const VALIDATION = 'validation';
    const TIMEOUT = 'timeout';
    const CANCELLED = 'cancelled';

    /**
     * @return string
     */
    public function classify(\Throwable $error, JobTypeDefinition $definition)
    {
        if ($error instanceof JobCancelledException) {
            return self::CANCELLED;
        }

        if ($error instanceof JobTransientException) {
            return self::TRANSIENT;
        }

        if ($error instanceof JobPermanentException) {
            return self::PERMANENT;
        }

        if ($error instanceof IntegrityException) {
            return self::CONFLICT;
        }

        if ($error instanceof InvalidArgumentException || $error instanceof InvalidConfigException) {
            return self::VALIDATION;
        }

        // Неизвестное исключение повторяем только там, где повтор объявлен
        // безопасным. Иначе можно продублировать уже выполненное внешнее
        // действие: обработчик мог упасть уже после него.
        return $definition->idempotent ? self::TRANSIENT : self::PERMANENT;
    }

    /**
     * @return bool
     */
    public function isRetryable($kind)
    {
        return in_array($kind, [self::TRANSIENT, self::CONFLICT, self::TIMEOUT], true);
    }
}
