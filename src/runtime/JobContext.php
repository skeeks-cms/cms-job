<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 * @author Semenov Alexander <semenov@skeeks.com>
 */

namespace skeeks\cms\job\runtime;

use skeeks\cms\job\exceptions\JobRequeueException;
use skeeks\cms\job\JobTypeDefinition;
use skeeks\cms\job\models\CmsJobRun;
use skeeks\cms\models\CmsSite;
use yii\base\BaseObject;
use yii\helpers\ArrayHelper;

/**
 * Всё, что обработчику нужно знать о текущем прогоне.
 */
class JobContext extends BaseObject
{
    /**
     * @var CmsJobRun
     */
    public $run;

    /**
     * @var JobTypeDefinition
     */
    public $definition;

    /**
     * @var array
     */
    private $_payload;

    /**
     * @var array
     */
    private $_cursor;

    /**
     * @return CmsJobRun
     */
    public function getRun()
    {
        return $this->run;
    }

    /**
     * @return JobTypeDefinition
     */
    public function getDefinition()
    {
        return $this->definition;
    }

    /**
     * @return array
     */
    public function getPayload()
    {
        if ($this->_payload === null) {
            $this->_payload = $this->run->getPayload();
        }

        return $this->_payload;
    }

    /**
     * @return mixed
     */
    public function get($key, $default = null)
    {
        return ArrayHelper::getValue($this->getPayload(), $key, $default);
    }

    /**
     * @return int
     */
    public function getAttempt()
    {
        return (int)$this->run->attempt;
    }

    /**
     * @return CmsSite|null
     */
    public function getSite()
    {
        return $this->run->cms_site_id ? CmsSite::findOne($this->run->cms_site_id) : null;
    }

    /**
     * Позиция, с которой продолжать. Пусто на первом проходе.
     *
     * @return array
     */
    public function getCursor()
    {
        if ($this->_cursor === null) {
            $this->_cursor = $this->run->getCursor();
        }

        return $this->_cursor;
    }

    /**
     * Курсор сохраняется вместе с очередным пакетом прогресса, а не отдельным
     * запросом.
     */
    public function setCursor(array $cursor)
    {
        $this->_cursor = $cursor;
        $this->run->setCursor($cursor);
    }

    /**
     * Сохранить курсор и продолжить в новом процессе.
     *
     * Не отказ: попытка не расходуется. Так курсорные задания переживают
     * исчерпание памяти, выход из окна выполнения и просто длинные прогоны —
     * следующая порция стартует с чистой памятью, что важнее всего при
     * десятках тысяч записей и растущем identity map ActiveRecord.
     *
     * @throws JobRequeueException
     */
    public function requestRequeue($delay = 0)
    {
        $exception = new JobRequeueException('Задание продолжит работу в новом процессе.');
        $exception->delay = (int)$delay;

        throw $exception;
    }
}
