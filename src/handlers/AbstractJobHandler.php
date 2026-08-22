<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 * @author Semenov Alexander <semenov@skeeks.com>
 */

namespace skeeks\cms\job\handlers;

use skeeks\cms\job\contracts\JobHandlerInterface;
use yii\base\BaseObject;

/**
 * Основа предметного обработчика.
 */
abstract class AbstractJobHandler extends BaseObject implements JobHandlerInterface
{
}
