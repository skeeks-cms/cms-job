<?php
// No project bootstrap, credentials, network or persistent database.
define('YII_ENABLE_ERROR_HANDLER', false);
$vendor = getenv('SKEEKS_TEST_VENDOR') ?: '/app/vendor';
$loader = require $vendor.'/autoload.php';
$loader->addPsr4('skeeks\\cms\\job\\', dirname(__DIR__).'/src', true);
$loader->addPsr4('skeeks\\cms\\agent\\', dirname(__DIR__, 2).'/cms-agent/src', true);
require $vendor.'/yiisoft/yii2/Yii.php';

use skeeks\cms\agent\CmsAgentComponent;
use skeeks\cms\agent\models\CmsAgentModel;
use skeeks\cms\job\contracts\JobReporterInterface;
use skeeks\cms\job\exceptions\JobCancelledException;
use skeeks\cms\job\exceptions\JobRequeueException;
use skeeks\cms\job\handlers\CleanupJobHandler;
use skeeks\cms\job\JobRegistry;
use skeeks\cms\job\models\CmsJobRun;
use skeeks\cms\job\models\CmsJobRunArtifact;
use skeeks\cms\job\runtime\JobContext;
use skeeks\cms\job\runtime\JobHistoryCleanup;
use skeeks\cms\job\runtime\JobLogStorage;

class MaintenanceReporter implements JobReporterInterface
{
    public $cancelled = false;
    public $beats = 0;
    public $result = [];
    public $advanced = 0;
    public function setStage(string $stage, ?string $message = null): void {}
    public function setTotal(?int $total): void {}
    public function advance(int $by = 1): void { $this->advanced += $by; }
    public function countSuccess(int $by = 1): void {}
    public function countWarning(int $by = 1): void {}
    public function countError(int $by = 1): void {}
    public function countSkipped(int $by = 1): void {}
    public function info(string $message, array $context = []): void {}
    public function warning(string $message, array $context = []): void {}
    public function error(string $message, array $context = []): void {}
    public function itemError(string $itemType, $itemId, string $message, array $row = []): void {}
    public function heartbeat(): void { ++$this->beats; }
    public function isCancelled(): bool { return $this->cancelled; }
    public function addArtifact(string $type, string $path, array $options = []): CmsJobRunArtifact { throw new LogicException('Not used'); }
    public function setResult(array $result): void { $this->result = $result; }
}

$config = require dirname(__DIR__).'/src/config/common.php';
$root = sys_get_temp_dir().'/cms-job-maintenance-'.bin2hex(random_bytes(8));
mkdir($root, 0700);
$app = new yii\console\Application([
    'id' => 'maintenance-isolated-test', 'basePath' => $root, 'vendorPath' => $vendor,
    'runtimePath' => $root.'/runtime', 'extensions' => [],
    'components' => [
        'db' => ['class' => yii\db\Connection::class, 'dsn' => 'sqlite::memory:'],
        'cache' => ['class' => yii\caching\DummyCache::class],
        'jobLogs' => ['class' => JobLogStorage::class, 'basePath' => $root.'/logs'],
        'jobRegistry' => $config['components']['jobRegistry'],
        'cmsAgent' => array_merge(['class' => CmsAgentComponent::class, 'onHitsEnabled' => false], $config['components']['cmsAgent']),
        'skeeks' => new class extends yii\base\Component { public $site; },
        'jobs' => new class extends yii\base\Component {
            public function getRegistry() { return Yii::$app->jobRegistry; }
        },
        'i18n' => ['translations' => ['skeeks/agent' => [
            'class' => yii\i18n\PhpMessageSource::class,
            'basePath' => dirname(__DIR__, 2).'/cms-agent/src/messages',
        ]]],
    ],
]);
Yii::setAlias('@root', $root);
$app->skeeks->site = (object)['id' => 1];
$db = $app->db;
$db->createCommand('PRAGMA foreign_keys = ON')->execute();
$db->createCommand('CREATE TABLE cms_job_run (id INTEGER PRIMARY KEY, cms_site_id INTEGER, job_type TEXT, queue_name TEXT, status TEXT, retention_until INTEGER, payload_json TEXT, cursor_json TEXT, attempt INTEGER, max_attempts INTEGER, created_at INTEGER, updated_at INTEGER, lock_version INTEGER DEFAULT 0)')->execute();
$db->createCommand('CREATE TABLE cms_job_run_artifact (id INTEGER PRIMARY KEY, cms_job_run_id INTEGER REFERENCES cms_job_run(id) ON DELETE CASCADE, type TEXT, name TEXT, size INTEGER, created_at INTEGER, expires_at INTEGER, log_path TEXT, cms_storage_file_id INTEGER)')->execute();
$db->createCommand('CREATE TABLE cms_agent (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, description TEXT, job_type TEXT, job_payload TEXT, cms_site_id INTEGER, last_exec_at INTEGER, next_exec_at INTEGER, agent_interval INTEGER, priority INTEGER, is_system INTEGER, is_active INTEGER, is_running INTEGER, is_period INTEGER)')->execute();
$checks = 0;
function check($ok, string $message): void { global $checks; if (!$ok) { throw new RuntimeException($message); } ++$checks; }
function insertRun(int $id, string $status = 'succeeded', $expiry = null): CmsJobRun {
    Yii::$app->db->createCommand()->insert('cms_job_run', [
        'id' => $id, 'cms_site_id' => 1, 'job_type' => 'fixture', 'queue_name' => 'maintenance',
        'status' => $status, 'retention_until' => $expiry, 'payload_json' => '{}', 'cursor_json' => '{}',
    ])->execute();
    return CmsJobRun::findOne($id);
}
function artifact(CmsJobRun $run, string $extension = 'log', ?int $expiry = null): string {
    $logs = Yii::$app->jobLogs;
    $path = $logs->create($run, $extension);
    file_put_contents($path, 'private diagnostic');
    Yii::$app->db->createCommand()->insert('cms_job_run_artifact', [
        'cms_job_run_id' => $run->id, 'type' => $extension === 'csv' ? 'error-report' : 'log',
        'log_path' => $logs->key($path), 'expires_at' => $expiry ?? time() - 10,
    ])->execute();
    return $path;
}

try {
    $types = ['cms-job.cleanup' => 86400, 'cms-job.cleanup-logs' => 3600];
    foreach ($types as $type => $interval) {
        $def = $app->jobRegistry->get($type);
        check($def->queue === 'maintenance' && $def->idempotent && $def->overlapPolicy === 'skip', 'Native maintenance contract');
        check($def->permission === skeeks\cms\rbac\CmsManager::PERMISSION_ROLE_ADMIN_ACCESS, 'Existing admin permission');
        check(Yii::createObject($def->handler) instanceof CleanupJobHandler, 'Configured handler resolves');
        check(($def->resourceKey)() === 'cms-job:cleanup' && ($def->dedupKey)() === $type, 'Shared lock, distinct operation dedup');
    }
    $db->createCommand()->insert('cms_agent', ['name' => 'legacy/unrelated', 'cms_site_id' => 1, 'is_system' => 1, 'is_active' => 1])->execute();
    check(count($app->cmsAgent->getScheduleChanges()['create']) === 2, 'Exactly two new schedules');
    $app->cmsAgent->loadAgents()->loadAgents();
    check(CmsAgentModel::find()->count() == 3, 'Repeated initialization does not duplicate or delete unrelated schedule');
    foreach ($types as $type => $interval) {
        $agent = CmsAgentModel::findOne(['name' => 'job:'.$type]);
        check($agent && $agent->job_type === $type && $agent->agent_interval == $interval && $agent->is_active == 1, 'Default schedule enabled');
        check($agent->is_system == 1 && $agent->isJobBased, 'Package schedules are system schedules with native manual launch');
        check($agent->jobDedupKey === $type, 'Scheduler uses installation-wide dedup');
    }
    $agent = CmsAgentModel::findOne(['name' => 'job:cms-job.cleanup']);
    $agent->updateAttributes(['is_active' => 0, 'next_exec_at' => 12345, 'last_exec_at' => 12000]);
    $app->cmsAgent->loadAgents();
    $agent->refresh();
    check($agent->is_active == 0 && $agent->next_exec_at == 12345 && $agent->last_exec_at == 12000, 'Initialization preserves administrator and runtime state');
    $app->skeeks->site = (object)['id' => 2];
    $app->cmsAgent->loadAgents()->loadAgents();
    $other = CmsAgentModel::findOne(['name' => 'job:cms-job.cleanup', 'cms_site_id' => 2]);
    check($other->jobDedupKey === $agent->jobDedupKey && CmsAgentModel::find()->count() == 5, 'Multiple sites share cleanup dedup without duplicate schedules');
    $app->skeeks->site = (object)['id' => 1];

    $active = insertRun(1, 'running', time() - 100);
    $queued = insertRun(2, 'queued', time() - 100);
    $fresh = insertRun(3, 'succeeded', time() + 86400);
    $forever = insertRun(4, 'succeeded');
    $activePath = artifact($active);
    $queuedPath = artifact($queued);
    $freshPath = artifact($fresh, 'csv', time() + 86400);
    for ($i = 10; $i < 17; ++$i) { artifact(insertRun($i, $i % 2 ? 'failed' : 'succeeded', time() - 100), $i % 2 ? 'csv' : 'log'); }
    $current = insertRun(100, 'running');
    $context = new JobContext(['run' => $current]);
    $reporter = new MaintenanceReporter();
    $handler = new CleanupJobHandler(['batchSize' => 3]);
    $deliveries = 0;
    do {
        ++$deliveries;
        try { $handler->run($context, $reporter); $more = false; }
        catch (JobRequeueException $e) {
            $more = true;
            check($e->delay === 1 && $reporter->result['_job_execution']['state'] === 'awaiting_continuation', 'Bounded continuation');
            $context->getRun()->save(false);
            $context = new JobContext(['run' => CmsJobRun::findOne(100)]);
        }
    } while ($more && $deliveries < 10);
    check($deliveries === 3 && $reporter->result['deleted_runs'] === 7, 'Drain more than a batch in same run');
    check(CmsJobRun::find()->count() == 5 && is_file($activePath) && is_file($queuedPath) && is_file($freshPath), 'Active, queued, fresh and indefinite history protected');
    check(CmsJobRunArtifact::find()->count() == 3 && $reporter->beats > 7, 'Private files removed before cascading metadata, heartbeats maintained');
    check((new JobHistoryCleanup())->cleanup() === 0, 'History cleanup idempotent');

    $owner = insertRun(200, 'succeeded', time() + 86400);
    $expiredCsv = artifact($owner, 'csv');
    $expiredLog = artifact($owner);
    $oldOrphan = $app->jobLogs->create($owner, 'csv');
    touch($oldOrphan, time() - 15 * 86400);
    $activeOrphan = $app->jobLogs->create($active);
    touch($activeOrphan, time() - 15 * 86400);
    $freshOrphan = $app->jobLogs->create($owner);
    $outside = $root.'/unmanaged-working-file';
    file_put_contents($outside, 'must remain');
    $current = insertRun(101, 'running');
    $logHandler = new CleanupJobHandler(['operation' => 'logs']);
    $logReporter = new MaintenanceReporter();
    $logHandler->run(new JobContext(['run' => $current]), $logReporter);
    check(!is_file($expiredCsv) && !is_file($expiredLog) && !is_file($oldOrphan), 'Expired log/CSV and old orphan removed');
    check(is_file($activePath) && is_file($queuedPath) && is_file($freshPath) && is_file($activeOrphan) && is_file($freshOrphan) && is_file($outside), 'Active and fresh files and unmanaged paths protected');
    check(CmsJobRun::findOne(200) && CmsJobRunArtifact::find()->where(['cms_job_run_id' => 200, 'log_path' => null])->count() == 2, 'Log cleanup retains history and expired metadata');
    check($logReporter->result['expired_logs'] === 2 && $logReporter->result['orphan_logs'] === 1 && $logReporter->beats > 3, 'Log progress includes scan and deletion');
    for ($i = 0; $i < 7; ++$i) { artifact($owner); }
    $logHandler = new CleanupJobHandler(['operation' => 'logs', 'batchSize' => 3]);
    $logReporter = new MaintenanceReporter();
    $context = new JobContext(['run' => CmsJobRun::findOne(101)]);
    $deliveries = 0;
    do {
        ++$deliveries;
        try { $logHandler->run($context, $logReporter); $more = false; }
        catch (JobRequeueException $e) {
            $more = true;
            $context->getRun()->save(false);
            $context = new JobContext(['run' => CmsJobRun::findOne(101)]);
        }
    } while ($more && $deliveries < 10);
    check($deliveries === 3 && $logReporter->result['expired_logs'] === 7, 'Log backlog drains across persisted continuations');
    check($app->jobLogs->cleanup() === 0 && $app->jobLogs->cleanupOrphans() === 0, 'Legacy single-pass log cleanup remains idempotent');
    $cancel = new MaintenanceReporter();
    $cancel->cancelled = true;
    $pending = artifact($owner);
    try { $logHandler->run(new JobContext(['run' => $current]), $cancel); throw new LogicException('Cancellation ignored'); }
    catch (JobCancelledException $e) { check(is_file($pending), 'Cancellation precedes deletion'); }
    $context = new JobContext(['run' => $current]);
    $current->setPayload(['path' => '/']);
    try { $handler->run($context, new MaintenanceReporter()); throw new LogicException('Unsafe payload accepted'); }
    catch (InvalidArgumentException $e) { check(true, 'Payload cannot select a path or operation'); }
    $current->setPayload([]);
    // A failure to remove a private file must never erase its history.
    $bad = insertRun(300, 'failed', time() - 100);
    $db->createCommand()->insert('cms_job_run_artifact', ['cms_job_run_id' => 300, 'type' => 'log', 'log_path' => '../outside'])->execute();
    try { (new JobHistoryCleanup())->cleanup(); throw new LogicException('Unsafe path accepted'); }
    catch (InvalidArgumentException $e) { check(CmsJobRun::findOne(300) !== null, 'Deletion failure preserves owning history'); }
    echo "PASS: {$checks} isolated maintenance checks\n";
} finally {
    $db->close();
    yii\helpers\FileHelper::removeDirectory($root);
}
