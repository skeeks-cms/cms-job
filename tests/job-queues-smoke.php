<?php
// Configuration-only test: no site bootstrap, database or transport instances.
$vendor = (getenv('SKEEKS_APP_ROOT') ?: '/app').'/vendor';
require $vendor.'/autoload.php';
require $vendor.'/yiisoft/yii2/Yii.php';

class QueueListingController extends \skeeks\cms\job\console\controllers\WorkerController
{
    public $output = '';
    public $errors = '';
    public function stdout($string) { $this->output .= $string; }
    public function stderr($string) { $this->errors .= $string; }
}
$app = new yii\console\Application([
    'id' => 'queue-list-test', 'basePath' => __DIR__,
    'components' => [
        'jobQueueFactory' => [
            'class' => \skeeks\cms\job\transport\yii2queue\QueueFactory::class,
            'queues' => ['maintenance' => [], 'default' => []],
            // Must never be instantiated or disclosed by this command.
            'defaults' => ['class' => 'NonexistentTransport', 'password' => 'fixture-secret'],
        ],
        'jobRegistry' => [
            'class' => \skeeks\cms\job\JobRegistry::class,
            'types' => ['test.second' => ['handler' => 'UnusedHandler', 'queue' => 'maintenance', 'timeout' => 10, 'leaseSeconds' => 2],
                'test.first' => ['handler' => 'UnusedHandler', 'queue' => 'maintenance', 'timeout' => 20, 'leaseSeconds' => 3]],
        ],
    ],
]);
$controller = new QueueListingController('worker', $app);
$checks = 0;
function queueCheck($condition, $message) {
    global $checks;
    if (!$condition) { throw new RuntimeException($message); }
    echo 'PASS '.$message."\n";
    $checks++;
}
queueCheck($controller->runAction('queues', ['json' => '1']) === 0, 'public action accepts --json');
$data = json_decode($controller->output, true, 512, JSON_THROW_ON_ERROR);
queueCheck($data['schema_version'] === 1 && count($data['queues']) === 2, 'versioned JSON lists all configured lanes');
queueCheck($data['queues'][0]['name'] === 'default' && $data['queues'][0]['types'] === [] && $data['queues'][0]['max_ttr'] === null, 'empty lane retained');
queueCheck(array_column($data['queues'][1]['types'], 'type') === ['test.first', 'test.second'] && $data['queues'][1]['max_ttr'] === 23, 'types sorted and TTR derived from definitions');
queueCheck(strpos($controller->output, 'fixture-secret') === false, 'transport config not exposed');
$controller->json = false; $controller->output = '';
queueCheck($controller->actionQueues() === 0 && strpos($controller->output, 'maintenance') !== false, 'human-readable output');
$app->jobRegistry->add(['type' => 'test.missing', 'handler' => 'UnusedHandler', 'queue' => 'missing']);
$controller->json = true; $controller->output = '';
queueCheck($controller->actionQueues() === yii\console\ExitCode::CONFIG, 'missing lane returns config error');
$data = json_decode($controller->output, true, 512, JSON_THROW_ON_ERROR);
queueCheck($data['unconfigured_types'] === [['type' => 'test.missing', 'queue' => 'missing']], 'invalid reference included in parseable JSON');
echo "OK {$checks} queue listing checks\n";
