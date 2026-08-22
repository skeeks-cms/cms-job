<?php

use yii\db\Migration;

/**
 * Лента выполнения задания.
 */
class m260822_210200__create_table__cms_job_run_event extends Migration
{
    public function safeUp()
    {
        $tableName = '{{%cms_job_run_event}}';
        $tableOptions = $this->db->driverName === 'mysql'
            ? 'CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE=InnoDB'
            : null;

        $this->createTable($tableName, [
            'id'             => $this->bigPrimaryKey(),
            'cms_job_run_id' => $this->bigInteger()->notNull(),
            'level'          => $this->string(16)->notNull()->defaultValue('info')->comment("debug|info|warning|error"),
            'stage'          => $this->string(64)->null(),
            'message'        => $this->text()->notNull(),
            'context_json'   => $this->text()->null(),
            'created_at'     => $this->integer()->notNull(),
        ], $tableOptions);

        $this->createIndex('cms_job_run_event__run', $tableName, ['cms_job_run_id', 'id']);

        $this->addForeignKey(
            'cms_job_run_event__cms_job_run_id',
            $tableName,
            'cms_job_run_id',
            '{{%cms_job_run}}',
            'id',
            'CASCADE',
            'CASCADE'
        );

        $this->addCommentOnTable(
            $tableName,
            'Этапы и агрегаты выполнения; события на каждый элемент сюда не пишутся'
        );
    }

    public function safeDown()
    {
        $this->dropTable('{{%cms_job_run_event}}');

        return true;
    }
}
