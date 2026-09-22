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

class DispatcherTestQueue extends DbQueue
{
    public function poll() { return $this->reserve(); }
}
class DispatcherTestConsumer extends Yii2QueueConsumer
{
    protected function resolveScriptPath() { return __FILE__; }
}
class IdleTestRecovery extends yii\base\Component
{
    public function getRegistry() { return new stdClass(); }
    public function reapExpired($callback = null) { return $callback === null ? 0 : []; }
}
class DispatcherTestJob extends yii\base\BaseObject implements yii\queue\JobInterface
{
    public $label;
    public $seconds = 1;
    public $crash = false;
    public function execute($queue)
    {
        self::record($this->label, 'start');
        if ($this->crash) { exit(17); }
        usleep((int)($this->seconds * 1000000));
        self::record($this->label, 'end');
    }
    public static function record($label, $event)
    {
        file_put_contents(getenv('SKEEKS_DISPATCH_PROOF'), json_encode([$label, $event, microtime(true), getmypid()])."\n", FILE_APPEND | LOCK_EX);
    }
}
$config = new Yiisoft\Config\Config(new Yiisoft\Config\ConfigPaths(ROOT_DIR, 'config'), null,
    [Yiisoft\Config\Modifier\RecursiveMerge::groups('console', 'console-'.ENV, 'params', 'params-console-'.ENV)], 'params-console-'.ENV);
$site = $config->has('console-'.ENV) ? $config->get('console-'.ENV) : $config->get('console');
$prefix = getenv('SKEEKS_DISPATCH_CHANNEL');
if (!$prefix) { $prefix = 'dispatch-test-'.bin2hex(random_bytes(8)); putenv('SKEEKS_DISPATCH_CHANNEL='.$prefix); }
$_ENV['SKEEKS_DISPATCH_CHANNEL'] = $_SERVER['SKEEKS_DISPATCH_CHANNEL'] = $prefix;
if (!preg_match('/^dispatch-test-[a-f0-9]{16}$/D', $prefix)) { throw new RuntimeException('Invalid test channel'); }
$common = require __DIR__.'/../src/config/common.php';
$factory = $common['components']['jobQueueFactory'];
$factory['defaults']['class'] = DispatcherTestQueue::class;
$factory['queues'] = [];
foreach (['a', 'b', 'c'] as $lane) { $factory['queues'][$lane] = ['channel' => $prefix.'-'.$lane]; }
$app = new yii\console\Application([
    'id' => 'dispatcher-live-test', 'basePath' => ROOT_DIR, 'vendorPath' => ROOT_DIR.'/vendor',
    'extensions' => [], 'components' => [
        'db' => $site['components']['db'], 'mutex' => $common['components']['mutex'],
        'jobQueueFactory' => $factory, 'jobConsumer' => ['class' => DispatcherTestConsumer::class],
        'jobDispatcher' => array_merge($common['components']['jobDispatcher'], ['lockPath' => sys_get_temp_dir().'/'.$prefix]),
        'jobs' => ['class' => IdleTestRecovery::class], 'jobRunStore' => ['class' => IdleTestRecovery::class],
        'jobLockManager' => ['class' => IdleTestRecovery::class],
    ],
]);
if (($argv[1] ?? '') === 'cms-job/worker/exec') {
    $name = substr(end($argv), strlen('--queue='));
    exit($app->jobQueueFactory->get($name)->execute($argv[2], stream_get_contents(STDIN), $argv[3], $argv[4], $argv[5]) ? 0 : 3);
}
if (($argv[1] ?? '') === 'dispatch-test') {
    $settings = new \skeeks\cms\job\transport\WorkerSettings(json_decode($argv[2], true));
    try {
        exit($app->jobDispatcher->consume(['a', 'b', 'c'], new WorkerOptions([
            'verbose' => true, 'once' => true, 'timeout' => 1, 'maxRuntime' => 20, 'maxJobs' => (int)($argv[3] ?? 0),
        ]), $settings));
    } catch (\yii\base\InvalidConfigException $e) { fwrite(STDERR, $e->getMessage()); exit(78); }
}
$proof = tempnam(sys_get_temp_dir(), 'cmsjob-dispatch-');
putenv('SKEEKS_DISPATCH_PROOF='.$proof);
$_ENV['SKEEKS_DISPATCH_PROOF'] = $_SERVER['SKEEKS_DISPATCH_PROOF'] = $proof;
$checks = 0;
function dispatchCheck($value, $label) {
    global $checks;
    if (!$value) { throw new RuntimeException('FAIL '.$label); }
    ++$checks; echo 'PASS '.$label."\n";
}
function events(): array {
    global $proof;
    $out = [];
    foreach (file($proof, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        [$label, $event, $at] = json_decode($line, true); $out[$label][$event] = $at;
    }
    return $out;
}
function launch(array $settings = [], int $maxJobs = 0) {
    $p = new Symfony\Component\Process\Process([PHP_BINARY, __FILE__, 'dispatch-test', json_encode($settings), (string)$maxJobs], ROOT_DIR, null, null, 30);
    $p->start(); return $p;
}
function pushTest($lane, $label, $seconds = 1, $ttr = 10, $crash = false) {
    global $app;
    $app->jobQueueFactory->get($lane)->ttr($ttr)->push(new DispatcherTestJob(['label' => $label, 'seconds' => $seconds, 'crash' => $crash]));
}
function clearTest() {
    global $app, $proof;
    foreach (['a', 'b', 'c'] as $name) { $app->jobQueueFactory->get($name)->clear(); }
    file_put_contents($proof, '');
}
$process = null;
try {
    $settings = new \skeeks\cms\job\transport\WorkerSettings();
    dispatchCheck($settings->mode === 'dispatcher' && $settings->maxProcesses === 10 && $settings->concurrency('a') === 1, 'defaults dispatcher / 10 total / 1 per channel');
    pushTest('a', 'a1'); pushTest('a', 'a2'); pushTest('b', 'b1'); pushTest('c', 'c1');
    $process = launch(); dispatchCheck($process->wait() === 0, 'dispatcher drains isolated lanes');
    $e = events();
    dispatchCheck(count($e) === 4 && $e['a2']['start'] >= $e['a1']['end'], 'one child per channel, backlog processed');
    dispatchCheck(max($e['a1']['start'], $e['b1']['start'], $e['c1']['start']) < min($e['a1']['end'], $e['b1']['end'], $e['c1']['end']), 'three distinct channels execute concurrently');
    clearTest();
    foreach (['a', 'b', 'c'] as $name) { pushTest($name, $name); }
    $process = launch(['maxProcesses' => 2]); dispatchCheck($process->wait() === 0, 'global cap run finishes');
    $e = events();
    dispatchCheck($e['c']['start'] >= min($e['a']['end'], $e['b']['end']), 'global cap limits children across channels');
    clearTest(); pushTest('a', 'a1'); pushTest('a', 'a2');
    $process = launch(['channels' => ['a' => 2]]); dispatchCheck($process->wait() === 0, 'per-channel override run finishes');
    $e = events(); dispatchCheck($e['a2']['start'] < $e['a1']['end'], 'per-channel concurrency configurable');
    clearTest(); pushTest('a', 'timeout', 4, 1); pushTest('b', 'crash', 1, 10, true); pushTest('a', 'after');
    $process = launch(); dispatchCheck($process->wait() === 0, 'timeout and crashed children release capacity');
    $e = events(); dispatchCheck(!isset($e['timeout']['end']) && !isset($e['crash']['end']) && isset($e['after']['end']), 'hard TTR and crash do not stop other jobs');
    clearTest(); pushTest('a', 'drain', 3); pushTest('a', 'waiting');
    $process = launch();
    $deadline = microtime(true) + 10;
    while (!isset(events()['drain']) && microtime(true) < $deadline) { usleep(50000); }
    dispatchCheck(isset(events()['drain']), 'child started before stop');
    $other = launch(); dispatchCheck($other->wait() === 78, 'second site dispatcher rejected by singleton lock');
    $process->signal(SIGTERM); dispatchCheck($process->wait() === 0, 'SIGTERM drains child gracefully');
    $e = events(); dispatchCheck(isset($e['drain']['end']) && !isset($e['waiting']), 'shutdown completes running job without reserving backlog');
    clearTest(); pushTest('a', 'one'); pushTest('b', 'two');
    $process = launch([], 1); dispatchCheck($process->wait() === 0 && count(events()) === 1, 'maxJobs bounds reservations before dispatch');
    echo "OK {$checks} checks\n";
} finally {
    if ($process && $process->isRunning()) { $process->signal(SIGTERM); $process->wait(); }
    clearTest(); unlink($proof);
}
