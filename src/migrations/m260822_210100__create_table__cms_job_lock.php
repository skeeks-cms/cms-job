<?php

use yii\db\Migration;

/**
 * Блокировка ресурса: сериализация конфликтующих заданий.
 */
class m260822_210100__create_table__cms_job_lock extends Migration
{
    public function safeUp()
    {
        $tableName = '{{%cms_job_lock}}';
        $tableOptions = $this->db->driverName === 'mysql'
            ? 'CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE=InnoDB'
            : null;

        // Первичный ключ по resource_key делает захват атомарным: конкурент
        // получает ошибку уникальности вместо гонки чтения и записи.
        $this->createTable($tableName, [
            'resource_key'   => $this->string(190)->notNull(),
            'cms_job_run_id' => $this->bigInteger()->notNull(),
            'worker_id'      => $this->string(64)->null(),
            'acquired_at'    => $this->integer()->notNull(),
            'expires_at'     => $this->integer()->notNull(),
        ], $tableOptions);

        $this->addPrimaryKey('cms_job_lock__pk', $tableName, 'resource_key');
        $this->createIndex('cms_job_lock__expires_at', $tableName, 'expires_at');
        $this->createIndex('cms_job_lock__run', $tableName, 'cms_job_run_id');

        $this->addForeignKey(
            'cms_job_lock__cms_job_run_id',
            $tableName,
            'cms_job_run_id',
            '{{%cms_job_run}}',
            'id',
            'CASCADE',
            'CASCADE'
        );

        $this->addCommentOnTable($tableName, 'Блокировки ресурсов фоновых заданий');
    }

    public function safeDown()
    {
        $this->dropTable('{{%cms_job_lock}}');

        return true;
    }
}
