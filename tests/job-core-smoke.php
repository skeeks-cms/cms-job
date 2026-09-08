<?php
/**
 * Дымовой тест ядра фоновых заданий.
 *
 * Запуск из корня проекта:
 *   php vendor/skeeks/cms-job/tests/job-core-smoke.php
 *
 * Тест работает с реальной базой: создаёт и удаляет собственные прогоны
 * заданий, чужих данных не трогает.
 */

use skeeks\cms\job\JobTypeDefinition;
use skeeks\cms\job\models\CmsJobLock;
use skeeks\cms\job\models\CmsJobRun;
use skeeks\cms\job\models\CmsJobRunEvent;

$root = getenv('SKEEKS_APP_ROOT') ?: '/app';

define('ROOT_DIR', $root);
define('YII_ENV', 'dev');
define('YII_DEBUG', true);

defined('STDIN') or define('STDIN', fopen('php://stdin', 'r'));
defined('STDOUT') or define('STDOUT', fopen('php://stdout', 'w'));

// Повторяем bootstrap из vendor/skeeks/cms/app-console.php, но без run():
// приложение нужно поднять, а не запустить обработку команды.
require_once $root.'/vendor/skeeks/cms/bootstrap.php';

$config = getenv('SKEEKS_JOB_RELEASE_DB') !== false ? null : new \Yiisoft\Config\Config(
    new \Yiisoft\Config\ConfigPaths($root, 'config'),
    null,
    [
        \Yiisoft\Config\Modifier\RecursiveMerge::groups(
            'console',
            'console-'.ENV,
            'params',
            'params-console-'.ENV
        ),
    ],
    'params-console-'.ENV
);

$configData = $config === null ? [] : ($config->has('console-'.ENV) ? $config->get('console-'.ENV) : $config->get('console'));

$createApplication = require __DIR__.'/fixtures/test-application.php';
$application = $createApplication($configData);

$failures = [];
$checks = 0;

function jobExpect($condition, $message)
{
    global $failures, $checks;

    $checks++;
    if (!$condition) {
        $failures[] = $message;
        echo "  FAIL  {$message}\n";
    } else {
        echo "  ok    {$message}\n";
    }
}

/**
 * Обработчик-заглушка: поведение задаётся через payload.
 */
class SmokeHandler extends \skeeks\cms\job\handlers\AbstractJobHandler
{
    public static $sideEffects = [];

    public function run(
        \skeeks\cms\job\runtime\JobContext $context,
        \skeeks\cms\job\contracts\JobReporterInterface $reporter
    ): void {
        self::$sideEffects[] = $context->getRun()->id;

        $reporter->setStage('work', 'Работаем');
        $reporter->setTotal(3);

        foreach ([1, 2, 3] as $i) {
            $reporter->advance();
            $reporter->countSuccess();
        }

        if ($context->get('warn')) {
            $reporter->itemError('demo', 42, 'Элемент не обработан');
        }

        if ($context->get('fail')) {
            throw new \RuntimeException('Специально уроненное задание');
        }

        $reporter->setResult(['ok' => true]);
    }
}

$registry = Yii::$app->jobs->getRegistry();

$registry->add(new JobTypeDefinition([
    'type' => 'smoke.plain',
    'handler' => SmokeHandler::class,
    'title' => 'Дымовой тест',
    'queue' => 'smoke',
]));

$registry->add(new JobTypeDefinition([
    'type' => 'smoke.dedup',
    'handler' => SmokeHandler::class,
    'queue' => 'smoke',
    'overlapPolicy' => CmsJobRun::OVERLAP_SKIP,
    'dedupKey' => function () {
        return 'smoke:dedup';
    },
]));

$registry->add(new JobTypeDefinition([
    'type' => 'smoke.resource',
    'handler' => SmokeHandler::class,
    'queue' => 'smoke',
    'resourceKey' => function () {
        return 'smoke:resource';
    },
]));

$registry->add(new JobTypeDefinition([
    'type' => 'smoke.retry',
    'handler' => SmokeHandler::class,
    'queue' => 'smoke',
    'maxAttempts' => 3,
    'idempotent' => true,
]));

$registry->add(new JobTypeDefinition([
    'type' => 'smoke.transient',
    'handler' => SmokeHandler::class,
    'queue' => 'smoke',
    'visibility' => CmsJobRun::VISIBILITY_TRANSIENT,
]));

// Доменный слой проверяется без транспорта: публикатор складывает сообщения
// в память, а мы сами решаем, когда их доставить. Интеграция с yii2-queue
// проверяется отдельным тестом job-transport-integration.php.
$publisher = new \skeeks\cms\job\transport\CollectingPublisher();
Yii::$app->set('jobPublisher', $publisher);

// Публикатор нужен и хранилищу: возврат запуска в очередь сам создаёт новое
// сообщение, иначе строка осталась бы ожидающей и никем не разбуженной.
Yii::$app->set('jobRunStore', [
    'class' => \skeeks\cms\job\runtime\JobRunStore::class,
    'publisher' => $publisher,
]);
Yii::$app->set('jobs', [
    'class' => \skeeks\cms\job\CmsJobComponent::class,
    'publisher' => $publisher,
    'store' => Yii::$app->get('jobRunStore'),
]);
Yii::$app->set('jobRunner', [
    'class' => \skeeks\cms\job\runtime\CmsJobRunner::class,
    'store' => Yii::$app->get('jobRunStore'),
]);

$runner = Yii::$app->jobRunner;
$worker = 'smoke:'.getmypid();

// Прерванный прогон мог оставить блокировку своего ресурса. Ключи `smoke:*`
// принадлежат только этому тесту, поэтому снять их безопасно, и без этого
// следующий запуск падал бы на чужом остатке, а не на реальном дефекте.
CmsJobLock::deleteAll(['like', 'resource_key', 'smoke:', false]);

$claimAndRun = function () use ($publisher, $runner) {
    $ids = $publisher->drain(['smoke']);
    foreach ($ids as $id) {
        $runner->execute($id);
    }

    return $ids;
};

echo "\n1. Успешное задание и счётчики\n";

$run = Yii::$app->jobs->push('smoke.plain', []);
jobExpect($run !== null, 'push() вернул прогон');
jobExpect($run->status === CmsJobRun::STATUS_QUEUED, 'новый прогон в статусе queued');
jobExpect($run->root_id === $run->id, 'root_id корневого прогона равен его id');

$claimAndRun();
$run->refresh();

jobExpect($run->status === CmsJobRun::STATUS_SUCCEEDED, 'успешное задание получает succeeded');
jobExpect((int)$run->success_count === 3, 'счётчик успехов сохранён: '.$run->success_count);
jobExpect((int)$run->progress_current === 3, 'прогресс сохранён: '.$run->progress_current);
jobExpect($run->getProgressPercent() === 100.0, 'процент вычисляется, а не хранится');
jobExpect($run->attempt === 1, 'израсходована ровно одна попытка');
jobExpect($run->finished_at > 0, 'проставлено время завершения');
jobExpect($run->dedup_active === null, 'ключ дедупликации снят при завершении');
jobExpect($run->getResult() === ['ok' => true], 'результат сохранён');

echo "\n2. Бизнес-результат отличается от кода возврата\n";

$run2 = Yii::$app->jobs->push('smoke.plain', ['warn' => true]);
$claimAndRun();
$run2->refresh();

jobExpect(
    $run2->status === CmsJobRun::STATUS_SUCCEEDED_WITH_WARNINGS,
    'ошибка элемента даёт succeeded_with_warnings, а не succeeded'
);
jobExpect((int)$run2->error_count === 1, 'ошибка элемента посчитана');

$artifacts = $run2->artifacts;
jobExpect(count($artifacts) === 1, 'создан ровно один артефакт с отчётом об ошибках');
jobExpect(
    $artifacts && $artifacts[0]->type === 'error-report',
    'артефакт помечен как отчёт об ошибках'
);

echo "\n3. Политика пересечения skip\n";

$first = Yii::$app->jobs->push('smoke.dedup', []);
$second = Yii::$app->jobs->push('smoke.dedup', []);

jobExpect($first !== null, 'первая постановка принята');
jobExpect($second === null, 'вторая постановка отброшена политикой skip');

$first->refresh();
jobExpect((int)$first->skipped_runs === 1, 'отброшенная постановка зафиксирована счётчиком');

$claimAndRun();
$first->refresh();

$third = Yii::$app->jobs->push('smoke.dedup', []);
jobExpect($third !== null, 'после завершения то же задание можно поставить снова');
$claimAndRun();

echo "\n4. Блокировка ресурса\n";

$a = Yii::$app->jobs->push('smoke.resource', []);
$b = Yii::$app->jobs->push('smoke.resource', []);

jobExpect($a && $b, 'оба задания по одному ресурсу поставлены');

$ids = $publisher->drain(['smoke']);
jobExpect(count($ids) === 2, 'опубликованы оба сообщения');

$runner->execute($ids[0]);
$a->refresh();
jobExpect($a->status === CmsJobRun::STATUS_SUCCEEDED, 'первое задание по ресурсу выполнено');

jobExpect(
    CmsJobLock::find()->where(['resource_key' => 'smoke:resource'])->count() == 0,
    'блокировка освобождена после завершения'
);

// Второе задание должно было отработать так же, но уже после освобождения.
$runner->execute($ids[1]);
$b->refresh();
jobExpect($b->status === CmsJobRun::STATUS_SUCCEEDED, 'второе задание по тому же ресурсу выполнено следом');

echo "\n5. Ресурс занят чужой блокировкой\n";

$c = Yii::$app->jobs->push('smoke.resource', []);
$ids = $publisher->drain(['smoke']);

// Занимаем ресурс от имени постороннего процесса.
Yii::$app->db->createCommand()->insert(CmsJobLock::tableName(), [
    'resource_key' => 'smoke:resource',
    'cms_job_run_id' => $c->id,
    'execution_token' => 'foreign-worker-token',
    'worker_id' => 'other',
    'acquired_at' => time(),
    'expires_at' => time() + 600,
])->execute();

$runner->execute($ids[0]);
$c->refresh();

jobExpect(
    $c->status === CmsJobRun::STATUS_QUEUED,
    'при занятом ресурсе задание возвращается в очередь, а не падает'
);
jobExpect((int)$c->attempt === 0, 'занятый ресурс не расходует попытку');

CmsJobLock::deleteAll(['resource_key' => 'smoke:resource']);
Yii::$app->jobs->cancel($c);

echo "\n6. Повторы и окончательный отказ\n";

$failing = Yii::$app->jobs->push('smoke.retry', ['fail' => true]);
$claimAndRun();
$failing->refresh();

jobExpect($failing->status === CmsJobRun::STATUS_QUEUED, 'после первого отказа задание ждёт повтора');
jobExpect((int)$failing->attempt === 1, 'израсходована первая попытка');
jobExpect($failing->available_at > time(), 'повтор отложен на время backoff');
jobExpect($failing->error_code === 'transient', 'отказ классифицирован как временный');

// Проматываем backoff, чтобы не ждать: и в строке запуска, и в отложенном
// сообщении, которое повтор поставил заново.
$rewind = function () use ($failing, $publisher, $runner) {
    CmsJobRun::updateAll(['available_at' => time() - 1], ['id' => $failing->id]);

    foreach ($publisher->drain(['smoke'], false) as $id) {
        $runner->execute($id);
    }
};

$rewind();
$rewind();
$failing->refresh();

jobExpect($failing->status === CmsJobRun::STATUS_FAILED, 'после исчерпания попыток статус failed');
jobExpect((int)$failing->attempt === 3, 'израсходованы все три попытки');

echo "\n7. Неидемпотентный отказ не повторяется\n";

$once = Yii::$app->jobs->push('smoke.plain', ['fail' => true], ['maxAttempts' => 5]);
$claimAndRun();
$once->refresh();

jobExpect(
    $once->status === CmsJobRun::STATUS_FAILED,
    'неизвестное исключение в неидемпотентном типе не повторяется'
);
jobExpect($once->error_code === 'permanent', 'отказ классифицирован как постоянный');
jobExpect((int)$once->attempt === 1, 'лимит попыток не израсходован впустую');

echo "\n8. Эфемерное задание не оставляет следа\n";

$transient = Yii::$app->jobs->push('smoke.transient', []);
$transientId = $transient->id;
$claimAndRun();

jobExpect(
    CmsJobRun::findOne($transientId) === null,
    'успешное техническое сообщение удаляется из журнала'
);
jobExpect(
    CmsJobRunEvent::find()->where(['cms_job_run_id' => $transientId])->count() == 0,
    'события эфемерного задания удалены каскадом'
);

echo "\n9. Отмена\n";

$cancelled = Yii::$app->jobs->push('smoke.plain', []);
jobExpect(Yii::$app->jobs->cancel($cancelled), 'отмена ожидающего задания принята');
$cancelled->refresh();
jobExpect($cancelled->status === CmsJobRun::STATUS_CANCELLED, 'ожидающее задание отменяется сразу');
jobExpect($cancelled->dedup_active === null, 'ключ дедупликации снят при отмене');

echo "\n10. Ручной перезапуск — отдельная запись\n";

$retried = Yii::$app->jobs->retry($failing);
jobExpect($retried !== null && $retried->id !== $failing->id, 'перезапуск создаёт новый прогон');
jobExpect((int)$retried->retry_of_id === (int)$failing->id, 'новый прогон ссылается на исходный');
jobExpect((int)$retried->attempt === 0, 'у нового прогона своя история попыток');
Yii::$app->jobs->cancel($retried);

// Уборка за собой.
$ids = CmsJobRun::find()
    ->select('id')
    ->where(['like', 'job_type', 'smoke.%', false])
    ->column();
if ($ids) {
    CmsJobRun::deleteAll(['id' => $ids]);
}

echo "\n";
if ($failures) {
    echo 'ПРОВАЛЕНО: '.count($failures).' из '.$checks."\n";
    exit(1);
}

echo "Ядро фоновых заданий: {$checks} проверок, все пройдены\n";
exit(0);
