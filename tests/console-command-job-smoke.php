<?php
/**
 * Дымовой тест моста для консольных команд и связки с расписанием.
 *
 * Запуск из корня проекта:
 *   php vendor/skeeks/cms-job/tests/console-command-job-smoke.php
 *
 * Тест реально запускает дочерние процессы `php yii ...` и работает с базой,
 * создавая и удаляя собственные записи.
 */

use skeeks\cms\agent\models\CmsAgentModel;
use skeeks\cms\job\models\CmsJobRun;
use skeeks\cms\job\models\CmsJobRunArtifact;

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

// Транспорт здесь не проверяется: сообщения складываются в память, а доставку
// вызываем сами. Интеграция с yii2-queue — в job-transport-integration.php.
$publisher = new \skeeks\cms\job\transport\CollectingPublisher();
Yii::$app->set('jobPublisher', $publisher);
Yii::$app->set('jobs', [
    'class' => \skeeks\cms\job\CmsJobComponent::class,
    'publisher' => $publisher,
]);

$runner = Yii::$app->jobRunner;
$worker = 'console-smoke:'.getmypid();

$drain = function () use ($publisher, $runner) {
    $ids = $publisher->drain([CmsJobRun::QUEUE_MAINTENANCE], false);
    foreach ($ids as $id) {
        $runner->execute($id);
    }

    return $ids;
};

echo "\n1. Тип console.command зарегистрирован ядром\n";

$registry = Yii::$app->jobs->getRegistry();
jobExpect($registry->has('console.command'), 'тип console.command есть в реестре');

$definition = $registry->get('console.command');
jobExpect($definition->queue === CmsJobRun::QUEUE_MAINTENANCE, 'полоса задана реестром: '.$definition->queue);
jobExpect($definition->idempotent === false, 'консольная команда по умолчанию не идемпотентна');
jobExpect($definition->overlapPolicy === CmsJobRun::OVERLAP_SKIP, 'политика пересечения — skip');

echo "\n2. Успешная команда\n";
Yii::$app->jobLogs->maxBytes = 1024;

$run = Yii::$app->jobs->push('console.command', [
    'command' => 'help',
]);

jobExpect($run !== null, 'задание поставлено');
$drain();
$run->refresh();

jobExpect($run->status === CmsJobRun::STATUS_SUCCEEDED, 'нулевой код возврата даёт succeeded, статус: '.$run->status);
jobExpect($run->getResult() === ['exit_code' => 0], 'код возврата сохранён в результате');

$log = CmsJobRunArtifact::find()
    ->where(['cms_job_run_id' => $run->id, 'type' => CmsJobRunArtifact::TYPE_LOG])
    ->one();
jobExpect($log !== null, 'вывод команды приложен журналом');
jobExpect($log && $log->size > 0, 'журнал не пустой');
jobExpect($log && $log->log_path && !$log->cms_storage_file_id, 'лог хранится приватно, без uploads');
$privateLog = $log ? Yii::$app->jobLogs->resolve($log->log_path) : null;
jobExpect($privateLog && filesize($privateLog) <= 1024, 'вывод ограничен лимитом файла');
jobExpect($privateLog && strpos(file_get_contents($privateLog), 'Лог сокращён') !== false, 'усечение явно отмечено, команда завершилась');
Yii::$app->jobLogs->maxBytes = 5242880;

echo "\n3. Несуществующая команда\n";

$bad = Yii::$app->jobs->push('console.command', [
    'command' => 'nosuch/route',
]);
$drain();
$bad->refresh();

jobExpect($bad->status === CmsJobRun::STATUS_FAILED, 'ненулевой код возврата даёт failed');
jobExpect($bad->error_code === 'permanent', 'отказ команды не повторяется');
jobExpect((bool)$bad->error_message, 'сообщение об ошибке сохранено');

echo "\n4. Оболочка не участвует: метасимволы не выполняются\n";

// Каталог заведомо существует и доступен на запись: если бы подстановка
// сработала, файл действительно был бы создан, и проверка это поймала бы.
$marker = sys_get_temp_dir().'/cms-job-INJECTED-'.getmypid();
@unlink($marker);
jobExpect(is_writable(sys_get_temp_dir()), 'каталог для маркера доступен на запись');

$injected = Yii::$app->jobs->push('console.command', [
    'command' => 'help',
    'args' => ['--x=1; touch '.$marker],
]);
$drain();
$injected->refresh();

jobExpect(!file_exists($marker), 'подстановка команды через аргумент не сработала');
jobExpect(
    $injected->status === CmsJobRun::STATUS_FAILED,
    'аргумент с метасимволами отклонён валидацией, статус: '.$injected->status
);

echo "\n5. Маршрут проверяется по синтаксису\n";

$evil = Yii::$app->jobs->push('console.command', [
    'command' => 'help; rm -rf /',
]);
$drain();
$evil->refresh();

jobExpect($evil->status === CmsJobRun::STATUS_FAILED, 'недопустимый маршрут отклонён');
jobExpect(
    strpos((string)$evil->error_message, 'Недопустимый маршрут') !== false,
    'причина отказа названа явно'
);

echo "\n6. Ограничение списком разрешённых команд\n";

$registry->add(new \skeeks\cms\job\JobTypeDefinition([
    'type' => 'console.restricted',
    'handler' => [
        'class' => \skeeks\cms\job\handlers\ConsoleCommandJobHandler::class,
        'allowedCommands' => ['help', 'cms/*'],
    ],
    'queue' => CmsJobRun::QUEUE_MAINTENANCE,
    'title' => 'Ограниченная команда',
]));

$denied = Yii::$app->jobs->push('console.restricted', ['command' => 'shop/agents/update-product-rating']);
$drain();
$denied->refresh();

jobExpect($denied->status === CmsJobRun::STATUS_FAILED, 'команда вне списка отклонена');
jobExpect(
    strpos((string)$denied->error_message, 'не входит в список') !== false,
    'причина отказа названа явно'
);

$allowed = Yii::$app->jobs->push('console.restricted', ['command' => 'help']);
$drain();
$allowed->refresh();
jobExpect($allowed->status === CmsJobRun::STATUS_SUCCEEDED, 'команда из списка выполняется');

echo "\n7. Агент по расписанию ставит задание, а не выполняет команду\n";
if (!method_exists(CmsAgentModel::class, 'pushJob')) {
    throw new RuntimeException('Scheduler bridge is unavailable: install the cms-agent candidate containing pushJob before releasing the integration.');
}

$agent = new CmsAgentModel([
    'name' => 'smoke/console/bridge',
    'description' => 'Дымовой тест моста расписания',
    'agent_interval' => 600,
    'is_active' => 1,
    'is_system' => 0,
    'job_type' => 'console.command',
]);
$agent->setJobPayload(['command' => 'help']);
if (getenv('SKEEKS_JOB_RELEASE_DB') !== false) {
    $agent->cms_site_id = 1; // Explicit site in the isolated FK fixture.
}
jobExpect($agent->save(), 'агент с типом задания сохранён');

jobExpect($agent->isJobBased, 'агент опознан как создающий задание');
jobExpect($agent->jobPayload === ['command' => 'help'], 'payload агента читается обратно');

$fromAgent = $agent->pushJob();
jobExpect($fromAgent !== null, 'агент поставил задание');
jobExpect($fromAgent->trigger_type === CmsJobRun::TRIGGER_SCHEDULE, 'источник запуска — расписание');
jobExpect($fromAgent->trigger_ref === 'cms_agent:'.$agent->id, 'указана ссылка на строку расписания');
jobExpect($fromAgent->dedup_active === 'cms_agent:'.$agent->id, 'ключ дедупликации по агенту активен');

echo "\n8. Повторный тик расписания не плодит задания\n";

$again = $agent->pushJob();
jobExpect($again === null, 'вторая постановка отброшена, пока первая не завершилась');

$fromAgent->refresh();
jobExpect((int)$fromAgent->skipped_runs === 1, 'пропуск зафиксирован счётчиком, а не потерян');

$drain();
$fromAgent->refresh();
jobExpect($fromAgent->status === CmsJobRun::STATUS_SUCCEEDED, 'задание от агента выполнено');

$third = $agent->pushJob();
jobExpect($third !== null, 'после завершения расписание снова ставит задание');

echo "\n9. Отмена действительно останавливает процесс\n";

// Скрипт-заглушка вместо консольного приложения: нужен долгий дочерний
// процесс, чтобы проверить SIGTERM. Прежняя схема отмены останавливала
// только запись в базе, а сам процесс продолжал работать.
$sleeper = sys_get_temp_dir().'/cms-job-sleeper-'.getmypid().'.php';
file_put_contents($sleeper, '<?php sleep(60);');

/**
 * Запрашивает отмену уже после старта процесса.
 *
 * Проверять надо именно отмену работающей операции: отмена ожидающей
 * короткозамыкается ядром до запуска обработчика и о сигналах ничего не
 * доказывает.
 */
class CancellingConsoleHandler extends \skeeks\cms\job\handlers\ConsoleCommandJobHandler
{
    public function run(
        \skeeks\cms\job\runtime\JobContext $context,
        \skeeks\cms\job\contracts\JobReporterInterface $reporter
    ): void {
        CmsJobRun::updateAll(
            ['cancel_requested_at' => time()],
            ['id' => $context->getRun()->id]
        );

        parent::run($context, $reporter);
    }
}

$registry->add(new \skeeks\cms\job\JobTypeDefinition([
    'type' => 'console.sleeper',
    'handler' => [
        'class' => CancellingConsoleHandler::class,
        'scriptPath' => $sleeper,
    ],
    'queue' => CmsJobRun::QUEUE_MAINTENANCE,
    'title' => 'Долгая команда',
    'leaseSeconds' => 120,
]));

$long = Yii::$app->jobs->push('console.sleeper', ['command' => 'help']);

$startedAt = microtime(true);
foreach ($publisher->drain([CmsJobRun::QUEUE_MAINTENANCE], false) as $id) {
    $runner->execute($id);
}
$elapsed = microtime(true) - $startedAt;

$long->refresh();

jobExpect($long->status === CmsJobRun::STATUS_CANCELLED, 'отменённая команда получает статус cancelled');
jobExpect($elapsed < 30, sprintf('процесс убит, а не досижен до конца: %.1f с вместо 60', $elapsed));

@unlink($sleeper);
CmsJobRun::deleteAll(['job_type' => 'console.sleeper']);

// Уборка за собой.
foreach ([$run, $bad, $injected, $evil, $denied, $allowed, $fromAgent, $third] as $item) {
    if ($item && !$item->isNewRecord) {
        CmsJobRun::deleteAll(['id' => $item->id]);
    }
}
CmsJobRun::deleteAll(['job_type' => ['console.command', 'console.restricted']]);
CmsAgentModel::deleteAll(['name' => 'smoke/console/bridge']);
@unlink($marker);

echo "\n";
if ($failures) {
    echo 'ПРОВАЛЕНО: '.count($failures).' из '.$checks."\n";
    exit(1);
}

echo "Мост консольных команд и расписания: {$checks} проверок, все пройдены\n";
exit(0);
