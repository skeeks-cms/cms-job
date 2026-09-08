<?php
// Included by job-release-install.php: disposable database/runtime only.
use skeeks\cms\job\models\CmsJobRunArtifact;
use skeeks\cms\job\runtime\JobReporter;

$logs = $app->jobLogs;
$reporter = new JobReporter(['run' => $run, 'definition' => $app->jobRegistry->get('release.fixture')]);
$path = $logs->create($run);
$key = $logs->key($path);
releaseCheck(strpos($key, 'maintenance/'.gmdate('Y/m/d').'/'.intdiv($run->id, 1000).'/'.$run->id.'-') === 0, 'logs partitioned by queue/date/run bucket');
$retryPath = $logs->create($run);
releaseCheck($retryPath !== $path, 'attempts have distinct filenames');
$logs->remove($logs->key($retryPath));
file_put_contents($path, 'private diagnostics');
$artifact = $reporter->addArtifact(CmsJobRunArtifact::TYPE_LOG, $path);
releaseCheck($artifact->log_path === $key && !$artifact->cms_storage_file_id, 'log registered without CMS upload');
releaseCheck((int)$artifact->size === 19 && $artifact->expires_at > time(), 'size and expiry recorded');
$rejected = false;
try { $logs->resolve('../secret'); } catch (InvalidArgumentException $e) { $rejected = true; }
releaseCheck($rejected, 'traversal rejected');
$outside = $app->runtimePath.'/outside';
mkdir($outside);
symlink($outside, $logs->root().'/escaped');
$rejected = false;
try { $logs->remove('escaped/2026/09/07/0/1-'.str_repeat('a', 32).'.log'); } catch (RuntimeException $e) { $rejected = true; }
releaseCheck($rejected && is_dir($outside), 'missing file through symlink cannot prune outside storage');
unlink($logs->root().'/escaped');
rmdir($outside);
$source = $app->runtimePath.'/import.log';
file_put_contents($source, str_repeat('x', 5000));
$logs->maxBytes = 1024;
$imported = $reporter->addArtifact(CmsJobRunArtifact::TYPE_LOG, $source);
releaseCheck($imported->size == 1024 && filesize($logs->resolve($imported->log_path)) === 1024, 'external log import is bounded');
unlink($source);
$csvSource = $app->runtimePath.'/report.csv';
$csvBytes = "item;message\n".str_repeat("1;ошибка\n", 1000);
file_put_contents($csvSource, $csvBytes);
$csvArtifact = $reporter->addArtifact(CmsJobRunArtifact::TYPE_ERROR_REPORT, $csvSource);
$csvPath = $logs->resolve($csvArtifact->log_path);
releaseCheck(!$csvArtifact->cms_storage_file_id && substr($csvPath, -4) === '.csv'
    && file_get_contents($csvPath) === $csvBytes, 'error report is private and never truncated by console limit');
unlink($csvSource);
// Two deliveries must retain independent files, even within the same second.
$reports = [];
foreach ([1, 2] as $item) {
    $partReporter = new JobReporter(['run' => $run, 'definition' => $app->jobRegistry->get('release.fixture')]);
    $append = new ReflectionMethod(JobReporter::class, 'appendErrorCsv');
    $append->setAccessible(true);
    $append->invoke($partReporter, 'site', $item, 'Ошибка', []);
    $close = new ReflectionMethod(JobReporter::class, 'closeErrorCsv');
    $close->setAccessible(true);
    $close->invoke($partReporter);
    $reports[] = CmsJobRunArtifact::find()->where(['cms_job_run_id' => $run->id])->orderBy(['id' => SORT_DESC])->one();
}
releaseCheck($reports[0]->name !== $reports[1]->name && $reports[0]->log_path !== $reports[1]->log_path
    && is_file($logs->resolve($reports[0]->log_path)) && is_file($logs->resolve($reports[1]->log_path)),
    'continuation CSV fragments have unique names and survive finalization');
$artifact->updateAttributes(['expires_at' => time() - 1]);
$run->updateAttributes(['status' => 'running']);
releaseCheck($logs->cleanup() === 0 && is_file($path), 'expired active log preserved');
$run->updateAttributes(['status' => 'succeeded']);
releaseCheck($logs->cleanup() === 1 && !file_exists($path), 'expired finished log deleted');
$artifact->refresh();
releaseCheck($artifact->log_path === null && $run->refresh(), 'expiry keeps artifact metadata and run history');
releaseCheck($logs->cleanup() === 0, 'cleanup is idempotent');
$oldOrphan = $logs->create($run);
touch($oldOrphan, time() - 15 * 86400);
$freshOrphan = $logs->create($run);
releaseCheck($logs->cleanupOrphans() === 1 && !file_exists($oldOrphan) && is_file($freshOrphan), 'only old orphan removed');
$run->updateAttributes(['status' => 'running']);
touch($freshOrphan, time() - 15 * 86400);
releaseCheck($logs->cleanupOrphans() === 0 && is_file($freshOrphan), 'active orphan preserved');
$run->updateAttributes(['status' => 'succeeded']);
$logs->cleanupOrphans();
$logs->maxBytes = 5242880;

// Exercise the download action against isolated data, with explicit identities.
class LogTestUser extends yii\base\Component {
    public $isGuest = false;
    public $allowed = true;
    public function can($permission) { return $this->allowed; }
}
class LogTestSite extends yii\base\Component { public $site; }
$db->createCommand()->insert('{{%cms_site}}', ['id' => 987])->execute();
$run->updateAttributes(['cms_site_id' => 987]);
$app->set('user', new LogTestUser());
$app->set('skeeks', new LogTestSite(['site' => (object)['id' => 987]]));
$oldRequest = $app->request;
$oldResponse = $app->response;
$app->set('request', new yii\web\Request(['cookieValidationKey' => 'isolated-fixture']));
$app->set('response', new yii\web\Response());
$controller = (new ReflectionClass(skeeks\cms\job\controllers\AdminCmsJobRunController::class))->newInstanceWithoutConstructor();
$controller->permissionName = 'test.admin';
$response = $controller->actionLog($csvArtifact->id);
releaseCheck(strpos($response->headers->get('Content-Type'), 'text/csv') === 0
    && $response->headers->get('Cache-Control') === 'private, no-store', 'CSV download is private attachment');
if (is_array($response->stream) && is_resource($response->stream[0])) { fclose($response->stream[0]); }
$rejected = false;
try { $controller->actionLogChunk($csvArtifact->id); } catch (yii\web\NotFoundHttpException $e) { $rejected = true; }
releaseCheck($rejected, 'CSV cannot enter console streaming endpoint');
$previewPath = $logs->resolve($imported->log_path);
file_put_contents($previewPath, "\033[31m<script>alert(1)</script>\033[0m");
$html = $controller->renderLogPreview($imported->id);
releaseCheck(strpos($html, '&lt;script&gt;') !== false && strpos($html, '<script>') === false
    && strpos($html, '[31m') === false, 'preview escapes HTML and strips ANSI');
file_put_contents($previewPath, str_repeat('x', 300000).'END');
$preview = $logs->preview($imported->log_path);
releaseCheck($preview['truncated'] && strlen($preview['text']) === 262144
    && substr($preview['text'], -3) === 'END', 'preview reads bounded tail of large file');
releaseCheck(strpos($controller->renderLogPreview($imported->id), 'последние 256') !== false, 'preview explains truncation');
file_put_contents($previewPath, str_repeat('x', 300000));
$chunk = $logs->readChunk($imported->log_path);
releaseCheck($chunk['reset'] && $chunk['truncated'] && strlen(base64_decode($chunk['bytes'])) === 262144
    && $chunk['offset'] === 300000, 'stream initial tail is bounded with byte cursor');
file_put_contents($previewPath, "Привет\n", FILE_APPEND);
$next = $logs->readChunk($imported->log_path, $chunk['offset']);
releaseCheck(base64_decode($next['bytes']) === "Привет\n" && !$next['reset'] && !$next['hasMore'], 'stream returns only appended UTF-8 bytes');
releaseCheck($logs->readChunk($imported->log_path, $next['offset'])['bytes'] === '', 'unchanged stream returns no content');
file_put_contents($previewPath, str_repeat('a', 70000));
$next = $logs->readChunk($imported->log_path, 0);
releaseCheck(strlen(base64_decode($next['bytes'])) === 65536 && $next['hasMore'], 'incremental response bounded to 64 KiB');
$reset = $logs->readChunk($imported->log_path, 999999);
releaseCheck($reset['reset'] && $reset['offset'] === 70000, 'truncated file resets stale cursor');
$live = $controller->actionLogChunk($imported->id, '70000');
releaseCheck($live['finished'] && $live['bytes'] === '', 'terminal stream exposes completion');
$rejected = false;
try { $controller->actionLogChunk($imported->id, '../'); } catch (yii\web\BadRequestHttpException $e) { $rejected = true; }
releaseCheck($rejected, 'invalid cursor rejected');
file_put_contents($previewPath, '');
releaseCheck(strpos($controller->renderLogPreview($imported->id), 'Лог пока пуст') !== false, 'empty log has explicit state');
$response = $controller->actionLog($imported->id);
releaseCheck($response->headers->get('Cache-Control') === 'private, no-store'
    && $response->headers->get('X-Content-Type-Options') === 'nosniff', 'private download headers preserved');
if (is_array($response->stream) && is_resource($response->stream[0])) { fclose($response->stream[0]); }
$app->user->allowed = false;
$rejected = false;
try { $controller->actionLog($csvArtifact->id); } catch (yii\web\ForbiddenHttpException $e) { $rejected = true; }
releaseCheck($rejected, 'CSV download requires permission');
$rejected = false;
try { $controller->actionLogChunk($imported->id); } catch (yii\web\ForbiddenHttpException $e) { $rejected = true; }
releaseCheck($rejected, 'stream checks authorization');
releaseCheck(strpos($controller->renderLogPreview($imported->id), 'Нет доступа') !== false, 'preview requires download permission');
$rejected = false;
try { $controller->actionLog($imported->id); } catch (yii\web\ForbiddenHttpException $e) { $rejected = true; }
releaseCheck($rejected, 'download requires admin permission');
$app->user->allowed = true;
$app->user->isGuest = true;
$rejected = false;
try { $controller->actionLog($imported->id); } catch (yii\web\ForbiddenHttpException $e) { $rejected = true; }
releaseCheck($rejected, 'guest cannot download');
$app->user->isGuest = false;
$app->skeeks->site->id = 986;
$rejected = false;
try { $controller->actionLog($csvArtifact->id); } catch (yii\web\NotFoundHttpException $e) { $rejected = true; }
releaseCheck($rejected, 'CSV download rejects another site');
$rejected = false;
try { $controller->actionLogChunk($imported->id); } catch (yii\web\NotFoundHttpException $e) { $rejected = true; }
releaseCheck($rejected, 'stream rejects another site');
releaseCheck(strpos($controller->renderLogPreview($imported->id), '<pre') === false, 'preview refuses another site');
$rejected = false;
try { $controller->actionLog($imported->id); } catch (yii\web\NotFoundHttpException $e) { $rejected = true; }
releaseCheck($rejected, 'download rejects another site');
$app->skeeks->site->id = 987;
$csvArtifact->updateAttributes(['expires_at' => time() - 1]);
$rejected = false;
try { $controller->actionLog($csvArtifact->id); } catch (yii\web\GoneHttpException $e) { $rejected = true; }
releaseCheck($rejected, 'expired CSV download returns Gone');
releaseCheck($logs->cleanup() === 1 && !file_exists($csvPath), 'expired CSV removed by diagnostic cleanup');
$csvOrphan = $logs->create($run, 'csv');
touch($csvOrphan, time() - 15 * 86400);
releaseCheck($logs->cleanupOrphans() === 1 && !file_exists($csvOrphan), 'old orphan CSV removed');
$imported->updateAttributes(['expires_at' => time() - 1]);
releaseCheck(strpos($controller->renderLogPreview($imported->id), 'срок хранения истёк') !== false, 'expired preview has explicit state');
$rejected = false;
try { $controller->actionLog($imported->id); } catch (yii\web\GoneHttpException $e) { $rejected = true; }
releaseCheck($rejected, 'expired download returns Gone');
$app->set('request', $oldRequest);
$app->set('response', $oldResponse);
$importedPath = $logs->resolve($imported->log_path);
$run->updateAttributes(['retention_until' => time() - 1]);
releaseCheck($app->runAction('cms-job/worker/cleanup') === 0
    && !file_exists($importedPath)
    && !file_exists($csvPath)
    && !file_exists($logs->root().'/'.$reports[0]->log_path)
    && !skeeks\cms\job\models\CmsJobRun::findOne($run->id), 'history cleanup also deletes private files');
