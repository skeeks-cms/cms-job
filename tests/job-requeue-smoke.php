<?php
/**
 * Возврат запуска в очередь: ровно одно сообщение на каждый переход.
 *
 * Проверяется главный инвариант доставки: любой переход running → queued
 * обязан создать ровно одно новое транспортное сообщение. Ноль сообщений —
 * запуск ожидает вечно и никем не разбужен; два — операция выполнится дважды.
 *
 * Запуск из корня проекта:
 *   php vendor/skeeks/cms-job/tests/job-requeue-smoke.php
 *
 * Транспорт здесь подменён CollectingPublisher: проверяется доменный слой.
 * Те же сценарии против настоящей таблицы cms_queue — в
 * job-transport-integration.php.
 */

use skeeks\cms\job\JobTypeDefinition;
use skeeks\cms\job\models\CmsJobLock;
use skeeks\cms\job\models\CmsJobRun;
use skeeks\cms\job\transport\CollectingPublisher;

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

$configData = $config === null ? [] : ($config->has('console-'.ENV) ? $config->get('console-'.ENV) : $config->get('console'));
$createApplication = require __DIR__.'/fixtures/test-application.php';
$application = $createApplication($configData);

// Свой идентификатор прогона: тест убирает только собственные записи и никогда
// не трогает чужие задания той же полосы или типа.
$correlationId = 'requeue-test-'.getmypid().'-'.random_int(1000, 9999);

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

$publisher = new CollectingPublisher();
\Yii::$app->set('jobPublisher', $publisher);
\Yii::$app->set('jobRunStore', [
    'class' => \skeeks\cms\job\runtime\JobRunStore::class,
    'publisher' => $publisher,
]);
\Yii::$app->set('jobRunner', [
    'class' => \skeeks\cms\job\runtime\CmsJobRunner::class,
    'store' => \Yii::$app->get('jobRunStore'),
]);
\Yii::$app->set('jobs', [
    'class' => \skeeks\cms\job\CmsJobComponent::class,
    'publisher' => $publisher,
    'store' => \Yii::$app->get('jobRunStore'),
]);

$store = \Yii::$app->get('jobRunStore');
$runner = \Yii::$app->get('jobRunner');
$registry = \Yii::$app->jobs->getRegistry();

/**
 * Поведение обработчика задаётся через payload.
 */
class RequeueHandler extends \skeeks\cms\job\handlers\AbstractJobHandler
{
    public function run(
        \skeeks\cms\job\runtime\JobContext $context,
        \skeeks\cms\job\contracts\JobReporterInterface $reporter
    ): void {
        if ($context->get('requeue')) {
            $e = new \skeeks\cms\job\exceptions\JobRequeueException('Курсор сохранён.');
            $e->delay = 42;

            throw $e;
        }

        if ($context->get('fail')) {
            throw new \skeeks\cms\job\exceptions\JobTransientException('Временный отказ.');
        }

        $reporter->countSuccess();
    }
}

$registry->add(new JobTypeDefinition([
    'type' => 'requeue.plain',
    'handler' => RequeueHandler::class,
    'queue' => 'requeue-lane',
    'maxAttempts' => 5,
    'idempotent' => true,
]));

$registry->add(new JobTypeDefinition([
    'type' => 'requeue.locked',
    'handler' => RequeueHandler::class,
    'queue' => 'requeue-lane',
    'overlapPolicy' => CmsJobRun::OVERLAP_QUEUE,
    'resourceKey' => function () {
        return 'requeue:resource';
    },
]));

$registry->add(new JobTypeDefinition([
    'type' => 'requeue.window',
    'handler' => RequeueHandler::class,
    'queue' => 'requeue-lane',
    // Окно заведомо закрыто: минута, которая уже прошла и ещё не наступит.
    'allowedWindow' => ['03:00', '03:01'],
]));

$push = function ($type, array $payload = [], array $options = []) use ($correlationId) {
    return \Yii::$app->jobs->push($type, $payload, array_merge([
        'correlationId' => $correlationId,
    ], $options));
};

/**
 * Сообщения по конкретному запуску среди опубликованных.
 */
$messagesFor = function ($runId) use ($publisher) {
    return array_values(array_filter($publisher->all(), function ($m) use ($runId) {
        return (int)$m['runId'] === (int)$runId;
    }));
};

/**
 * Общая проверка после перехода running → queued.
 */
$assertRequeued = function (CmsJobRun $run, $expectedDelay, $label) use ($messagesFor, $publisher) {
    $run->refresh();

    jobExpect($run->status === CmsJobRun::STATUS_QUEUED, "{$label}: статус queued");

    $messages = $messagesFor($run->id);
    jobExpect(
        count($messages) === 1,
        "{$label}: ровно одно сообщение (получено ".count($messages).')'
    );

    if (!$messages) {
        return;
    }

    $delay = (int)$messages[0]['delay'];
    $expectedAvailable = $messages[0]['availableAt'];

    if ($expectedDelay !== null) {
        jobExpect(
            abs($delay - $expectedDelay) <= 2,
            "{$label}: задержка сообщения {$delay} с соответствует ожидаемой {$expectedDelay} с"
        );
    }

    jobExpect(
        abs((int)$run->available_at - $expectedAvailable) <= 2,
        "{$label}: available_at запуска согласован с задержкой сообщения"
    );

    jobExpect(
        $messages[0]['queue'] === $run->queue_name,
        "{$label}: сообщение отправлено в полосу запуска"
    );
};

echo "\n1. Занятый resource_key\n";

$holder = $push('requeue.locked');
$blocked = $push('requeue.locked');

// Первый захватывает ресурс и остаётся работать.
$holderToken = $store->claim((int)$holder->id, 'w-holder', 300);
\Yii::$app->jobLockManager->acquire('requeue:resource', (int)$holder->id, $holderToken, 'w-holder', 300);

$publisher->clear();
$outcome = $runner->execute($blocked->id);

jobExpect($outcome === \skeeks\cms\job\runtime\CmsJobRunner::OUTCOME_DEFERRED, 'занятый ресурс откладывает запуск');
$assertRequeued($blocked, 15, 'занятый ресурс');

$blocked->refresh();
jobExpect((int)$blocked->attempt === 0, 'занятый ресурс не расходует попытку');

\Yii::$app->jobLockManager->release('requeue:resource', $holderToken);
$store->finish((int)$holder->id, $holderToken, [
    'status' => CmsJobRun::STATUS_SUCCEEDED,
    'finished_at' => time(),
    'dedup_active' => null,
]);

echo "\n2. Закрытое окно выполнения\n";

$windowed = $push('requeue.window');
$publisher->clear();
$outcome = $runner->execute($windowed->id);

jobExpect($outcome === \skeeks\cms\job\runtime\CmsJobRunner::OUTCOME_DEFERRED, 'закрытое окно откладывает запуск');
$assertRequeued($windowed, null, 'закрытое окно');

$windowed->refresh();
jobExpect((int)$windowed->attempt === 0, 'закрытое окно не расходует попытку');
jobExpect($windowed->available_at > time() + 30, 'запуск отложен до открытия окна');

echo "\n3. JobRequeueException — сохранён курсор\n";

$cursored = $push('requeue.plain', ['requeue' => true]);
$publisher->clear();
$outcome = $runner->execute($cursored->id);

jobExpect($outcome === \skeeks\cms\job\runtime\CmsJobRunner::OUTCOME_DEFERRED, 'сохранение курсора откладывает запуск');
$assertRequeued($cursored, 42, 'сохранённый курсор');

$cursored->refresh();
jobExpect((int)$cursored->attempt === 0, 'продолжение по курсору не расходует попытку');

echo "\n4. Бизнес-повтор после временного отказа\n";

$retried = $push('requeue.plain', ['fail' => true]);
$publisher->clear();
$runner->execute($retried->id);

$assertRequeued($retried, null, 'бизнес-повтор');

$retried->refresh();
jobExpect((int)$retried->attempt === 1, 'бизнес-повтор расходует попытку');
jobExpect($retried->error_code === 'transient', 'причина отказа сохранена');
jobExpect($retried->available_at > time(), 'повтор отложен на время backoff');

$messages = $messagesFor($retried->id);
jobExpect(
    $messages && abs((int)$messages[0]['delay'] - ((int)$retried->available_at - time())) <= 2,
    'задержка сообщения совпадает с available_at запуска'
);

echo "\n5. Восстановление уборкой\n";

$abandoned = $push('requeue.plain');
$abandonedToken = $store->claim((int)$abandoned->id, 'w-dead', 300);

// Изображаем упавший процесс: аренда истекла, владелец не вернётся.
CmsJobRun::updateAll(['lease_until' => time() - 60], ['id' => $abandoned->id]);

$publisher->clear();
$result = $store->reapExpired(function (CmsJobRun $run) use ($registry) {
    return $registry->has($run->job_type) && $registry->get($run->job_type)->idempotent;
});

jobExpect((int)$result['requeued'] === 1, 'уборка вернула запуск в очередь');
$assertRequeued($abandoned, 0, 'восстановление уборкой');

echo "\n6. Повторная уборка не создаёт дубликат\n";

$before = count($messagesFor($abandoned->id));
$second = $store->reapExpired(function (CmsJobRun $run) use ($registry) {
    return $registry->has($run->job_type) && $registry->get($run->job_type)->idempotent;
});
$after = count($messagesFor($abandoned->id));

jobExpect((int)$second['requeued'] === 0, 'вторая уборка ничего не возвращает');
jobExpect($before === $after, 'вторая уборка не публикует второе сообщение');

echo "\n7. Неидемпотентный запуск уборка не повторяет\n";

$registry->add(new JobTypeDefinition([
    'type' => 'requeue.unsafe',
    'handler' => RequeueHandler::class,
    'queue' => 'requeue-lane',
    'idempotent' => false,
    'maxAttempts' => 5,
]));

$unsafe = $push('requeue.unsafe');
$unsafeToken = $store->claim((int)$unsafe->id, 'w-dead', 300);
CmsJobRun::updateAll(['lease_until' => time() - 60], ['id' => $unsafe->id]);

$publisher->clear();
$result = $store->reapExpired(function (CmsJobRun $run) use ($registry) {
    return $registry->has($run->job_type) && $registry->get($run->job_type)->idempotent;
});

$unsafe->refresh();
jobExpect((int)$result['timed_out'] === 1, 'неидемпотентный запуск помечен timed_out');
jobExpect($unsafe->status === CmsJobRun::STATUS_TIMED_OUT, 'статус timed_out');
jobExpect(count($messagesFor($unsafe->id)) === 0, 'сообщение для него не публикуется');

echo "\n8. Перехваченный владелец ничего не публикует\n";

$stolen = $push('requeue.plain', ['fail' => true]);
$oldToken = $store->claim((int)$stolen->id, 'w-old', 300);

// Запуск перехвачен: маркер сменился.
CmsJobRun::updateAll(['execution_token' => 'someone-else'], ['id' => $stolen->id]);

$publisher->clear();
$requeued = $store->requeue((int)$stolen->id, (string)$oldToken, 'requeue-lane', 30);

jobExpect($requeued === false, 'перехваченный владелец получает отказ');
jobExpect(count($messagesFor($stolen->id)) === 0, 'перехваченный владелец не публикует сообщение');

$stolen->refresh();
jobExpect($stolen->status === CmsJobRun::STATUS_RUNNING, 'статус перехватившего не изменён');

echo "\n9. Гонка двух постановок с политикой skip\n";

$registry->add(new JobTypeDefinition([
    'type' => 'requeue.skip',
    'handler' => RequeueHandler::class,
    'queue' => 'requeue-lane',
    'overlapPolicy' => CmsJobRun::OVERLAP_SKIP,
    'resourceKey' => function () {
        return 'requeue:skip-resource';
    },
]));

$first = $push('requeue.skip');
jobExpect($first !== null, 'первая постановка принята');
jobExpect(
    $first->dedup_key === 'resource:requeue:skip-resource',
    'ключ дедупликации выведен из ресурса, гонку ловит индекс'
);

$secondPush = $push('requeue.skip');
jobExpect($secondPush === null, 'вторая постановка отброшена');

$first->refresh();
jobExpect((int)$first->skipped_runs === 1, 'пропуск зафиксирован счётчиком');

// Уборка за собой: только записи этого прогона.
$ids = CmsJobRun::find()
    ->select('id')
    ->andWhere(['correlation_id' => $correlationId])
    ->column();

if ($ids) {
    CmsJobLock::deleteAll(['cms_job_run_id' => $ids]);
    CmsJobRun::deleteAll(['id' => $ids]);
}

echo "\n";
if ($failures) {
    echo 'ПРОВАЛЕНО: '.count($failures).' из '.$checks."\n";
    exit(1);
}

echo "Возврат в очередь: {$checks} проверок, все пройдены\n";
exit(0);
