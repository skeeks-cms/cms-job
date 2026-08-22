<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 * @author Semenov Alexander <semenov@skeeks.com>
 */

namespace skeeks\cms\job\models\queries;

use skeeks\cms\job\models\CmsJobRun;
use yii\db\ActiveQuery;

/**
 * @see CmsJobRun
 */
class CmsJobRunQuery extends ActiveQuery
{
    /**
     * @return $this
     */
    public function active()
    {
        return $this->andWhere(['status' => CmsJobRun::activeStatuses()]);
    }

    /**
     * @return $this
     */
    public function finished()
    {
        return $this->andWhere(['status' => CmsJobRun::finishedStatuses()]);
    }

    /**
     * Только операции, которые показываются пользователю.
     *
     * @return $this
     */
    public function visible()
    {
        return $this->andWhere(['visibility' => CmsJobRun::VISIBILITY_VISIBLE]);
    }

    /**
     * @return $this
     */
    public function ofType($type)
    {
        return $this->andWhere(['job_type' => $type]);
    }

    /**
     * @return $this
     */
    public function forResource($resourceKey)
    {
        return $this->andWhere(['resource_key' => $resourceKey]);
    }

    /**
     * @return $this
     */
    public function forSite($siteId)
    {
        return $this->andWhere(['cms_site_id' => $siteId]);
    }

    /**
     * @return $this
     */
    public function createdBy($userId)
    {
        return $this->andWhere(['created_by' => $userId]);
    }

    /**
     * Готовые к запуску прямо сейчас.
     *
     * @return $this
     */
    public function due($time = null)
    {
        return $this
            ->andWhere(['status' => CmsJobRun::STATUS_QUEUED])
            ->andWhere(['<=', 'available_at', $time === null ? time() : $time]);
    }
}
