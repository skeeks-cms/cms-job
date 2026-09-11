<?php
require __DIR__.'/../src/helpers/JobDisplayStatus.php';
use skeeks\cms\job\helpers\JobDisplayStatus;
$checks = 0;
function checkDisplay($expected, $status, $result, $now = 1000) {
    global $checks; ++$checks;
    if (JobDisplayStatus::resolve($status, $result, $now) !== $expected) { throw new RuntimeException('Display status mismatch #'.$checks); }
}
$remote = [JobDisplayStatus::METADATA_KEY => ['state'=>'running','observed_at'=>990]];
checkDisplay('queued','queued',[]);
checkDisplay('queued','queued',['status'=>'running']); // Arbitrary domain results cannot override presentation.
checkDisplay('running','queued',$remote);
checkDisplay('running','running',$remote);
checkDisplay('running','queued',$remote,1110);
checkDisplay(JobDisplayStatus::STALE,'queued',$remote,1111);
foreach (['succeeded','succeeded_with_warnings','failed','cancelled','timed_out'] as $terminal) { checkDisplay($terminal,$terminal,$remote); }
foreach ([null,'990',1001] as $invalid) {
    checkDisplay(JobDisplayStatus::STALE,'queued',[JobDisplayStatus::METADATA_KEY=>['state'=>'running','observed_at'=>$invalid]]);
}
checkDisplay('queued','queued',[JobDisplayStatus::METADATA_KEY=>['state'=>'pending','observed_at'=>990]]);
checkDisplay('queued','queued',[JobDisplayStatus::METADATA_KEY=>'running']);
$continuation = [JobDisplayStatus::METADATA_KEY => ['state'=>JobDisplayStatus::CONTINUATION]];
checkDisplay(JobDisplayStatus::CONTINUATION, 'queued', $continuation);
checkDisplay(JobDisplayStatus::CONTINUATION, 'queued', $continuation, 999999);
checkDisplay('running', 'running', $continuation);
foreach (['succeeded','failed','cancelled','timed_out'] as $terminal) { checkDisplay($terminal,$terminal,$continuation); }
echo "OK: $checks display-status checks\n";
