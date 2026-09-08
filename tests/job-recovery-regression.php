<?php
// Real child processes, private transport channel, cleanup limited to this run.
use skeeks\cms\job\models\CmsJobRun;
use skeeks\cms\job\transport\WorkerOptions;
use skeeks\cms\job\transport\yii2queue\CmsJobEnvelope;

$correlation = 'recovery-'.getmypid().'-'.bin2hex(random_bytes(4));
putenv('SKEEKS_JOB_TEST_CHANNEL='.$correlation);
$_SERVER['SKEEKS_JOB_TEST_CHANNEL'] = $correlation;
$_ENV['SKEEKS_JOB_TEST_CHANNEL'] = $correlation;
$app = require __DIR__.'/fixtures/recovery-app.php';
$failures = [];
$checks = 0;
function checkRecovery($condition, $message) {
    global $checks, $failures;
    $checks++;
    echo ($condition ? 'ok ' : 'FAIL ').$message."\n";
    if (!$condition) { $failures[] = $message; }
}
$push = function ($mode = 'ok', $type = 'unsafe') use ($correlation) {
    return Yii::$app->jobs->push('recovery.'.$type, ['mode' => $mode], ['correlationId' => $correlation, 'priority' => 234]);
};
$messages = function () use ($correlation) {
    return (new yii\db\Query())->from('{{%cms_queue}}')->where(['channel' => $correlation])->orderBy('id')->all();
};
$consume = function ($isolate = true, $maxJobs = 1) {
    return Yii::$app->jobConsumer->consume(new WorkerOptions([
        'queue' => 'recovery', 'once' => true, 'timeout' => 1,
        'isolate' => $isolate, 'maxJobs' => $maxJobs, 'verbose' => true,
    ]));
};
try {
    foreach (['crash', 'signal', 'sleep'] as $bootMode) {
        $run = $push();
        putenv('SKEEKS_JOB_TEST_BOOT_MODE='.$bootMode);
        $_ENV['SKEEKS_JOB_TEST_BOOT_MODE'] = $bootMode;
        $consume();
        putenv('SKEEKS_JOB_TEST_BOOT_MODE');
        unset($_ENV['SKEEKS_JOB_TEST_BOOT_MODE']);
        $run->refresh();
        $rows = $messages();
        checkRecovery($run->status === 'queued' && !$run->execution_token, $bootMode.': still unclaimed');
        checkRecovery(count($rows) === 1 && $rows[0]['reserved_at'] === null, $bootMode.': delivery released');
        checkRecovery(count($rows) === 1 && (int)$rows[0]['delay'] === 1, $bootMode.': explicit redelivery delay');
        sleep(2);
        $consume();
        $run->refresh();
        checkRecovery($run->status === 'succeeded' && (int)$run->attempt === 1, $bootMode.': redelivery executes once');
        checkRecovery(count($messages()) === 0, $bootMode.': message acknowledged after execution');
    }

    $a = $push(); $b = $push();
    $consume();
    $a->refresh(); $b->refresh();
    checkRecovery($a->status === 'succeeded' && $b->status === 'queued', 'isolated maxJobs=1');
    $consume(false);
    checkRecovery(Yii::$app->jobQueueFactory->get('recovery')->messageHandler === null, 'isolation handler restored');
    $b->refresh();
    checkRecovery($b->status === 'succeeded', 'non-isolated consume after isolated consume');

    $hung = $push('sleep');
    $start = microtime(true);
    $consume();
    $elapsed = microtime(true) - $start;
    $hung->refresh();
    checkRecovery($hung->status === 'timed_out' && $hung->error_code === 'timeout', 'real Process TTR terminates claimed child');
    checkRecovery($elapsed >= 1.5 && $elapsed < 10, 'real timeout timing, not a bootstrap failure');

    $retry = $push('sleep-once', 'safe');
    $consume();
    $retry->refresh();
    checkRecovery($retry->status === 'queued' && count($messages()) === 1, 'idempotent timeout publishes exactly one retry');
    sleep(2);
    $consume();
    $retry->refresh();
    checkRecovery($retry->status === 'succeeded' && (int)$retry->attempt === 2, 'idempotent timeout resumes on second business attempt');

    $a = $push('pause'); $b = $push('pause');
    $command = [PHP_BINARY, __DIR__.'/fixtures/recovery-worker.php', 'cms-job/worker', '--queue=recovery', '--once=1', '--maxJobs=1'];
    $workerA = new Symfony\Component\Process\Process($command, ROOT_DIR, null, null, 10);
    $workerB = new Symfony\Component\Process\Process($command, ROOT_DIR, null, null, 10);
    $workerA->start(); $workerB->start();
    try {
        checkRecovery($workerA->wait() === 0 && $workerB->wait() === 0, 'two parallel workers exit successfully');
    } finally {
        if ($workerA->isRunning()) { $workerA->stop(1); }
        if ($workerB->isRunning()) { $workerB->stop(1); }
    }
    $a->refresh(); $b->refresh();
    checkRecovery($a->status === 'succeeded' && $b->status === 'succeeded' && (int)$a->attempt === 1 && (int)$b->attempt === 1, 'parallel workers execute each run once');

    $a = $push('pause'); $b = $push();
    $worker = new Symfony\Component\Process\Process([
        PHP_BINARY, __DIR__.'/fixtures/recovery-worker.php', 'cms-job/worker', '--queue=recovery', '--idleDelay=1',
    ], ROOT_DIR, null, null, 10);
    $worker->start();
    try {
        $deadline = microtime(true) + 5;
        do { usleep(20000); $a->refresh(); } while ($a->status === 'queued' && microtime(true) < $deadline);
        checkRecovery($a->status === 'running', 'SIGTERM test reached running child');
        $worker->signal(SIGTERM);
        checkRecovery($worker->wait() === 0, 'worker exits gracefully on SIGTERM');
    } finally {
        if ($worker->isRunning()) { $worker->stop(1); }
    }
    $a->refresh(); $b->refresh();
    checkRecovery($a->status === 'succeeded' && $b->status === 'queued', 'SIGTERM finishes current run without taking next');
    $consume();

    $crashedSafe = $push('signal', 'safe');
    $crashedUnsafe = $push('crash');
    $consume(); $consume();
    $crashedSafe->refresh(); $crashedUnsafe->refresh();
    checkRecovery($crashedSafe->status === 'running' && $crashedUnsafe->status === 'running' && count($messages()) === 0, 'claimed crashes await lease recovery');
    // The production reaper scans all expired runs: never run it if a foreign
    // expired operation is present in this local development database.
    if (CmsJobRun::find()->where(['status' => 'running'])->andWhere(['<', 'lease_until', time()])
        ->andWhere(['or', ['correlation_id' => null], ['<>', 'correlation_id', $correlation]])->exists()) {
        throw new RuntimeException('Reaper test blocked by a foreign expired run.');
    }
    CmsJobRun::updateAll(['lease_until' => time() - 2], ['id' => [$crashedSafe->id, $crashedUnsafe->id]]);
    $isIdempotent = function ($run) { return Yii::$app->jobRegistry->get($run->job_type)->idempotent; };
    $reaped = Yii::$app->jobRunStore->reapExpired($isIdempotent);
    $crashedSafe->refresh(); $crashedUnsafe->refresh();
    checkRecovery($reaped === ['requeued' => 1, 'timed_out' => 1] && $crashedSafe->status === 'queued' && $crashedUnsafe->status === 'timed_out', 'reaper retries only declared idempotent crash');
    $rows = $messages();
    checkRecovery(count($rows) === 1 && (int)$rows[0]['priority'] === 234 && (int)$rows[0]['ttr'] === 2, 'reaper preserves delivery metadata');
    Yii::$app->jobRunStore->reapExpired($isIdempotent);
    checkRecovery(count($messages()) === 1, 'second reaper adds no duplicate');
    CmsJobRun::updateAll(['payload_json' => json_encode(['mode' => 'ok'])], ['id' => $crashedSafe->id]);
    $consume();
    $crashedSafe->refresh();
    checkRecovery($crashedSafe->status === 'succeeded' && (int)$crashedSafe->attempt === 2, 'reaped crash executes again through transport');

    $run = $push();
    $token = Yii::$app->jobRunStore->claim((int)$run->id, 'test-owner', 60);
    Yii::$app->jobRunner->failUnsupportedEnvelope((int)$run->id, 999);
    $run->refresh();
    checkRecovery($run->status === 'running' && $run->execution_token === $token, 'unsupported envelope cannot fail active owner');
    $eventsBefore = $run->getEvents()->count();
    Yii::$app->jobRunner->failHardTimeout((int)$run->id, 2, str_repeat('a', 32));
    $run->refresh();
    checkRecovery($run->status === 'running' && $run->execution_token === $token, 'timeout requires original attempt token');
    checkRecovery($run->getEvents()->count() === $eventsBefore, 'fenced timeout creates no event');
    $failure = new ReflectionMethod(Yii::$app->jobRunner, 'handleFailure');
    $failure->setAccessible(true);
    $failure->invoke(Yii::$app->jobRunner, $run, Yii::$app->jobRegistry->get($run->job_type), 'stale-token', new RuntimeException('stale failure'));
    $run->refresh();
    checkRecovery($run->status === 'running' && $run->getEvents()->count() === $eventsBefore, 'stale failure writes neither final state nor event');
    Yii::$app->jobRunStore->finish((int)$run->id, $token, ['status' => 'succeeded']);
    $consume();

    $run = $push();
    $before = $messages()[0];
    $token = Yii::$app->jobRunStore->claim((int)$run->id, 'test-owner', 60);
    Yii::$app->jobRunStore->requeue((int)$run->id, $token, 'recovery', 3);
    $rows = $messages(); $after = end($rows);
    checkRecovery((int)$before['priority'] === (int)$after['priority'] && (int)$before['ttr'] === (int)$after['ttr'], 'requeue preserves priority and TTR');
    $token = Yii::$app->jobRunStore->claim((int)$run->id, 'test-owner', 60);
    $messageCount = count($messages());
    $publisher = Yii::$app->jobRunStore->publisher;
    Yii::$app->jobRunStore->publisher = new class extends \skeeks\cms\job\transport\CollectingPublisher {
        public function publish(\skeeks\cms\job\transport\JobTransportMessage $message, string $queue, int $delay = 0, int $priority = 0, int $ttr = 0): ?string {
            throw new RuntimeException('injected publish failure');
        }
    };
    try {
        Yii::$app->jobRunStore->requeue((int)$run->id, $token, 'recovery', 3, false, [], ['level' => 'warning', 'message' => 'must roll back']);
        checkRecovery(false, 'publication failure must propagate');
    } catch (RuntimeException $e) {
        checkRecovery($e->getMessage() === 'injected publish failure', 'publication failure propagates');
    } finally {
        Yii::$app->jobRunStore->publisher = $publisher;
    }
    $run->refresh();
    checkRecovery($run->status === 'running' && $run->execution_token === $token && count($messages()) === $messageCount && (int)$run->getEvents()->count() === 0, 'failed publication rolls back state and event');
} finally {
    putenv('SKEEKS_JOB_TEST_BOOT_MODE');
    Yii::$app->db->createCommand()->delete('{{%cms_queue}}', ['channel' => $correlation])->execute();
    CmsJobRun::deleteAll(['correlation_id' => $correlation]);
    putenv('SKEEKS_JOB_TEST_CHANNEL');
    unset($_ENV['SKEEKS_JOB_TEST_CHANNEL'], $_SERVER['SKEEKS_JOB_TEST_CHANNEL'], $_ENV['SKEEKS_JOB_TEST_BOOT_MODE']);
}
echo "Recovery: {$checks} checks, ".count($failures)." failures\n";
exit($failures ? 1 : 0);
