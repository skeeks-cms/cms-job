<?php
// Minimal package-only application; never reads the site's database config.
$vendor = (getenv('SKEEKS_APP_ROOT') ?: '/app').'/vendor';
require_once $vendor.'/autoload.php';
require_once $vendor.'/yiisoft/yii2/Yii.php';

use skeeks\cms\job\contracts\JobReporterInterface;
use skeeks\cms\job\handlers\AbstractJobHandler;
use skeeks\cms\job\runtime\JobContext;

class ReleaseFixtureHandler extends AbstractJobHandler
{
    public function run(JobContext $context, JobReporterInterface $reporter): void
    {
        $reporter->countSuccess();
    }
}
class ReleaseFixtureConsumer extends \skeeks\cms\job\transport\yii2queue\Yii2QueueConsumer
{
    protected function resolveScriptPath() { return __DIR__.'/release-worker.php'; }
}

$database = getenv('SKEEKS_JOB_RELEASE_DB');
if (!preg_match('/^cmsjob_release_[a-f0-9]{12}$/D', (string)$database)) {
    throw new RuntimeException('A private release test database is required.');
}
$prefix = getenv('SKEEKS_JOB_RELEASE_PREFIX') ?: '';
if (!in_array($prefix, ['', 'sx_'], true)) { throw new RuntimeException('Invalid test prefix'); }
$config = yii\helpers\ArrayHelper::merge(
    require __DIR__.'/../../src/config/common.php',
    require __DIR__.'/../../src/config/console.php',
    require __DIR__.'/consumer-config.php',
    [
        'id' => 'cms-job-release-test', 'basePath' => dirname(__DIR__),
        'runtimePath' => sys_get_temp_dir().'/'.$database,
        'vendorPath' => $vendor, 'extensions' => [],
        'aliases' => ['@skeeks/cms/job' => dirname(__DIR__, 2).'/src', '@root' => sys_get_temp_dir().'/'.$database],
        'components' => [
            'jobLogs' => ['basePath' => '@runtime/cms-job/logs'],
            'db' => [
                'class' => yii\db\Connection::class,
                'dsn' => 'mysql:host=cmsjob-release-db;dbname='.$database,
                'username' => 'root', 'password' => '', 'charset' => 'utf8mb4',
                'tablePrefix' => $prefix,
            ],
            'jobConsumer' => ['class' => ReleaseFixtureConsumer::class],
            'jobRegistry' => ['types' => [
                'release.fixture' => [
                    'type' => 'release.fixture', 'handler' => ReleaseFixtureHandler::class,
                    'queue' => 'maintenance', 'timeout' => 5, 'leaseSeconds' => 5,
                ],
            ]],
        ],
    ]
);
unset($config['components']['authManager']);
foreach (array_keys($config['components']['jobQueueFactory']['queues']) as $lane) {
    $config['components']['jobRegistry']['types']['release.'.$lane] = [
        'type' => 'release.'.$lane, 'handler' => ReleaseFixtureHandler::class,
        'queue' => $lane, 'timeout' => 5, 'leaseSeconds' => 5,
    ];
}
return new yii\console\Application($config);
