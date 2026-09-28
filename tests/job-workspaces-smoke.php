<?php
// No site bootstrap, credentials, network, production DB or persistent test data.
define('YII_ENABLE_ERROR_HANDLER', false);
$vendor = getenv('SKEEKS_TEST_VENDOR') ?: '/app/vendor';
$loader = require $vendor.'/autoload.php';
$loader->addPsr4('skeeks\\cms\\job\\', dirname(__DIR__).'/src', true);
require $vendor.'/yiisoft/yii2/Yii.php';

use skeeks\cms\job\models\CmsJobRun;
use skeeks\cms\job\runtime\JobContext;
use skeeks\cms\job\runtime\JobWorkspaceStorage;
use skeeks\cms\job\runtime\JobHistoryCleanup;
use skeeks\cms\job\exceptions\JobFencedException;
use skeeks\cms\job\exceptions\JobRequeueException;
use skeeks\cms\job\exceptions\JobCancelledException;

class WorkspacePublisher extends yii\base\BaseObject implements skeeks\cms\job\contracts\JobPublisherInterface {
    public function publish(skeeks\cms\job\transport\JobTransportMessage $message, string $queue, int $delay=0, int $priority=0, int $ttr=0): ?string { return 'fixture'; }
    public function supports(string $queue): bool { return true; }
}
class WorkspaceFixtureHandler extends skeeks\cms\job\handlers\AbstractJobHandler {
    public static $workspace;
    public function run(JobContext $context, skeeks\cms\job\contracts\JobReporterInterface $reporter): void {
        self::$workspace=$context->getWorkspace();
        file_put_contents(self::$workspace->path('data.txt'),'retained between deliveries');
        switch ($context->getPayload()['mode'] ?? '') {
            case 'requeue': $context->setCursor(['offset'=>3]); $context->requestRequeue(1); break;
            case 'cancel': throw new JobCancelledException('fixture');
            case 'fail': throw new RuntimeException('fixture');
            case 'fence':
                CmsJobRun::updateAll(['execution_token'=>'replacement'],['id'=>$context->getRun()->id]);
                self::$workspace->path('blocked.txt'); break;
        }
        $reporter->countSuccess();
    }
}
$root=sys_get_temp_dir().'/cms-job-workspaces-'.bin2hex(random_bytes(8));
mkdir($root,0700);
$config=require dirname(__DIR__).'/src/config/common.php';
$app=new yii\console\Application(['id'=>'workspace-test','basePath'=>$root,'vendorPath'=>$vendor,'runtimePath'=>$root.'/runtime','extensions'=>[],
 'components'=>[
  'db'=>['class'=>yii\db\Connection::class,'dsn'=>'sqlite::memory:'],
  'jobWorkspaces'=>['class'=>JobWorkspaceStorage::class,'basePath'=>$root.'/workspaces','historyProtectionRoots'=>[$root.'/legacy']],
  'jobRegistry'=>['class'=>skeeks\cms\job\JobRegistry::class,'types'=>[
    'fixture'=>['handler'=>WorkspaceFixtureHandler::class,'queue'=>'maintenance','idempotent'=>true,'maxAttempts'=>1],
    'cms-job.cleanup-workspaces'=>$config['components']['jobRegistry']['types']['cms-job.cleanup-workspaces'],
  ]],
  'jobPublisher'=>WorkspacePublisher::class,
  'jobRunStore'=>skeeks\cms\job\runtime\JobRunStore::class,
  'jobLockManager'=>skeeks\cms\job\runtime\LockManager::class,
  'jobRunner'=>skeeks\cms\job\runtime\CmsJobRunner::class,
 ]]);
$db=$app->db;
$db->open();
// Match the existing MySQL store expression in this isolated SQLite fixture.
$db->pdo->sqliteCreateFunction('GREATEST', static fn($a,$b)=>max($a,$b), 2);
$db->createCommand("CREATE TABLE cms_job_run (
 id INTEGER PRIMARY KEY, uid TEXT, cms_site_id INTEGER, job_type TEXT, job_version INTEGER DEFAULT 1,
 queue_name TEXT, visibility TEXT DEFAULT 'visible', title TEXT, trigger_type TEXT, trigger_ref TEXT,
 created_by INTEGER, correlation_id TEXT, parent_id INTEGER, root_id INTEGER, retry_of_id INTEGER,
 status TEXT, priority INTEGER DEFAULT 100, attempt INTEGER DEFAULT 0, max_attempts INTEGER DEFAULT 1,
 available_at INTEGER DEFAULT 0, lease_until INTEGER, execution_token TEXT, worker_id TEXT, worker_pid INTEGER,
 cancel_requested_at INTEGER, cancel_requested_by INTEGER, dedup_key TEXT, dedup_active TEXT, resource_key TEXT,
 overlap_policy TEXT, stage TEXT, progress_current INTEGER DEFAULT 0, progress_total INTEGER, progress_message TEXT,
 success_count INTEGER DEFAULT 0, warning_count INTEGER DEFAULT 0, error_count INTEGER DEFAULT 0,
 skipped_count INTEGER DEFAULT 0, skipped_runs INTEGER DEFAULT 0, payload_json TEXT, cursor_json TEXT,
 result_json TEXT, error_code TEXT, error_message TEXT, created_at INTEGER, updated_at INTEGER,
 started_at INTEGER, finished_at INTEGER, retention_until INTEGER, lock_version INTEGER DEFAULT 0)")->execute();
$db->createCommand('CREATE TABLE cms_job_run_event (id INTEGER PRIMARY KEY, cms_job_run_id INTEGER, level TEXT, stage TEXT, message TEXT, context_json TEXT, created_at INTEGER)')->execute();
$db->createCommand('CREATE TABLE cms_job_run_artifact (id INTEGER PRIMARY KEY, cms_job_run_id INTEGER, log_path TEXT)')->execute();
$storage=$app->jobWorkspaces;
$checks=0;
function ok($condition,string $message): void { global $checks; if(!$condition) throw new RuntimeException($message); ++$checks; }
function rejects(callable $call,string $class,string $message): void {
    try{$call();}catch(Throwable $e){ok($e instanceof $class,$message.': '.get_class($e));return;}throw new RuntimeException($message);
}
function runRow(int $id,string $status='running',array $extra=[]): CmsJobRun {
    Yii::$app->db->createCommand()->insert('cms_job_run',array_merge([
        'id'=>$id,'cms_site_id'=>1,'job_type'=>'fixture','queue_name'=>'maintenance','status'=>$status,
        'execution_token'=>'token-'.$id,'lease_until'=>time()+3600,'payload_json'=>'{}','cursor_json'=>'{}',
        'created_at'=>time()-20*86400,'updated_at'=>time(),'visibility'=>'visible'], $extra))->execute();
    return CmsJobRun::findOne($id);
}
function finish(int $id,string $status='succeeded',int $age=8): void {
    CmsJobRun::updateAll(['status'=>$status,'finished_at'=>time()-$age*86400,'retention_until'=>time()-1,
        'execution_token'=>null,'lease_until'=>null],['id'=>$id]);
}
function context(int $id): JobContext { return new JobContext(['run'=>CmsJobRun::findOne($id)]); }
function reasons(array $page): array { return array_column($page['items'],'reason','run_id'); }
function makeWorkspace(int $id): string {
    runRow($id);$c=context($id);$p=$c->getWorkspace()->path('file.txt');file_put_contents($p,'content');
    $c->releaseWorkspace();return $p;
}
try {
    ok($storage->sweep()['items']===[] && !file_exists($root.'/workspaces'),'Dry-run of absent root writes nothing');
    runRow(1);
    $c=context(1);$w=$c->getWorkspace();$file=$w->path('offers.jsonl');file_put_contents($file,"one\ntwo\n");
    ok($c->getWorkspace()===$w,'One context owns one workspace lock');
    foreach(['../escape','/absolute','a/../../b','x\\y','a//b'] as $name){
        rejects(fn()=>$w->path($name),InvalidArgumentException::class,'Traversal rejected');
    }
    rejects(fn()=>context(1)->getWorkspace(),JobRequeueException::class,'Second delivery cannot open workspace concurrently');
    finish(1,'timed_out',20);
    ok(reasons($storage->sweep(false))[1]==='busy' && is_file($file),'Live expired worker protects terminal workspace');
    rejects(fn()=>$w->path('late'),JobFencedException::class,'Old worker cannot request paths after fencing');
    $c->releaseWorkspace();
    rejects(fn()=>$w->path(),LogicException::class,'Released workspace cannot be reused');
    $hash=hash_file('sha256',$file);
    $plan=$storage->sweep(true);
    ok(reasons($plan)[1]==='candidate' && hash_file('sha256',$file)===$hash,'Read-only candidate leaves content unchanged');
    ok((new JobHistoryCleanup())->cleanup()===0 && CmsJobRun::findOne(1),'History retained until workspace deletion');
    rejects(fn()=>CmsJobRun::findOne(1)->delete(),yii\base\InvalidCallException::class,'Model/admin deletion preserves workspace owner');
    $clean=$storage->sweep(false);
    ok($clean['deleted']===1 && !file_exists($file) && !$storage->protectsHistory(1),'Expired terminal workspace and receipt removed');
    ok((new JobHistoryCleanup())->cleanup()===1 && !CmsJobRun::findOne(1),'History removed after resource deletion');

    $file=makeWorkspace(2);
    CmsJobRun::updateAll(['status'=>'queued'],['id'=>2]);
    ok(reasons($storage->sweep(false))[2]==='unfinished' && is_file($file),'Queued continuation protected');
    CmsJobRun::updateAll(['status'=>'running','execution_token'=>'new-owner','lease_until'=>time()+100],['id'=>2]);
    $c=context(2);ok(file_get_contents($c->getWorkspace()->path('file.txt'))==='content','Automatic retry reuses same data');$c->releaseWorkspace();
    $other=makeWorkspace(3);ok($other!==$file,'Manual new run gets distinct workspace');
    finish(2);finish(3,'failed',8);
    $storage->setHold(2,true);
    $why=reasons($storage->sweep(false));
    ok($why[2]==='hold' && $why[3]==='retained','Hold and 14-day failure retention respected');
    $storage->setHold(2,false);finish(3,'failed',15);
    ok($storage->sweep(false)['deleted']===2,'Release hold and expired failure become removable');

    makeWorkspace(4);finish(4);
    $outside=$root.'/outside';file_put_contents($outside,'never remove');
    symlink($outside,$root.'/workspaces/runs/4/data/link');
    ok(reasons($storage->sweep(false))[4]==='unsafe_or_error' && is_file($outside),'Symlink blocks entire tree');
    unlink($root.'/workspaces/runs/4/data/link');
    makeWorkspace(5);finish(5);
    file_put_contents($root.'/workspaces/runs/5/manifest.json','{}');
    ok(reasons($storage->sweep(false))[5]==='unsafe_or_error','Unknown ownership kept');
    makeWorkspace(6);finish(6);CmsJobRun::deleteAll(['id'=>6]);
    ok(reasons($storage->sweep(false))[6]==='missing_run','Missing history never implies safe deletion');

    // Crash after quarantine: cancellation occurs during postorder deletion, not before rename.
    $file=makeWorkspace(7);finish(7);
    rejects(function() use($storage,$root){
        $storage->sweep(false,1,6,function()use($root){if(is_dir($root.'/workspaces/trash/7'))throw new JobCancelledException('interrupt');});
    },JobCancelledException::class,'Cancellation during deletion propagates');
    ok(is_file($root.'/workspaces/trash/7.json') && $storage->protectsHistory(7),'Durable receipt protects interrupted cleanup');
    ok($storage->sweep(false,1,6)['deleted']===1,'Interrupted quarantine is resumed');
    $file=makeWorkspace(8);finish(8);
    $live=$root.'/workspaces/runs/8';$trash=$root.'/workspaces/trash/8';
    copy($live.'/manifest.json',$trash.'.json');rename($live,$trash);
    unlink($trash.'/data/file.txt');rmdir($trash.'/data');unlink($trash.'/manifest.json');
    ok($storage->sweep(false,1,7)['deleted']===1,'Crash after removing manifest resumes from receipt');

    makeWorkspace(20);finish(20,'timed_out',20);
    $lockPath=$root.'/workspaces/runs/20/.lock';
    $process=proc_open([PHP_BINARY,'-r', '$h=fopen($argv[1],"r");if(!flock($h,LOCK_EX|LOCK_NB)){exit(2);}echo "locked\\n";fflush(STDOUT);sleep(30);','--',$lockPath],
        [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
    ok(is_resource($process) && trim(fgets($pipes[1]))==='locked','Independent worker holds physical lock');
    ok(reasons($storage->sweep(false,1,19))[20]==='busy','Cleaner cannot delete files held by another process');
    proc_terminate($process,9);
    foreach($pipes as $pipe){fclose($pipe);}proc_close($process);
    ok($storage->sweep(false,1,19)['deleted']===1,'OS releases lock after worker death');

    makeWorkspace(21);finish(21);
    $racingReason=null;
    $page=$storage->sweep(false,1,20,function()use($storage,$root,&$racingReason){
        if($racingReason===null && is_dir($root.'/workspaces/trash/21')) {
            $racingReason=reasons($storage->sweep(false,1,20))[21];
        }
    });
    ok($racingReason==='busy' && $page['deleted']===1,'Second cleaner cannot race a quarantined directory');

    foreach(['cancelled','succeeded_with_warnings'] as $offset=>$status){
        $id=22+$offset;makeWorkspace($id);finish($id,$status,8);
        ok(reasons($storage->sweep(false,1,$id-1))[$id]==='retained','Diagnostic retention: '.$status);
        finish($id,$status,15);ok($storage->sweep(false,1,$id-1)['deleted']===1,'Diagnostic expiry: '.$status);
    }

    runRow(9,'succeeded',['finished_at'=>time()-20*86400,'retention_until'=>time()-1]);
    mkdir($root.'/legacy/9',0700,true);file_put_contents($root.'/legacy/9/source.xml','legacy');
    $history=new JobHistoryCleanup();$history->cleanup(100);
    ok(CmsJobRun::findOne(9)!==null && is_file($root.'/legacy/9/source.xml'),'Legacy snapshots protect history without adoption');
    runRow(10,'succeeded',['finished_at'=>time()-20*86400,'retention_until'=>time()-1]);
    $history->cleanup(1,null,8);$last=$history->lastScannedId;
    ok($last===9 && $history->scannedCount===1,'Skipped held history advances scan cursor');
    ok($history->cleanup(1,null,$last)===1 && !CmsJobRun::findOne(10),'Held history cannot starve following records');

    // Actual runner finally releases locks on every outcome, including transient success.
    foreach(['success','requeue','cancel','fail','fence'] as $index=>$mode) {
        $id=100+$index;runRow($id,'queued',['execution_token'=>null,'lease_until'=>null,
            'visibility'=>'transient','payload_json'=>json_encode(['mode'=>$mode])]);
        $app->jobRunner->execute($id);
        $row=CmsJobRun::findOne($id);
        $expected=['success'=>'succeeded','requeue'=>'queued','cancel'=>'cancelled','fail'=>'failed','fence'=>'running'][$mode];
        ok($row && $row->status===$expected,'Runner outcome and retained workspace history: '.$mode);
        rejects(fn()=>WorkspaceFixtureHandler::$workspace->path(),LogicException::class,'Runner closes retained reference: '.$mode);
        $handle=fopen($root.'/workspaces/runs/'.$id.'/.lock','r');
        ok(flock($handle,LOCK_EX|LOCK_NB),'Runner releases physical lock: '.$mode);flock($handle,LOCK_UN);fclose($handle);
    }
    ok(CmsJobRun::findOne(101)->getCursor()['offset']===3,'Runner persists continuation cursor');
    for($id=300;$id<405;++$id){makeWorkspace($id);finish($id);}
    runRow(200,'queued',['job_type'=>'cms-job.cleanup-workspaces','execution_token'=>null,'lease_until'=>null]);
    $app->jobRunner->execute(200);
    $maintenance=CmsJobRun::findOne(200);
    ok($maintenance->status==='queued' && $maintenance->getCursor()['after']>300,'Workspace maintenance continues past its bounded page');
    CmsJobRun::updateAll(['available_at'=>0],['id'=>200]);
    $app->jobRunner->execute(200);$maintenance->refresh();
    ok($maintenance->status==='succeeded_with_warnings' && $maintenance->getResult()['deleted']===105,'Scheduled handler drains backlog and preserves visible warnings');
    ok(is_dir($root.'/workspaces/runs/5') && is_dir($root.'/workspaces/runs/6'),'Scheduled handler leaves ambiguous workspaces intact');
    echo "PASS: {$checks} workspace lifecycle checks\n";
} finally {$db->close();yii\helpers\FileHelper::removeDirectory($root);}
