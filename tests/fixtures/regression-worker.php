<?php
define('ROOT_DIR', getenv('SKEEKS_APP_ROOT') ?: '/app');
define('YII_ENV', 'dev');
define('YII_DEBUG', true);
require ROOT_DIR.'/vendor/skeeks/cms/bootstrap.php';
if (!getenv('SKEEKS_JOB_RELEASE_DB')) {
    throw new RuntimeException('Only disposable regression databases are allowed.');
}
$createApplication = require __DIR__.'/test-application.php';
exit($createApplication([])->run());
