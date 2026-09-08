<?php
/**
 * Интеграция с транспортом yii2-queue.
 *
 * ЗАБЛОКИРОВАН до штатной установки зависимости. Требует реально
 * установленного `yiisoft/yii2-queue:^2.3.8`:
 *
 *   composer update skeeks/cms-job --with-dependencies
 *
 * Без пакета тест сообщает о блокировке и завершается кодом 2, чтобы его
 * нельзя было принять за пройденный.
 *
 * Запуск из корня проекта:
 *   php vendor/skeeks/cms-job/tests/job-transport-integration.php
 *
 * Создаваемые записи помечены уникальным correlation_id. Потребление идёт
 * через обычные полосы: требуется пустая тестовая очередь без фоновых producers.
 */

use skeeks\cms\job\JobTypeDefinition;
use skeeks\cms\job\models\CmsJobRun;
use skeeks\cms\job\transport\JobTransportMessage;
use skeeks\cms\job\transport\WorkerOptions;

$root = getenv('SKEEKS_APP_ROOT') ?: '/app';

define('ROOT_DIR', $root);
define('YII_ENV', 'dev');
define('YII_DEBUG', true);

defined('STDIN') or define('STDIN', fopen('php://stdin', 'r'));
defined('STDOUT') or define('STDOUT', fopen('php://stdout', 'w'));

require_once $root.'/vendor/skeeks/cms/bootstrap.php';

if (!class_exists('yii\queue\db\Queue')) {
    fwrite(STDOUT, "ЗАБЛОКИРОВАН: пакет yiisoft/yii2-queue не установлен.\n");
    fwrite(STDOUT, "Выполните: composer update skeeks/cms-job --with-dependencies\n");
    fwrite(STDOUT, "Тест не выполнялся и не может считаться пройденным.\n");
    exit(2);
}

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

$CORRELATION = 'transport-test-'.getmypid().'-'.time();
$QUEUE_TABLE = Yii::$app->db->schema->getRawTableName('{{%cms_queue}}');

if ((new yii\db\Query())->from($QUEUE_TABLE)->where(['done_at' => null])->exists()) {
    throw new RuntimeException('Транспортный тест требует пустой тестовой очереди.');
}

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
 * Сообщения только этого прогона.
 */
function ownMessages($channel = null)
{
    global $QUEUE_TABLE, $CORRELATION;

    $ids = CmsJobRun::find()->select('id')->where(['correlation_id' => $CORRELATION])->column();

    if (!$ids) {
        return [];
    }

    $query = (new yii\db\Query())->from($QUEUE_TABLE);

    if ($channel !== null) {
        $query->andWhere(['channel' => $channel]);
    }

    $rows = $query->all();
    $mine = [];

    foreach ($rows as $row) {
        $job = is_resource($row['job']) ? stream_get_contents($row['job']) : $row['job'];
        $data = json_decode((string)$job, true);

        if (is_array($data) && isset($data['runId']) && in_array((int)$data['runId'], array_map('intval', $ids), true)) {
            $row['runId'] = (int)$data['runId'];
            $row['decoded'] = $data;
            $mine[] = $row;
        }
    }

    return $mine;
}

class TransportHandler extends \skeeks\cms\job\handlers\AbstractJobHandler
{
    public static $observed = [];

    public function run(
        \skeeks\cms\job\runtime\JobContext $context,
        \skeeks\cms\job\contracts\JobReporterInterface $reporter
    ): void {
        self::$observed[] = (int)$context->getRun()->id;
        $reporter->countSuccess();
    }
}

$registry = Yii::$app->jobs->getRegistry();

foreach (['default', 'imports', 'exports', 'notifications', 'mail', 'hosting'] as $lane) {
    $registry->add(new JobTypeDefinition([
        'type' => 'transport.'.$lane,
        'handler' => TransportHandler::class,
        'queue' => $lane,
        'title' => 'Тест транспорта: '.$lane,
        'leaseSeconds' => 60,
        'timeout' => 60,
    ]));
}

echo "\n1. Постановка создаёт запуск и транспортное сообщение\n";

$run = Yii::$app->jobs->push('transport.default', ['x' => 1], ['correlationId' => $CORRELATION]);

jobExpect($run !== null, 'запуск создан');
jobExpect($run->queue_name === 'default', 'полоса взята из реестра');

$messages = ownMessages('default');
jobExpect(count($messages) === 1, 'в транспорте ровно одно сообщение');
jobExpect(
    $messages && $messages[0]['runId'] === (int)$run->id,
    'сообщение ссылается на созданный запуск'
);

echo "\n2. Через очередь идёт только номер запуска\n";

$decoded = $messages ? $messages[0]['decoded'] : [];
$keys = array_keys($decoded);
sort($keys);

jobExpect($keys === ['class', 'runId', 'v'], 'конверт содержит только class, runId и v: '.implode(',', $keys));
jobExpect(
    ($decoded['v'] ?? null) === JobTransportMessage::VERSION,
    'указана версия формата конверта'
);
jobExpect(
    strpos((string)($decoded['class'] ?? ''), 'CmsJobEnvelope') !== false,
    'в очереди лежит конверт, а не доменный обработчик'
);
jobExpect(
    !isset($decoded['payload']) && !isset($decoded['handler']),
    'payload и обработчик через очередь не передаются'
);

echo "\n3. Маршрутизация по именованным полосам\n";

$byLane = [];
foreach (['imports', 'exports', 'notifications', 'mail', 'hosting'] as $lane) {
    $laneRun = Yii::$app->jobs->push('transport.'.$lane, [], ['correlationId' => $CORRELATION]);
    $byLane[$lane] = $laneRun;

    $laneMessages = ownMessages($lane);
    $found = false;
    foreach ($laneMessages as $m) {
        if ($m['runId'] === (int)$laneRun->id) {
            $found = true;
            break;
        }
    }

    jobExpect($found, "сообщение полосы '{$lane}' попало в свой канал");
}

$importsMessages = ownMessages('imports');
$importsRunIds = array_map(function ($m) { return $m['runId']; }, $importsMessages);
jobExpect(
    !in_array((int)$byLane['mail']->id, $importsRunIds, true),
    'сообщение чужой полосы в канал imports не попало'
);

echo "\n4. Атомарность: откат снимает и запуск, и сообщение\n";

$before = count(ownMessages());
$beforeRuns = (int)CmsJobRun::find()->where(['correlation_id' => $CORRELATION])->count();

$rolledBackId = null;

try {
    Yii::$app->db->transaction(function () use (&$rolledBackId, $CORRELATION) {
        $inner = Yii::$app->jobs->push('transport.default', [], ['correlationId' => $CORRELATION]);
        $rolledBackId = (int)$inner->id;

        throw new \RuntimeException('Намеренный откат');
    });
} catch (\RuntimeException $e) {
    // Ожидаемо.
}

jobExpect($rolledBackId !== null, 'постановка внутри транзакции успела создать запуск');
jobExpect(
    CmsJobRun::findOne($rolledBackId) === null,
    'после отката строки запуска нет'
);

$afterRuns = (int)CmsJobRun::find()->where(['correlation_id' => $CORRELATION])->count();
jobExpect($afterRuns === $beforeRuns, 'число запусков не изменилось');

$after = ownMessages();
$rolledBackMessages = array_filter($after, function ($m) use ($rolledBackId) {
    return $m['runId'] === $rolledBackId;
});

jobExpect(count($rolledBackMessages) === 0, 'после отката транспортного сообщения тоже нет');
jobExpect(count($after) === $before, 'общее число сообщений не изменилось');

echo "\n5. Общая транзакция обеспечена одним экземпляром соединения\n";

$appDb = Yii::$app->db;
$queueDb = Yii::$app->jobQueueFactory->get('default')->db;

jobExpect(
    $appDb === $queueDb,
    'драйвер очереди использует тот же экземпляр yii\db\Connection, что и приложение'
);

echo "\n6. Фиксация сохраняет обе записи\n";

$committedId = null;
Yii::$app->db->transaction(function () use (&$committedId, $CORRELATION) {
    $inner = Yii::$app->jobs->push('transport.default', [], ['correlationId' => $CORRELATION]);
    $committedId = (int)$inner->id;
});

jobExpect(CmsJobRun::findOne($committedId) !== null, 'после фиксации запуск существует');

$committedMessages = array_filter(ownMessages(), function ($m) use ($committedId) {
    return $m['runId'] === $committedId;
});
jobExpect(count($committedMessages) === 1, 'после фиксации существует и сообщение');

echo "\n7. Воркер обрабатывает свою полосу и не трогает чужую\n";

TransportHandler::$observed = [];

// Изоляция здесь выключена намеренно.
//
// Типы этого теста регистрируются в реестре во время выполнения, то есть
// живут только в памяти текущего процесса. Дочерний процесс поднимает
// приложение заново и видит лишь типы из конфигурации, поэтому такой тип для
// него не существует. Это не обход дефекта, а реальное свойство изоляции: тип
// задания обязан быть объявлен в конфигурации, иначе исполнить его нельзя.
// Настоящая изоляция проверяется отдельным разделом на конфигурационном типе.
$options = new WorkerOptions([
    'queue' => 'imports',
    'once' => true,
    'timeout' => 1,
    'maxJobs' => 10,
    'isolate' => false,
]);

Yii::$app->jobConsumer->consume($options);

$importsRun = $byLane['imports'];
$mailRun = $byLane['mail'];
$importsRun->refresh();
$mailRun->refresh();

jobExpect(
    in_array((int)$importsRun->id, TransportHandler::$observed, true),
    'запуск полосы imports выполнен'
);
jobExpect(
    !in_array((int)$mailRun->id, TransportHandler::$observed, true),
    'воркер imports не забрал сообщение полосы mail'
);
jobExpect($importsRun->status === CmsJobRun::STATUS_SUCCEEDED, 'статус запуска imports — succeeded');
jobExpect($mailRun->status === CmsJobRun::STATUS_QUEUED, 'запуск полосы mail остался в очереди');

echo "\n8. Повторная доставка одного номера запуска безопасна\n";

TransportHandler::$observed = [];

// Публикуем второй конверт на уже выполненный запуск.
Yii::$app->jobPublisher->publish(
    new JobTransportMessage((int)$importsRun->id),
    'imports',
    0,
    0,
    60
);

Yii::$app->jobConsumer->consume(new WorkerOptions([
    'queue' => 'imports',
    'once' => true,
    'timeout' => 1,
    'isolate' => false,
]));

jobExpect(
    TransportHandler::$observed === [],
    'обработчик повторно не вызывался: запуск уже завершён'
);

$importsRun->refresh();
jobExpect($importsRun->status === CmsJobRun::STATUS_SUCCEEDED, 'статус не изменился');

echo "\n9. Отмена ожидающего: сообщение остаётся, работа не выполняется\n";

TransportHandler::$observed = [];
$cancelled = $byLane['exports'];
Yii::$app->jobs->cancel($cancelled);
$cancelled->refresh();

jobExpect($cancelled->status === CmsJobRun::STATUS_CANCELLED, 'запуск отменён сразу');

Yii::$app->jobConsumer->consume(new WorkerOptions([
    'queue' => 'exports',
    'once' => true,
    'timeout' => 1,
]));

jobExpect(
    !in_array((int)$cancelled->id, TransportHandler::$observed, true),
    'доставленное сообщение отменённой операции не запустило обработчик'
);

echo "\n10. Транспортная таблица не является историей\n";

$remaining = ownMessages('imports');
jobExpect(
    count($remaining) === 0,
    'после успешной обработки сообщения удалены из транспорта, история осталась в cms_job_run'
);

echo "\n11. Изоляция: задание выполняется в дочернем процессе\n";

// Тип из конфигурации, а не зарегистрированный в памяти: дочерний процесс
// поднимает приложение заново и видит только конфигурационные типы.
$isolated = Yii::$app->jobs->push('console.command', ['command' => 'help'], [
    'correlationId' => $CORRELATION,
]);

jobExpect($isolated !== null, 'конфигурационный тип поставлен в очередь');

$parentPid = getmypid();

Yii::$app->jobConsumer->consume(new WorkerOptions([
    'queue' => CmsJobRun::QUEUE_MAINTENANCE,
    'once' => true,
    'timeout' => 1,
    'isolate' => true,
]));

$isolated->refresh();

// При isolate=true родительский процесс обработчик не вызывает вовсе: он
// только порождает дочерний. Поэтому успешный статус здесь и есть
// доказательство того, что работу выполнил дочерний процесс.
jobExpect(
    $isolated->status === CmsJobRun::STATUS_SUCCEEDED,
    'изолированное задание выполнено: статус '.$isolated->status
);

// Дополнительное вещественное свидетельство: журнал команды, записанный
// в дочернем процессе. `worker_pid` для проверки не годится — он снимается
// при завершении вместе с маркером владения.
jobExpect(
    count($isolated->artifacts) === 1,
    'дочерний процесс оставил артефакт с журналом команды'
);

echo "\n12. Долгий обработчик прерывается и даёт терминальный отказ\n";

// Команда, которая не завершится сама. Проверяем, что предел по времени
// соблюдается принудительно, а не зависит от того, проверяет ли обработчик
// отмену: зависший обработчик её не проверяет никогда.
$sleeper = sys_get_temp_dir().'/cms-job-ttr-'.getmypid().'.php';
file_put_contents($sleeper, '<?php sleep(120);');

Yii::$app->jobs->getRegistry()->add(new JobTypeDefinition([
    'type' => 'transport.ttr',
    'handler' => [
        'class' => \skeeks\cms\job\handlers\ConsoleCommandJobHandler::class,
        'scriptPath' => $sleeper,
    ],
    'queue' => CmsJobRun::QUEUE_MAINTENANCE,
    'title' => 'Зависающее задание',
    'timeout' => 5,
    'leaseSeconds' => 5,
    'idempotent' => false,
]));

$hung = Yii::$app->jobs->push('transport.ttr', ['command' => 'help'], [
    'correlationId' => $CORRELATION,
]);

$startedAt = microtime(true);

Yii::$app->jobConsumer->consume(new WorkerOptions([
    'queue' => CmsJobRun::QUEUE_MAINTENANCE,
    'once' => true,
    'timeout' => 1,
    'isolate' => false,
]));

$elapsed = microtime(true) - $startedAt;
$hung->refresh();

jobExpect(
    $elapsed < 60,
    sprintf('обработчик прерван по таймауту за %.1f с вместо 120', $elapsed)
);

// Здесь срабатывает собственный таймаут ConsoleCommandJobHandler, а не
// жёсткий TTR транспорта: при isolate=false дочернего процесса нет и убивать
// по времени некому. Итог — терминальный отказ, а не повтор, потому что тип
// не объявлен идемпотентным.
jobExpect(
    $hung->status === CmsJobRun::STATUS_FAILED,
    'неидемпотентное задание после таймаута завершается отказом, а не повтором: '.$hung->status
);

jobExpect(
    $hung->error_code === 'permanent' && $hung->error_message === 'Команда прервана по таймауту.' && $elapsed >= 5,
    'отказ вызван реальным таймаутом команды, а не ошибкой загрузки'
);
@unlink($sleeper);

echo "\n13. Решение домена при жёстком TTR транспорта\n";

// Сквозной Symfony Process → handleHardTimeout проверяется отдельно в
// job-recovery-regression.php. Здесь проверяем доменное решение.
$ttrRun = Yii::$app->jobs->push('transport.ttr', ['command' => 'help'], [
    'correlationId' => $CORRELATION,
]);
$ttrToken = Yii::$app->jobRunStore->claim((int)$ttrRun->id, 'w-ttr', 60);

Yii::$app->jobRunner->failHardTimeout((int)$ttrRun->id, 5, $ttrToken);
$ttrRun->refresh();

jobExpect(
    $ttrRun->status === CmsJobRun::STATUS_TIMED_OUT,
    'неидемпотентный тип при жёстком TTR получает timed_out: '.$ttrRun->status
);
jobExpect($ttrRun->error_code === 'timeout', 'причина отказа — таймаут');

// Уборка: только записи этого прогона.
$ids = CmsJobRun::find()->select('id')->where(['correlation_id' => $CORRELATION])->column();

foreach (ownMessages() as $m) {
    Yii::$app->db->createCommand()->delete($QUEUE_TABLE, ['id' => $m['id']])->execute();
}

if ($ids) {
    CmsJobRun::deleteAll(['id' => $ids]);
}

echo "\n";
if ($failures) {
    echo 'ПРОВАЛЕНО: '.count($failures).' из '.$checks."\n";
    exit(1);
}

echo "Интеграция с транспортом: {$checks} проверок, все пройдены\n";
exit(0);
