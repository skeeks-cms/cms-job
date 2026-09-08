<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 * @author Semenov Alexander <semenov@skeeks.com>
 */

use yii\db\Migration;

/**
 * Транспортная таблица DB-драйвера yii2-queue.
 *
 * Схема собрана в один шаг из финального состояния драйвера версии 2.3.x. В
 * самом пакете она получается наложением пяти миграций, причём первая из них
 * (`M161119140200Queue`) описывает давно устаревший вид с колонками
 * `created_at`/`started_at`/`finished_at`; воспроизводить её бессмысленно.
 *
 * Отличия от миграций пакета, сделанные осознанно:
 *
 *  - имя таблицы с префиксом CMS, а не безымянное `queue`: в базе проекта уже
 *    больше двухсот таблиц, и общее слово заняли бы рано или поздно;
 *  - `bigint` для идентификатора: через полосу уведомлений и почты проходит
 *    поток технических сообщений, а `int` со временем упирается в потолок;
 *  - составной индекс под фактический запрос резервирования вместо трёх
 *    одиночных. `reserve()` отбирает по `channel` и `reserved_at IS NULL`,
 *    затем сортирует по `priority, id`; три отдельных индекса такой запрос не
 *    покрывают.
 *
 * Таблица не является историей выполнения. Бизнес-состояние операции живёт в
 * `cms_job_run`, а здесь лежит только техническое сообщение с номером запуска,
 * и после успешной обработки драйвер его удаляет.
 */
class m260823_091000__create_table__cms_queue extends Migration
{
    public function safeUp()
    {
        $table = '{{%cms_queue}}';

        if ($this->db->getTableSchema($table, true)) {
            throw new \RuntimeException(
                'cms_queue already exists but this migration is not recorded. '
                .'Inspect its schema, contents and migration history before continuing; '
                .'cms-job will not silently adopt an unknown transport table.'
            );
        }

        $tableOptions = null;
        if ($this->db->driverName === 'mysql') {
            $tableOptions = 'CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE=InnoDB';
        }

        $this->createTable($table, [
            'id' => $this->bigPrimaryKey(),

            'channel' => $this->string(64)->notNull()->comment('Имя полосы'),
            'job' => 'LONGBLOB NOT NULL',

            'pushed_at' => $this->integer()->notNull(),
            'ttr' => $this->integer()->notNull()->comment('Через сколько секунд вернуть невыполненное сообщение'),
            'delay' => $this->integer()->notNull()->defaultValue(0),
            'priority' => $this->integer()->unsigned()->notNull()->defaultValue(1024),

            'reserved_at' => $this->integer(),
            'attempt' => $this->integer(),
            'done_at' => $this->integer(),
        ], $tableOptions);

        // Основной запрос резервирования.
        $this->createIndex('cms_queue__reserve', $table, ['channel', 'reserved_at', 'priority', 'id']);
        // Возврат сообщений с истёкшим TTR.
        $this->createIndex('cms_queue__expired', $table, ['reserved_at', 'done_at']);

        $this->execute("ALTER TABLE {$this->realTableName($table)} COMMENT = 'Transport queue for cms-job';");
    }

    public function safeDown()
    {
        $table = '{{%cms_queue}}';

        if (!$this->db->getTableSchema($table, true)) {
            return true;
        }

        $this->dropTable($table);

        return true;
    }

    /**
     * @return string
     */
    protected function realTableName($table)
    {
        return $this->db->quoteTableName($this->db->schema->getRawTableName($table));
    }
}
