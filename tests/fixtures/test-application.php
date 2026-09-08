<?php
// Opt-in disposable database mode, inherited by every child process.
class RegressionFixtureConsumer extends \skeeks\cms\job\transport\yii2queue\Yii2QueueConsumer
{
    protected function resolveScriptPath() { return __DIR__.'/regression-worker.php'; }
}
return static function (array $data): yii\console\Application {
    $database = getenv('SKEEKS_JOB_RELEASE_DB');
    if ($database === false) {
        return new yii\console\Application(yii\helpers\ArrayHelper::merge(
            require __DIR__.'/consumer-config.php', $data
        ));
    }
    if (!preg_match('/^cmsjob_release_[a-f0-9]{12}$/D', $database)) {
        throw new RuntimeException('Invalid private regression database.');
    }
    $config = yii\helpers\ArrayHelper::merge(
        require __DIR__.'/../../src/config/common.php',
        require __DIR__.'/../../src/config/console.php',
        require __DIR__.'/consumer-config.php'
    );
    // Retain test-specific recovery types and consumer, never site services.
    foreach ($data['components'] ?? [] as $id => $component) {
        if (strpos($id, 'job') === 0) {
            $config['components'][$id] = yii\helpers\ArrayHelper::merge($config['components'][$id] ?? [], $component);
        }
    }
    unset($config['components']['authManager']);
    $config['controllerMap']['migrate']['class'] = yii\console\controllers\MigrateController::class;
    if ($config['components']['jobConsumer']['class'] === \skeeks\cms\job\transport\yii2queue\Yii2QueueConsumer::class) {
        $config['components']['jobConsumer']['class'] = RegressionFixtureConsumer::class;
    }
    Yii::$container->set(\skeeks\cms\job\handlers\ConsoleCommandJobHandler::class, [
        'class' => \skeeks\cms\job\handlers\ConsoleCommandJobHandler::class,
        'scriptPath' => __DIR__.'/regression-worker.php',
    ]);
    $config['id'] = 'cms-job-private-regression';
    $config['basePath'] = ROOT_DIR;
    $config['runtimePath'] = sys_get_temp_dir().'/'.$database;
    $config['components']['jobLogs']['basePath'] = '@runtime/cms-job/logs';
    $config['vendorPath'] = ROOT_DIR.'/vendor';
    $config['extensions'] = [];
    $config['aliases']['@skeeks/cms/job'] = dirname(__DIR__, 2).'/src';
    $config['components']['cache'] = ['class' => yii\caching\ArrayCache::class];
    $config['components']['db'] = [
        'class' => yii\db\Connection::class,
        'dsn' => 'mysql:host=cmsjob-release-db;dbname='.$database,
        'username' => 'root', 'password' => '', 'charset' => 'utf8mb4',
    ];
    return new yii\console\Application($config);
};
