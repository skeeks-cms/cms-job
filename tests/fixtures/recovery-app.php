<?php
// Isolated test application: never loaded by production configuration.
use skeeks\cms\job\contracts\JobReporterInterface;
use skeeks\cms\job\handlers\AbstractJobHandler;
use skeeks\cms\job\runtime\JobContext;
use skeeks\cms\job\transport\yii2queue\Yii2QueueConsumer;

define('ROOT_DIR', getenv('SKEEKS_APP_ROOT') ?: '/app');
define('YII_ENV', 'dev');
define('YII_DEBUG', true);
require ROOT_DIR.'/vendor/skeeks/cms/bootstrap.php';

class RecoveryTestHandler extends AbstractJobHandler
{
    public function run(JobContext $context, JobReporterInterface $reporter): void
    {
        if ($context->get('mode') === 'pause') {
            usleep(600000);
        }
        if ($context->get('mode') === 'sleep'
            || ($context->get('mode') === 'sleep-once' && $context->getAttempt() === 1)) {
            sleep(20);
        }
        if ($context->get('mode') === 'crash') {
            exit(42);
        }
        if ($context->get('mode') === 'signal') {
            posix_kill(getmypid(), SIGKILL);
        }
        $reporter->countSuccess();
    }
}

class RecoveryTestConsumer extends Yii2QueueConsumer
{
    protected function resolveScriptPath()
    {
        return __DIR__.'/recovery-worker.php';
    }
}

$channel = getenv('SKEEKS_JOB_TEST_CHANNEL');
if (!$channel || strpos($channel, 'recovery-') !== 0) {
    throw new RuntimeException('A unique recovery test channel is required.');
}
$config = getenv('SKEEKS_JOB_RELEASE_DB') !== false ? null : new \Yiisoft\Config\Config(
    new \Yiisoft\Config\ConfigPaths(ROOT_DIR, 'config'), null,
    [\Yiisoft\Config\Modifier\RecursiveMerge::groups('console', 'console-'.ENV, 'params', 'params-console-'.ENV)],
    'params-console-'.ENV
);
$data = $config === null ? [] : ($config->has('console-'.ENV) ? $config->get('console-'.ENV) : $config->get('console'));
$data['components']['jobQueueFactory']['queues']['recovery'] = ['channel' => $channel, 'ttr' => 2];
$data['components']['jobConsumer'] = ['class' => RecoveryTestConsumer::class, 'crashRedeliveryDelay' => 1];
$data['components']['jobRunner']['retryPolicy'] = [
    'class' => \skeeks\cms\job\runtime\RetryPolicy::class, 'initialDelay' => 1, 'jitter' => 0,
];
foreach (['safe' => true, 'unsafe' => false] as $suffix => $idempotent) {
    $data['components']['jobRegistry']['types']['recovery.'.$suffix] = [
        'type' => 'recovery.'.$suffix, 'handler' => RecoveryTestHandler::class,
        'queue' => 'recovery', 'timeout' => 1, 'leaseSeconds' => 1,
        'idempotent' => $idempotent, 'maxAttempts' => $idempotent ? 2 : 1,
    ];
}
$createApplication = require __DIR__.'/test-application.php';
return $createApplication($data);
