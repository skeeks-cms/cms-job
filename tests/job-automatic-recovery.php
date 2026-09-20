<?php
use yii\db\Query;
use skeeks\cms\job\models\CmsJobRun;
use Symfony\Component\Process\Process;
// Included only by job-release-install.php, after the disposable schema is built.
$lost = $app->jobs->push('release.recovery');
$cancelled = $app->jobs->push('release.recovery');
$exhausted = $app->jobs->push('release.fixture');
$live = $app->jobs->push('release.fixture');
foreach ([$lost,$cancelled,$exhausted,$live] as $run) {
    $app->jobRunStore->claim((int)$run->id,'lost-test-worker',120);
}
$ids=array_map(static fn($r)=>(int)$r->id,[$lost,$cancelled,$exhausted]);
CmsJobRun::updateAll(['lease_until'=>time()-2],['id'=>$ids]);
CmsJobRun::updateAll(['cancel_requested_at'=>time()],['id'=>$cancelled->id]);
// This disposable queue now models consumed messages whose owner vanished.
$db->createCommand()->delete('{{%cms_queue}}')->execute();
$worker = new Process([PHP_BINARY,__DIR__.'/fixtures/release-worker.php','cms-job/worker','--queue=default','--once=1'],null,null,null,15);
$worker->run();
foreach ([$lost,$cancelled,$exhausted,$live] as $run) $run->refresh();
releaseCheck($worker->getExitCode()===0,'idle worker automatically runs recovery');
releaseCheck($lost->status==='queued','expired safe run requeued without manual reap');
releaseCheck($cancelled->status==='cancelled' && $cancelled->dedup_active===null,'cancelled lost run finalized without retry');
releaseCheck($exhausted->status==='timed_out' && $exhausted->dedup_active===null,'exhausted run releases overlap guard');
releaseCheck($live->status==='running','live lease remains untouched');
releaseCheck((new Query())->from('{{%cms_queue}}')->count()==1,'recovery publishes exactly one safe retry');
$worker->run();
releaseCheck((new Query())->from('{{%cms_queue}}')->count()==1,'second recovery does not duplicate delivery');
$worker = new Process([PHP_BINARY,__DIR__.'/fixtures/release-worker.php','cms-job/worker','--queue=maintenance','--once=1'],null,null,null,15);
$worker->run();$lost->refresh();
releaseCheck($lost->status==='succeeded' && (int)$lost->attempt===2,'automatic retry reaches handler');
$app->jobRunStore->finish((int)$live->id,(string)$live->execution_token,['status'=>'succeeded','dedup_active'=>null]);
