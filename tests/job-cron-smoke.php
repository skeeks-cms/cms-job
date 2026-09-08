<?php
// No application config, DB, network, or real jobs. Tests the public facade.
require (getenv('SKEEKS_APP_ROOT') ?: '/app').'/vendor/autoload.php';
require (getenv('SKEEKS_APP_ROOT') ?: '/app').'/vendor/yiisoft/yii2/Yii.php';

use skeeks\cms\job\console\controllers\WorkerController;
use skeeks\cms\job\contracts\JobConsumerInterface;
use skeeks\cms\job\runtime\CronWorkerLock;
use skeeks\cms\job\transport\WorkerOptions;
use Symfony\Component\Process\Process;

if (($argv[1] ?? '') === 'lock-probe') {
    $lock = new CronWorkerLock($argv[2]);
    exit($lock->acquire($argv[3]) ? 0 : 10);
}
class CronTestConsumer extends yii\base\Component implements JobConsumerInterface
{
    public $seen;
    public $fail = false;
    public function consume(WorkerOptions $options): int {
        $this->seen = $options;
        if ($this->fail) { throw new RuntimeException('fixture failure'); }
        return 0;
    }
    public function size(string $queue): int { return 0; }
}
class CronTestFactory extends yii\base\Component
{
    public function has($queue) { return in_array($queue, ['default', 'maintenance'], true); }
    public function names() { return ['default', 'maintenance']; }
}
$root = sys_get_temp_dir().'/cms-job-cron-test-'.bin2hex(random_bytes(8));
mkdir($root, 0700);
Yii::setAlias('@root', $root);
$app = new yii\console\Application([
    'id' => 'cron-test', 'basePath' => $root,
    'components' => ['jobConsumer' => CronTestConsumer::class, 'jobQueueFactory' => CronTestFactory::class],
]);
$checks = 0;
function cronCheck($condition, $message) {
    global $checks;
    if (!$condition) { throw new RuntimeException($message); }
    $checks++;
    echo 'PASS '.$message."\n";
}
$controller = new WorkerController('worker', $app, ['queue' => 'maintenance']);
$path = $root.'/console/runtime/cms-jobs/locks';
$probe = function ($queue) use ($path) {
    $process = new Process([PHP_BINARY, __FILE__, 'lock-probe', $path, $queue]);
    $process->run();
    return $process->getExitCode();
};
try {
    cronCheck($controller->runAction('cron') === 0, 'cron route succeeds');
    $options = $app->jobConsumer->seen;
    cronCheck($options->once && $options->isolate, 'cron drains without disabling isolation');
    cronCheck($options->maxRuntime === 50 && $options->maxJobs === 100 && $options->memoryLimit === 256, 'bounded cron defaults');
    cronCheck($controller->once === false && $controller->maxSeconds === 0, 'cron does not alter subsequent worker defaults');
    cronCheck($controller->actionIndex() === 0 && !$app->jobConsumer->seen->once, 'persistent worker still listens');
    $controller->maxSeconds = 7;
    $controller->maxJobs = 2;
    cronCheck($controller->actionCron() === 0 && $app->jobConsumer->seen->maxRuntime === 7 && $app->jobConsumer->seen->maxJobs === 2, 'explicit cron limits forwarded');
    $lock = new CronWorkerLock($path);
    cronCheck($lock->acquire('maintenance'), 'acquire lane lock');
    cronCheck($probe('maintenance') === 10, 'independent process cannot overlap same lane');
    cronCheck($probe('default') === 0, 'different lane is independent');
    $app->jobConsumer->seen = null;
    cronCheck($controller->actionCron() === 0 && $app->jobConsumer->seen === null, 'busy cron exits successfully without consuming');
    $lock->release();
    cronCheck($probe('maintenance') === 0, 'lock available after release');
    $app->jobConsumer->fail = true;
    try { $controller->actionCron(); } catch (RuntimeException $e) {}
    cronCheck($probe('maintenance') === 0, 'exception releases cron lock');
    $app->jobConsumer->fail = false;
    $controller->maxSeconds = -1;
    cronCheck($controller->actionCron() === yii\console\ExitCode::USAGE, 'negative budget rejected');
    $controller->queue = 'missing';
    cronCheck($controller->actionCron() === yii\console\ExitCode::USAGE, 'unknown lane rejected');
    echo "OK {$checks} cron checks\n";
} finally {
    if (isset($lock)) { $lock->release(); }
    // Only the random directory created by this fixture, never project runtime.
    yii\helpers\FileHelper::removeDirectory($root);
}
