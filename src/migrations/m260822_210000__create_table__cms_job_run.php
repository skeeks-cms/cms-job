<?php

use yii\db\Migration;

/**
 * Прогон фонового задания: одновременно сообщение очереди и журнал операции.
 */
class m260822_210000__create_table__cms_job_run extends Migration
{
    public function safeUp()
    {
        $tableName = '{{%cms_job_run}}';
        $tableOptions = $this->db->driverName === 'mysql'
            ? 'CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE=InnoDB'
            : null;

        $this->createTable($tableName, [
            'id'                  => $this->bigPrimaryKey(),
            'uid'                 => $this->char(36)->notNull()->comment("UUID для внешних ссылок и корреляции"),
            'cms_site_id'         => $this->integer()->null(),

            'job_type'            => $this->string(128)->notNull()->comment("Ключ типа в реестре"),
            'job_version'         => $this->smallInteger()->notNull()->defaultValue(1),
            'queue_name'          => $this->string(32)->notNull()->defaultValue('default'),
            'visibility'          => $this->string(16)->notNull()->defaultValue('visible')->comment("visible|transient"),
            'title'               => $this->string(255)->null(),

            'trigger_type'        => $this->string(16)->notNull()->defaultValue('manual')->comment("manual|schedule|event|api|system|child"),
            'trigger_ref'         => $this->string(190)->null()->comment("Например cms_agent:72; FK нет намеренно"),
            'created_by'          => $this->integer()->null(),
            'correlation_id'      => $this->string(64)->null(),
            'parent_id'           => $this->bigInteger()->null(),
            'root_id'             => $this->bigInteger()->null(),
            'retry_of_id'         => $this->bigInteger()->null()->comment("Ручной перезапуск: ссылка на исходный прогон"),

            'status'              => $this->string(24)->notNull()->defaultValue('queued'),
            'priority'            => $this->smallInteger()->notNull()->defaultValue(100)->comment("Меньше — раньше"),
            'attempt'             => $this->smallInteger()->notNull()->defaultValue(0),
            'max_attempts'        => $this->smallInteger()->notNull()->defaultValue(1),
            'available_at'        => $this->integer()->notNull(),
            'lease_until'         => $this->integer()->null(),
            'worker_id'           => $this->string(64)->null(),
            'worker_pid'          => $this->integer()->null(),
            'cancel_requested_at' => $this->integer()->null()->comment("Признак отмены, не статус"),
            'cancel_requested_by' => $this->integer()->null(),

            'dedup_key'           => $this->string(190)->null(),
            'dedup_active'        => $this->string(190)->null()->comment("= dedup_key пока активно, иначе NULL; уникален"),
            'resource_key'        => $this->string(190)->null(),
            'overlap_policy'      => $this->string(16)->notNull()->defaultValue('queue')->comment("skip|coalesce|queue|replace"),

            'stage'               => $this->string(64)->null(),
            'progress_current'    => $this->bigInteger()->notNull()->defaultValue(0),
            'progress_total'      => $this->bigInteger()->null(),
            'progress_message'    => $this->string(255)->null(),
            'success_count'       => $this->bigInteger()->notNull()->defaultValue(0),
            'warning_count'       => $this->bigInteger()->notNull()->defaultValue(0),
            'error_count'         => $this->bigInteger()->notNull()->defaultValue(0),
            'skipped_count'       => $this->bigInteger()->notNull()->defaultValue(0),
            'skipped_runs'        => $this->integer()->notNull()->defaultValue(0)->comment("Сколько постановок отброшено политикой skip"),

            'payload_json'        => $this->text()->null()->comment("Секреты только ссылкой на настройку"),
            'cursor_json'         => $this->text()->null(),
            'result_json'         => $this->text()->null(),
            'error_code'          => $this->string(64)->null(),
            'error_message'       => $this->text()->null(),

            'created_at'          => $this->integer()->notNull(),
            'updated_at'          => $this->integer()->notNull(),
            'started_at'          => $this->integer()->null(),
            'finished_at'         => $this->integer()->null(),
            'retention_until'     => $this->integer()->null(),
            'lock_version'        => $this->integer()->notNull()->defaultValue(0),
        ], $tableOptions);

        $this->createIndex('cms_job_run__uid', $tableName, 'uid', true);

        // Частичных уникальных индексов в MySQL нет, поэтому уникальность
        // среди незавершённых обеспечивается обнулением колонки при финише:
        // NULL в уникальном индексе не конфликтует.
        $this->createIndex('cms_job_run__dedup', $tableName, 'dedup_active', true);

        $this->createIndex('cms_job_run__claim', $tableName, ['status', 'queue_name', 'priority', 'id']);
        $this->createIndex('cms_job_run__available', $tableName, ['status', 'available_at']);
        $this->createIndex('cms_job_run__lease', $tableName, ['status', 'lease_until']);
        $this->createIndex('cms_job_run__resource', $tableName, ['resource_key', 'status']);
        $this->createIndex('cms_job_run__type_created', $tableName, ['job_type', 'created_at']);
        $this->createIndex('cms_job_run__user_created', $tableName, ['created_by', 'created_at']);
        $this->createIndex('cms_job_run__site_status', $tableName, ['cms_site_id', 'status']);
        $this->createIndex('cms_job_run__parent', $tableName, 'parent_id');
        $this->createIndex('cms_job_run__root', $tableName, 'root_id');
        $this->createIndex('cms_job_run__retention', $tableName, 'retention_until');

        $this->addForeignKey('cms_job_run__cms_site_id', $tableName, 'cms_site_id', '{{%cms_site}}', 'id', 'SET NULL', 'SET NULL');
        $this->addForeignKey('cms_job_run__created_by', $tableName, 'created_by', '{{%cms_user}}', 'id', 'SET NULL', 'SET NULL');
        $this->addForeignKey('cms_job_run__cancel_requested_by', $tableName, 'cancel_requested_by', '{{%cms_user}}', 'id', 'SET NULL', 'SET NULL');

        // Ссылки на себя — SET NULL, а не CASCADE: удаление родителя не должно
        // стирать историю потомков.
        $this->addForeignKey('cms_job_run__parent_id', $tableName, 'parent_id', '{{%cms_job_run}}', 'id', 'SET NULL', 'SET NULL');
        $this->addForeignKey('cms_job_run__root_id', $tableName, 'root_id', '{{%cms_job_run}}', 'id', 'SET NULL', 'SET NULL');
        $this->addForeignKey('cms_job_run__retry_of_id', $tableName, 'retry_of_id', '{{%cms_job_run}}', 'id', 'SET NULL', 'SET NULL');

        $this->addCommentOnTable($tableName, 'Фоновые задания: очередь и журнал выполнения');
    }

    public function safeDown()
    {
        $this->dropTable('{{%cms_job_run}}');

        return true;
    }
}
