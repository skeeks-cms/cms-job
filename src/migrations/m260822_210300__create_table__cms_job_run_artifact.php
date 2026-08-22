<?php

use yii\db\Migration;

/**
 * Файлы, произведённые заданием.
 */
class m260822_210300__create_table__cms_job_run_artifact extends Migration
{
    public function safeUp()
    {
        $tableName = '{{%cms_job_run_artifact}}';
        $tableOptions = $this->db->driverName === 'mysql'
            ? 'CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE=InnoDB'
            : null;

        $this->createTable($tableName, [
            'id'                  => $this->primaryKey(),
            'cms_job_run_id'      => $this->bigInteger()->notNull(),
            'type'                => $this->string(32)->notNull()->comment("result|error-report|source|log"),
            'name'                => $this->string(255)->notNull(),
            'cms_storage_file_id' => $this->integer()->null(),
            'mime_type'           => $this->string(128)->null(),
            'size'                => $this->bigInteger()->null(),
            'created_at'          => $this->integer()->notNull(),
            'expires_at'          => $this->integer()->null(),
        ], $tableOptions);

        $this->createIndex('cms_job_run_artifact__run', $tableName, 'cms_job_run_id');
        $this->createIndex('cms_job_run_artifact__expires_at', $tableName, 'expires_at');

        $this->addForeignKey(
            'cms_job_run_artifact__cms_job_run_id',
            $tableName,
            'cms_job_run_id',
            '{{%cms_job_run}}',
            'id',
            'CASCADE',
            'CASCADE'
        );

        $this->addForeignKey(
            'cms_job_run_artifact__cms_storage_file_id',
            $tableName,
            'cms_storage_file_id',
            '{{%cms_storage_file}}',
            'id',
            'SET NULL',
            'SET NULL'
        );

        $this->addCommentOnTable($tableName, 'Артефакты фоновых заданий');
    }

    public function safeDown()
    {
        $this->dropTable('{{%cms_job_run_artifact}}');

        return true;
    }
}
