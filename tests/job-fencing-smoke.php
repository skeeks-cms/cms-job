<?php
/**
 * Защита запуска от чужой записи (fencing) и безопасная повторная доставка.
 *
 * Транспорт здесь не нужен: проверяется доменный слой, который переживёт его
 * замену. Запуски создаются напрямую, минуя публикацию.
 *
 * Запуск из корня проекта:
 *   php vendor/skeeks/cms-job/tests/job-fencing-smoke.php
 *
 * Тест работает с реальной базой, но трогает только собственные записи:
 * все они помечены уникальным correlation_id этого прогона.
 */

use skeeks\cms\job\JobTypeDefinition;
use skeeks\cms\job\models\CmsJobLock;
use skeeks\cms\job\models\CmsJobRun;
use skeeks\cms\job\runtime\CmsJobRunner;

$root = getenv('SKEEKS_APP_ROOT') ?: '/app';

define('ROOT_DIR', $root);
define('YII_ENV', 'dev');
define('YII_DEBUG', true);

defined('STDIN') or define('STDIN', fopen('php://stdin', 'r'));
defined('STDOUT') or define('STDOUT', fopen('php://stdout', 'w'));

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

$createApplication = require __DIR__.'/fixtures/test-application.php';
$application = $createApplication(
    $config === null ? [] : ($config->has('console-'.ENV) ? $config->get('console-'.ENV) : $config->get('console'))
);

// Метка этого прогона: удалять будем строго по ней.
$CORRELATION = 'fencing-test-'.getmypid().'-'.time();

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
 * Обработчик, поведение которого задаётся payload.
 */
class FencingHandler extends \skeeks\cms\job\handlers\AbstractJobHandler
{
    public static $observed = [];

    public function run(
        \skeeks\cms\job\runtime\JobContext $context,
        \skeeks\cms\job\contracts\JobReporterInterface $reporter
    ): void {
        $run = $context->getRun();
        self::$observed[] = (int)$run->id;

        $reporter->setStage('work');

        // Имитация перехвата: пока обработчик «работает», запуск отбирают.
        if ($context->get('steal')) {
            CmsJobRun::updateAll(
                ['execution_token' => 'stolen-by-another-worker'],
                ['id' => $run->id]
            );
        }

        $reporter->advance();
        $reporter->countSuccess();
        $reporter->flush(true);

        if ($context->get('fail')) {
            throw new \RuntimeException('Специально уроненное задание');
        }
    }
}

// Доменный слой проверяется без транспорта. Публикатор нужен и хранилищу:
// возврат запуска в очередь сам создаёт новое сообщение.
$publisher = new \skeeks\cms\job\transport\CollectingPublisher();
Yii::$app->set('jobPublisher', $publisher);
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

$registry = Yii::$app->jobs->getRegistry();
$store = Yii::$app->jobRunStore;
$locks = Yii::$app->jobLockManager;

$registry->add(new JobTypeDefinition([
    'type' => 'fencing.plain',
    'handler' => FencingHandler::class,
    'queue' => 'default',
    'leaseSeconds' => 60,
]));

$registry->add(new JobTypeDefinition([
    'type' => 'fencing.locked',
    'handler' => FencingHandler::class,
    'queue' => 'default',
    'leaseSeconds' => 60,
    'resourceKey' => function () {
        return 'fencing:resource';
    },
]));

$registry->add(new JobTypeDefinition([
    'type' => 'fencing.idempotent',
    'handler' => FencingHandler::class,
    'queue' => 'default',
    'leaseSeconds' => 60,
    'idempotent' => true,
    'maxAttempts' => 3,
]));

$registry->add(new JobTypeDefinition([
    'type' => 'fencing.unsafe',
    'handler' => FencingHandler::class,
    'queue' => 'default',
    'leaseSeconds' => 60,
    'idempotent' => false,
    'maxAttempts' => 3,
]));

/**
 * Создать запуск напрямую, без публикации в транспорт.
 */
function makeRun($type, array $payload = [], array $attributes = [])
{
    global $CORRELATION, $registry;

    $definition = $registry->get($type);

    $run = new CmsJobRun();
    $run->job_type = $type;
    $run->queue_name = $definition->queue;
    $run->max_attempts = $definition->maxAttempts;
    $run->correlation_id = $CORRELATION;
    $run->status = CmsJobRun::STATUS_QUEUED;
    $run->available_at = time();
    $run->setPayload($payload);

    if ($definition->resourceKey) {
        $run->resource_key = call_user_func($definition->resourceKey, $payload, $run);
    }

    foreach ($attributes as $k => $v) {
        $run->{$k} = $v;
    }

    if (!$run->save()) {
        throw new RuntimeException('Не удалось создать запуск: '.print_r($run->errors, true));
    }

    $run->updateAttributes(['root_id' => $run->id]);

    return $run;
}

$runner = new CmsJobRunner(['workerId' => 'worker-A']);
$runnerB = new CmsJobRunner(['workerId' => 'worker-B']);

echo "\n1. Захват выдаёт маркер владения\n";

$run = makeRun('fencing.plain');
$token = $store->claim((int)$run->id, 'worker-A', 60);

jobExpect($token !== null, 'первый захват удался');
jobExpect(strlen((string)$token) === 32, 'маркер имеет ожидаемую длину');

$second = $store->claim((int)$run->id, 'worker-B', 60);
jobExpect($second === null, 'повторный захват того же запуска отклонён');

$run->refresh();
jobExpect($run->status === CmsJobRun::STATUS_RUNNING, 'запуск помечен работающим');
jobExpect((int)$run->attempt === 1, 'попытка учтена ровно один раз');

echo "\n2. Чужой маркер не может писать\n";

$stale = 'stale-token-000000000000000000000';

jobExpect(
    $store->writeProgress((int)$run->id, $stale, ['progress_current' => 999], 60) === false,
    'прогресс под чужим маркером не записан'
);
jobExpect(
    $store->extendLease((int)$run->id, $stale, 600) === false,
    'аренда под чужим маркером не продлена'
);
jobExpect(
    $store->finish((int)$run->id, $stale, ['status' => CmsJobRun::STATUS_SUCCEEDED]) === false,
    'завершить чужой запуск нельзя'
);
jobExpect(
    $store->requeue((int)$run->id, $stale, $run->queue_name) === false,
    'вернуть чужой запуск в очередь нельзя'
);
jobExpect(
    count($publisher->all()) === 0,
    'отказанный возврат в очередь не публикует сообщение'
);

$run->refresh();
jobExpect((int)$run->progress_current === 0, 'значения запуска не изменились');
jobExpect($run->status === CmsJobRun::STATUS_RUNNING, 'статус не изменился');

jobExpect(
    $store->writeProgress((int)$run->id, (string)$token, ['progress_current' => 5], 60) === true,
    'владелец пишет прогресс успешно'
);

$store->finish((int)$run->id, (string)$token, ['status' => CmsJobRun::STATUS_SUCCEEDED]);

echo "\n3. Обработчик останавливается, если запуск перехватили\n";

FencingHandler::$observed = [];
$stolen = makeRun('fencing.plain', ['steal' => true]);
$outcome = $runner->execute($stolen->id);

jobExpect($outcome === CmsJobRunner::OUTCOME_FENCED, 'исход помечен как перехват');

$stolen->refresh();
jobExpect(
    $stolen->status === CmsJobRun::STATUS_RUNNING,
    'прежний владелец не завершил чужой запуск'
);
jobExpect(
    $stolen->execution_token === 'stolen-by-another-worker',
    'маркер нового владельца не затёрт'
);

echo "\n4. Блокировка ресурса продлевается вместе с арендой\n";

$locked = makeRun('fencing.locked');
$lockToken = $store->claim((int)$locked->id, 'worker-A', 60);
$locks->acquire('fencing:resource', (int)$locked->id, $lockToken, 'worker-A', 5);

$lockRow = CmsJobLock::findOne(['resource_key' => 'fencing:resource']);
jobExpect($lockRow !== null, 'блокировка захвачена');
$before = (int)$lockRow->expires_at;

jobExpect(
    $locks->extend('fencing:resource', $lockToken, 600) === true,
    'владелец продлевает свою блокировку'
);
jobExpect(
    $locks->extend('fencing:resource', 'foreign-token', 600) === false,
    'чужую блокировку продлить нельзя'
);

$lockRow->refresh();
jobExpect((int)$lockRow->expires_at > $before, 'срок блокировки увеличился');

$locks->release('fencing:resource', 'foreign-token');
jobExpect(
    CmsJobLock::find()->where(['resource_key' => 'fencing:resource'])->exists(),
    'чужой процесс не снял блокировку'
);

$locks->release('fencing:resource', $lockToken);
jobExpect(
    !CmsJobLock::find()->where(['resource_key' => 'fencing:resource'])->exists(),
    'владелец снял свою блокировку'
);

$store->finish((int)$locked->id, (string)$lockToken, ['status' => CmsJobRun::STATUS_CANCELLED]);

echo "\n5. Повторная доставка: запуск уже завершён\n";

$done = makeRun('fencing.plain', [], ['status' => CmsJobRun::STATUS_SUCCEEDED, 'finished_at' => time()]);
FencingHandler::$observed = [];

jobExpect(
    $runner->execute($done->id) === CmsJobRunner::OUTCOME_ALREADY_FINISHED,
    'сообщение по завершённому запуску подтверждается без работы'
);
jobExpect(FencingHandler::$observed === [], 'обработчик не вызывался');

echo "\n6. Повторная доставка: работает другой воркер\n";

$busy = makeRun('fencing.plain');
$busyToken = $store->claim((int)$busy->id, 'worker-A', 300);
FencingHandler::$observed = [];

jobExpect(
    $runnerB->execute($busy->id) === CmsJobRunner::OUTCOME_OWNED_BY_OTHER,
    'второй воркер не берётся за живой запуск'
);
jobExpect(FencingHandler::$observed === [], 'обработчик не вызывался повторно');

$store->finish((int)$busy->id, (string)$busyToken, ['status' => CmsJobRun::STATUS_CANCELLED]);

echo "\n7. Истёкшая аренда: идемпотентное перезапускается\n";

$idem = makeRun('fencing.idempotent');
$idemToken = $store->claim((int)$idem->id, 'worker-A', 60);
// Проматываем аренду.
CmsJobRun::updateAll(['lease_until' => time() - 10], ['id' => $idem->id]);
FencingHandler::$observed = [];

$outcome = $runnerB->execute($idem->id);
$idem->refresh();

jobExpect($outcome === CmsJobRunner::OUTCOME_EXECUTED, 'идемпотентный запуск перезахвачен и выполнен');
jobExpect(FencingHandler::$observed === [(int)$idem->id], 'обработчик отработал один раз');
jobExpect($idem->status === CmsJobRun::STATUS_SUCCEEDED, 'итог сохранён');
jobExpect((int)$idem->attempt === 2, 'перезахват израсходовал вторую попытку');

echo "\n8. Истёкшая аренда: неидемпотентное не повторяется\n";

$unsafe = makeRun('fencing.unsafe');
$unsafeToken = $store->claim((int)$unsafe->id, 'worker-A', 60);
CmsJobRun::updateAll(['lease_until' => time() - 10], ['id' => $unsafe->id]);
FencingHandler::$observed = [];

$outcome = $runnerB->execute($unsafe->id);
$unsafe->refresh();

jobExpect($outcome === CmsJobRunner::OUTCOME_EXECUTED, 'сообщение обработано');
jobExpect(FencingHandler::$observed === [], 'обработчик НЕ вызван: результат прошлой попытки неизвестен');
jobExpect($unsafe->status === CmsJobRun::STATUS_TIMED_OUT, 'запуск помечен timed_out');
jobExpect($unsafe->error_code === 'lease_expired', 'указана причина');

echo "\n9. Уборка просроченных аренд атомарна и учитывает идемпотентность\n";

$reapIdem = makeRun('fencing.idempotent');
$reapUnsafe = makeRun('fencing.unsafe');
$t1 = $store->claim((int)$reapIdem->id, 'worker-dead', 60);
$t2 = $store->claim((int)$reapUnsafe->id, 'worker-dead', 60);
CmsJobRun::updateAll(['lease_until' => time() - 10], ['id' => [$reapIdem->id, $reapUnsafe->id]]);

$result = $store->reapExpired(function (CmsJobRun $r) use ($registry) {
    return $registry->get($r->job_type)->idempotent;
});

$reapIdem->refresh();
$reapUnsafe->refresh();

jobExpect($reapIdem->status === CmsJobRun::STATUS_QUEUED, 'идемпотентный возвращён в очередь');
jobExpect($reapIdem->execution_token === null, 'маркер снят при возврате');
jobExpect($reapUnsafe->status === CmsJobRun::STATUS_TIMED_OUT, 'неидемпотентный помечен timed_out');
jobExpect($result['requeued'] >= 1 && $result['timed_out'] >= 1, 'счётчики уборки заполнены');

echo "\n10. Отмена запуска, ожидающего в очереди\n";

$cancelled = makeRun('fencing.plain', [], ['cancel_requested_at' => time()]);
FencingHandler::$observed = [];

$outcome = $runner->execute($cancelled->id);
$cancelled->refresh();

jobExpect($outcome === CmsJobRunner::OUTCOME_CANCELLED, 'исход — отмена');
jobExpect(FencingHandler::$observed === [], 'обработчик не запускался');
jobExpect($cancelled->status === CmsJobRun::STATUS_CANCELLED, 'статус отменён');

echo "\n11. Отложенный запуск не выполняется раньше срока\n";

$later = makeRun('fencing.plain', [], ['available_at' => time() + 600]);
FencingHandler::$observed = [];

jobExpect(
    $runner->execute($later->id) === CmsJobRunner::OUTCOME_DEFERRED,
    'сообщение, пришедшее раньше срока, откладывается'
);
jobExpect(FencingHandler::$observed === [], 'обработчик не вызывался');

$later->refresh();
jobExpect($later->status === CmsJobRun::STATUS_QUEUED, 'запуск остался в очереди');

echo "\n12. Несуществующий запуск подтверждается без ошибки\n";

jobExpect(
    $runner->execute(2147483000) === CmsJobRunner::OUTCOME_MISSING,
    'сообщение по удалённому запуску не роняет воркера'
);

// Уборка: только записи этого прогона.
$ids = CmsJobRun::find()->select('id')->where(['correlation_id' => $CORRELATION])->column();
if ($ids) {
    CmsJobLock::deleteAll(['cms_job_run_id' => $ids]);
    CmsJobRun::deleteAll(['id' => $ids]);
}

echo "\n";
if ($failures) {
    echo 'ПРОВАЛЕНО: '.count($failures).' из '.$checks."\n";
    exit(1);
}

echo "Fencing и повторная доставка: {$checks} проверок, все пройдены\n";
exit(0);
