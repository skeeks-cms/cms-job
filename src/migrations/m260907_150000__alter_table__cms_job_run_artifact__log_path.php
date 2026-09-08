<?php
use yii\db\Migration;

class m260907_150000__alter_table__cms_job_run_artifact__log_path extends Migration
{
    public function safeUp()
    {
        $this->addColumn('{{%cms_job_run_artifact}}', 'log_path', $this->string(190)->null());
        $this->createIndex('idx_job_artifact_log_path', '{{%cms_job_run_artifact}}', 'log_path');
        $this->createIndex('idx_job_artifact_expires', '{{%cms_job_run_artifact}}', 'expires_at');
    }

    public function safeDown()
    {
        echo "Private log references must not be dropped automatically.\n";
        return false;
    }
}
