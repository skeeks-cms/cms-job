<?php
if (getenv('SKEEKS_JOB_TEST_BOOT_MODE') === 'crash') {
    exit(42);
}
if (getenv('SKEEKS_JOB_TEST_BOOT_MODE') === 'signal') {
    posix_kill(getmypid(), SIGKILL);
}
if (getenv('SKEEKS_JOB_TEST_BOOT_MODE') === 'sleep') {
    sleep(20);
}
$app = require __DIR__.'/recovery-app.php';
exit($app->run());
