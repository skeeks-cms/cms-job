<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 * @author Semenov Alexander <semenov@skeeks.com>
 */

namespace skeeks\cms\job\exceptions;

use yii\base\Exception;

/**
 * Базовое исключение подсистемы заданий.
 */
class JobException extends Exception
{
    /**
     * @var string|null Машиночитаемый код для колонки error_code.
     */
    public $errorCode;

    public function __construct($message = '', $errorCode = null, $code = 0, ?\Throwable $previous = null)
    {
        $this->errorCode = $errorCode;
        parent::__construct($message, $code, $previous);
    }
}
