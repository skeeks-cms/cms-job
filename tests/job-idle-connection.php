<?php
// Explicit live-site transport smoke test. Only a random private channel is
// consumed/cleaned; no schedules, domain jobs or recovery passes are executed.
if (getenv('SKEEKS_JOB_LIVE_TEST') !== '1' || !getenv('SKEEKS_APP_ROOT')) {
    throw new RuntimeException('Set SKEEKS_JOB_LIVE_TEST=1 and SKEEKS_APP_ROOT explicitly.');
}
define('ROOT_DIR', getenv('SKEEKS_APP_ROOT'));
define('YII_ENV', 'dev');
define('YII_DEBUG', true);
require ROOT_DIR.'/vendor/skeeks/cms/bootstrap.php';

use skeeks\cms\job\transport\yii2queue\DbQueue;
use skeeks\cms\job\transport\yii2queue\Yii2QueueConsumer;
use skeeks\cms\job\transport\WorkerOptions;

class IdleTestQueue extends DbQueue
{
    public function poll() { return $this->reserve(); }
}
class IdleTestConsumer extends Yii2QueueConsumer
{
    protected function resolveScriptPath() { return __FILE__; }
}
class IdleTestRecovery extends yii\base\Component
{
    public function getRegistry() { return new stdClass(); }
    public function reapExpired($callback = null) { return $callback === null ? 0 : []; }
}
class IdleTestJob extends yii\base\BaseObject implements yii\queue\JobInterface
{
    public $parentId;
    public $proof;
    public function execute($queue)
    {
        $count = $queue->db->createCommand('SELECT COUNT(*) FROM information_schema.PROCESSLIST WHERE ID=:id', [':id' => $this->parentId])->queryScalar();
        file_put_contents($this->proof, json_encode(['parentConnections' => (int)$count, 'childPid' => getmypid()]));
    }
}
$config = new Yiisoft\Config\Config(new Yiisoft\Config\ConfigPaths(ROOT_DIR, 'config'), null,
    [Yiisoft\Config\Modifier\RecursiveMerge::groups('console', 'console-'.ENV, 'params', 'params-console-'.ENV)], 'params-console-'.ENV);
$site = $config->has('console-'.ENV) ? $config->get('console-'.ENV) : $config->get('console');
$channel = getenv('SKEEKS_IDLE_TEST_CHANNEL');
if (!$channel) {
    $channel = 'idle-test-'.bin2hex(random_bytes(8));
    putenv('SKEEKS_IDLE_TEST_CHANNEL='.$channel);
}
if (!preg_match('/^idle-test-[a-f0-9]{16}$/D', $channel)) throw new RuntimeException('Invalid test channel');
$common = require __DIR__.'/../src/config/common.php';
$factory = $common['components']['jobQueueFactory'];
$factory['defaults']['class'] = IdleTestQueue::class;
$factory['queues'] = ['test' => ['channel' => $channel]];
$app = new yii\console\Application([
    'id' => 'idle-connection-test', 'basePath' => ROOT_DIR, 'vendorPath' => ROOT_DIR.'/vendor',
    'extensions' => [], 'components' => [
        'db' => $site['components']['db'],
        'mutex' => $common['components']['mutex'],
        'jobQueueFactory' => $factory,
        'jobConsumer' => ['class' => IdleTestConsumer::class],
        'jobs' => ['class' => IdleTestRecovery::class],
        'jobRunStore' => ['class' => IdleTestRecovery::class],
        'jobLockManager' => ['class' => IdleTestRecovery::class],
    ],
]);
$queue = $app->jobQueueFactory->get('test');
if (($argv[1] ?? '') === 'cms-job/worker/exec') {
    exit($queue->execute($argv[2], stream_get_contents(STDIN), $argv[3], $argv[4], $argv[5]) ? 0 : 3);
}
$checks = 0;
function idleCheck($condition, $label) {
    global $checks;
    if (!$condition) throw new RuntimeException('FAIL '.$label);
    $checks++;
    echo 'PASS '.$label."\n";
}
$db = $app->db;
$proof = tempnam(sys_get_temp_dir(), 'cmsjob-idle-');
try {
    idleCheck($common['components']['jobQueueFactory']['defaults']['class'] === DbQueue::class, 'package defaults select releasing transport');
    idleCheck(($site['components']['jobQueueFactory']['defaults']['class'] ?? null) === DbQueue::class, 'effective site configuration selects releasing transport');
    $queue->releaseIdleConnection = false;
    $queue->poll();
    idleCheck($db->isActive, 'opt-out retains idle connection');
    $queue->releaseIdleConnection = true;
    $queue->poll();
    idleCheck(!$db->isActive, 'empty poll closes connection');
    $queue->poll();
    idleCheck(!$db->isActive, 'next poll reconnects and closes again');
    $tx = $db->beginTransaction();
    $queue->poll();
    idleCheck($db->isActive && $tx->isActive, 'empty poll preserves active transaction');
    $queue->push(new IdleTestJob(['proof' => $proof]));
    $tx->rollBack();
    idleCheck(!(new yii\db\Query())->from($queue->tableName)->where(['channel' => $channel])->exists($db), 'publication still rolls back with application transaction');
    $lock = yii\queue\db\Queue::class.$channel;
    idleCheck($queue->mutex->acquire($lock), 'reservation mutex acquired');
    $queue->releaseWorkerConnection();
    idleCheck($db->isActive && $queue->mutex->release($lock), 'connection-scoped reservation mutex preserved');
    $parentId = $db->createCommand('SELECT CONNECTION_ID()')->queryScalar();
    $queue->push(new IdleTestJob(['parentId' => $parentId, 'proof' => $proof]));
    $app->jobConsumer->consume(new WorkerOptions(['queue' => 'test', 'once' => true, 'maxJobs' => 1, 'verbose' => true]));
    $result = json_decode(file_get_contents($proof), true);
    idleCheck(($result['parentConnections'] ?? -1) === 0 && $result['childPid'] !== getmypid(), 'isolated child sees parent DB session released');
    idleCheck(!(new yii\db\Query())->from($queue->tableName)->where(['channel' => $channel])->exists($db), 'parent reconnects and acknowledges delivery');
    $parentId = $db->createCommand('SELECT CONNECTION_ID()')->queryScalar();
    $queue->push(new IdleTestJob(['parentId' => $parentId, 'proof' => $proof]));
    $app->jobConsumer->consume(new WorkerOptions(['queue' => 'test', 'once' => true, 'isolate' => false]));
    $result = json_decode(file_get_contents($proof), true);
    idleCheck(($result['parentConnections'] ?? 0) === 1 && $result['childPid'] === getmypid(), 'in-process handler retains its connection');
    idleCheck(!$db->isActive, 'worker closes after draining queue');
    echo "OK {$checks} checks\n";
} finally {
    if ($db->getTransaction()) $db->getTransaction()->rollBack();
    $db->createCommand()->delete($queue->tableName, ['channel' => $channel])->execute();
    unlink($proof);
}
