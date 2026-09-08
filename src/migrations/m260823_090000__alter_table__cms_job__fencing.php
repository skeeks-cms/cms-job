<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 * @author Semenov Alexander <semenov@skeeks.com>
 */

use yii\db\Migration;

/**
 * Маркер владения запуском (fencing token).
 *
 * Без него старый воркер, у которого истекла аренда и чей запуск уже
 * перехвачен новым, продолжает писать в ту же строку: сохраняет прогресс,
 * продлевает чужую аренду, завершает чужой запуск и снимает чужую блокировку.
 * Условия вида `WHERE id = ?` от этого не защищают, потому что идентификатор
 * запуска у обоих одинаковый.
 *
 * Токен выдаётся при каждом реальном захвате и входит в условие каждой
 * последующей записи. Проигравший получает 0 изменённых строк и обязан
 * остановиться, ничего не трогая.
 */
class m260823_090000__alter_table__cms_job__fencing extends Migration
{
    public function safeUp()
    {
        // The legacy transport stored messages in cms_job_run itself. Creating
        // an empty yii2-queue table would strand these runs without delivery.
        // Producers and old workers must be stopped/drained before switching.
        if ((new \yii\db\Query())->from('{{%cms_job_run}}')
            ->where(['status' => ['queued', 'running']])->exists($this->db)) {
            throw new \RuntimeException(
                'cms-job upgrade blocked: finish or explicitly cancel legacy queued/running jobs '
                .'using the old version, stop all producers and workers, then retry migrations. '
                .'No automatic replay of legacy operations is safe.'
            );
        }

        $this->addColumn(
            '{{%cms_job_run}}',
            'execution_token',
            $this->string(32)->null()->comment('Маркер владения текущим захватом')
        );

        $this->addColumn(
            '{{%cms_job_lock}}',
            'execution_token',
            $this->string(32)->null()->comment('Маркер владения захватом, удерживающим блокировку')
        );

        // Ищем просроченные аренды в reaper по этому индексу.
        $this->createIndex(
            'cms_job_run__status_lease',
            '{{%cms_job_run}}',
            ['status', 'lease_until']
        );
    }

    public function safeDown()
    {
        $this->dropIndex('cms_job_run__status_lease', '{{%cms_job_run}}');
        $this->dropColumn('{{%cms_job_lock}}', 'execution_token');
        $this->dropColumn('{{%cms_job_run}}', 'execution_token');

        return true;
    }
}
