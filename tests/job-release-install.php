<?php
// Requires the disposable cmsjob-release-db MariaDB container, never the site DB.
use yii\db\Connection;
use yii\db\Query;
use skeeks\cms\job\models\CmsJobRun;
use Symfony\Component\Process\Process;

$database = 'cmsjob_release_'.bin2hex(random_bytes(6));
putenv('SKEEKS_JOB_RELEASE_DB='.$database);
$_ENV['SKEEKS_JOB_RELEASE_DB'] = $database;
$app = require __DIR__.'/fixtures/release-app.php';
$admin = new Connection(['dsn' => 'mysql:host=cmsjob-release-db', 'username' => 'root', 'password' => '']);
$created = false;
$checks = 0;
function releaseCheck($condition, $message) {
    global $checks;
    if (!$condition) { throw new RuntimeException($message); }
    echo 'PASS '.$message."\n";
    $checks++;
}
function migrateRelease($limit = 0) {
    $controller = new yii\console\controllers\MigrateController('migrate', Yii::$app, [
        'migrationPath' => [dirname(__DIR__).'/src/migrations'],
        'interactive' => false, 'compact' => true,
    ]);
    return $controller->runAction('up', [$limit]);
}
try {
    $admin->createCommand('CREATE DATABASE '.$admin->quoteTableName($database).' CHARACTER SET utf8mb4')->execute();
    $created = true;
    $db = $app->db;
    foreach (['cms_site', 'cms_user', 'cms_storage_file'] as $table) {
        // Only FK contracts of the owning CMS, not a full CMS installation.
        $db->createCommand()->createTable('{{%'.$table.'}}', ['id' => 'pk'], 'ENGINE=InnoDB')->execute();
    }
    if (in_array('--upgrade', $argv, true)) {
        releaseCheck(migrateRelease(4) === 0, 'old four-migration schema installs');
        foreach (['succeeded', 'queued'] as $status) {
            $db->createCommand()->insert('{{%cms_job_run}}', [
                'uid' => $status, 'job_type' => 'release.fixture', 'status' => $status,
                'available_at' => time(), 'created_at' => time(), 'updated_at' => time(),
                'result_json' => '{"preserve":true}',
            ])->execute();
        }
        $code = migrateRelease();
        releaseCheck($code !== 0, 'upgrade refuses unfinished legacy runs rather than losing delivery');
        releaseCheck(!isset($db->getTableSchema('{{%cms_job_run}}', true)->columns['execution_token']), 'guard runs before any schema change');
        $db->createCommand()->update('{{%cms_job_run}}', ['status' => 'running'], ['uid' => 'queued'])->execute();
        releaseCheck(migrateRelease() !== 0, 'upgrade also refuses a running legacy worker');
        $db->createCommand()->update('{{%cms_job_run}}', ['status' => 'cancelled'], ['uid' => 'queued'])->execute();
    }
    if (in_array('--collision', $argv, true)) {
        $db->createCommand()->createTable('{{%cms_queue}}', ['id' => 'pk'])->execute();
        releaseCheck(migrateRelease() !== 0, 'unknown transport table is not silently adopted');
        releaseCheck(count($db->getTableSchema('{{%cms_queue}}', true)->columns) === 1, 'foreign table left unchanged');
        $db->createCommand()->dropTable('{{%cms_queue}}')->execute();
    }
    releaseCheck(migrateRelease() === 0, 'current schema installs');
    releaseCheck(migrateRelease() === 0, 'repeated migration is a no-op');
    releaseCheck(isset($db->getTableSchema('{{%cms_job_run}}', true)->columns['execution_token']), 'run fencing column exists');
    releaseCheck(isset($db->getTableSchema('{{%cms_job_lock}}', true)->columns['execution_token']), 'lock fencing column exists');
    releaseCheck(count($db->getTableSchema('{{%cms_queue}}')->columns) === 10, 'transport schema has all ten columns');
    if (in_array('--upgrade', $argv, true)) {
        releaseCheck((new Query())->from('{{%cms_job_run}}')->where(['result_json' => '{"preserve":true}'])->count() == 2, 'legacy history and results preserved');
    }
    $transaction = $db->beginTransaction();
    $app->jobs->push('release.fixture');
    $transaction->rollBack();
    releaseCheck(!(new Query())->from('{{%cms_queue}}')->exists(), 'enqueue rollback leaves no transport message');
    $run = $app->jobs->push('release.fixture');
    releaseCheck((new Query())->from('{{%cms_queue}}')->count() == 1, 'enqueue produces one message');
    $worker = new Process([PHP_BINARY, __DIR__.'/fixtures/release-worker.php', 'cms-job/worker', '--queue=maintenance', '--once=1', '--maxJobs=1'], null, null, null, 20);
    $worker->run();
    releaseCheck($worker->getExitCode() === 0, 'public worker and isolated child exit successfully: '.$worker->getErrorOutput());
    $run->refresh();
    releaseCheck($run->status === 'succeeded' && (int)$run->success_count === 1, 'fresh schema job completes through isolated worker');
    releaseCheck(!(new Query())->from('{{%cms_queue}}')->exists(), 'successful delivery is acknowledged');
    $laneRuns = [];
    foreach (['default', 'imports', 'exports', 'notifications', 'mail', 'hosting', 'bulk', 'maintenance'] as $lane) {
        $laneRuns[$lane] = $app->jobs->push('release.'.$lane);
    }
    foreach ($laneRuns as $lane => $laneRun) {
        $worker = new Process([PHP_BINARY, __DIR__.'/fixtures/release-worker.php', 'cms-job/worker', '--queue='.$lane, '--once=1'], null, null, null, 20);
        $worker->run();
        $laneRun->refresh();
        releaseCheck($worker->getExitCode() === 0 && $laneRun->status === 'succeeded', 'isolated lane worker: '.$lane);
    }
    releaseCheck(!(new Query())->from('{{%cms_queue}}')->exists(), 'all lanes acknowledged');
    foreach ([[], ['--queue=missing'], ['--queues=imports,exports']] as $arguments) {
        $worker = new Process(array_merge([PHP_BINARY, __DIR__.'/fixtures/release-worker.php', 'cms-job/worker'], $arguments), null, null, null, 10);
        $worker->run();
        releaseCheck($worker->getExitCode() === yii\console\ExitCode::USAGE, 'invalid lane returns documented usage code');
    }
    $worker = new Process([PHP_BINARY, __DIR__.'/fixtures/release-worker.php', 'cmsJob/worker', '--queues=maintenance', '--once=1'], null, null, null, 10);
    $worker->run();
    releaseCheck($worker->getExitCode() === 0, 'legacy route and queues alias still work');
    $cronA = $app->jobs->push('release.fixture');
    $cronB = $app->jobs->push('release.fixture');
    $cronCommand = [PHP_BINARY, __DIR__.'/fixtures/release-worker.php', 'cms-job/worker/cron', '--queue=maintenance', '--maxJobs=1'];
    $cron = new Process($cronCommand, null, null, null, 10);
    $cron->run();
    $cronA->refresh(); $cronB->refresh();
    releaseCheck($cron->getExitCode() === 0 && $cronA->status === 'succeeded' && $cronB->status === 'queued', 'cron executes isolated job and respects maxJobs');
    $cron = new Process($cronCommand, null, null, null, 10);
    $cron->run(); $cronB->refresh();
    releaseCheck($cron->getExitCode() === 0 && $cronB->status === 'succeeded', 'next cron resumes pending work');
    $delayed = $app->jobs->push('release.fixture', [], ['delay' => 3600]);
    $started = microtime(true);
    $cron = new Process($cronCommand, null, null, null, 10);
    $cron->run(); $delayed->refresh();
    releaseCheck($cron->getExitCode() === 0 && microtime(true) - $started < 5 && $delayed->status === 'queued', 'cron exits without waiting for delayed jobs');
    // Disposable fixture DB only; the remaining message belongs to this test.
    $app->jobs->cancel($delayed);
    $db->createCommand()->delete('{{%cms_queue}}', ['channel' => 'maintenance'])->execute();
    require __DIR__.'/job-log-storage-smoke.php';
    echo "OK {$checks} release checks\n";
    if (in_array('--regression', $argv, true)) {
        if ($db->tablePrefix !== '') {
            throw new RuntimeException('Regression fixtures require an empty prefix.');
        }
        $failed = [];
        $db->createCommand()->insert('{{%cms_site}}', ['id' => 1])->execute();
        // Minimal scheduler schema for bridge tests; not a cms-agent migration test.
        $db->createCommand()->createTable('{{%cms_agent}}', [
            'id' => 'pk', 'name' => 'text NOT NULL', 'description' => 'text',
            'agent_interval' => 'integer NOT NULL DEFAULT 86400',
            'priority' => 'integer NOT NULL DEFAULT 100',
            'last_exec_at' => 'integer', 'next_exec_at' => 'integer',
            'is_active' => 'integer NOT NULL DEFAULT 1', 'is_system' => 'integer NOT NULL DEFAULT 0',
            'is_period' => 'integer NOT NULL DEFAULT 1', 'is_running' => 'integer NOT NULL DEFAULT 0',
            'cms_site_id' => 'integer', 'job_type' => 'varchar(128)', 'job_payload' => 'text',
        ], 'ENGINE=InnoDB')->execute();
        foreach (['job-core-smoke', 'job-fencing-smoke', 'job-requeue-smoke', 'job-transport-integration', 'job-recovery-regression', 'console-command-job-smoke'] as $test) {
            $process = new Process([PHP_BINARY, __DIR__.'/'.$test.'.php'], null, null, null, 180);
            $process->run(static function ($type, $output) { echo $output; });
            if (!$process->isSuccessful()) { $failed[] = $test; }
            // This database was created by this process and contains no site data.
            $db->createCommand()->delete('{{%cms_queue}}')->execute();
            $db->createCommand()->delete('{{%cms_job_lock}}')->execute();
            $db->createCommand()->delete('{{%cms_job_run}}')->execute();
        }
        releaseCheck(!$failed, 'regression suite: '.implode(', ', $failed));
    }
} finally {
    $app->db->close();
    if ($created) {
        $admin->createCommand('DROP DATABASE '.$admin->quoteTableName($database))->execute();
    }
    $admin->close();
    yii\helpers\FileHelper::removeDirectory(sys_get_temp_dir().'/'.$database);
}
