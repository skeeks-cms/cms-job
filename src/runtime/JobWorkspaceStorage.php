<?php
namespace skeeks\cms\job\runtime;

use skeeks\cms\job\exceptions\JobFencedException;
use skeeks\cms\job\exceptions\JobRequeueException;
use skeeks\cms\job\models\CmsJobRun;
use yii\base\Component;

/**
 * Private, cooperative local-filesystem protocol. Never infer ownership from age.
 * All writers must use JobContext; external writers/shared FS without flock are unsupported.
 */
class JobWorkspaceStorage extends Component
{
    public $basePath = '@runtime/cms-jobs/workspaces';
    public $successDays = 7;
    public $failureDays = 14;
    /** Existing unmanaged run-ID directories: protect history, never delete/adopt. */
    public $historyProtectionRoots = [];
    public $maxEntriesPerDirectory = 20000;
    public $maxDepth = 32;

    public function root(bool $create = false): ?string
    {
        $path = rtrim(str_replace('\\', '/', \Yii::getAlias($this->basePath)), '/');
        if (is_link($path)) { throw new \RuntimeException('Workspace root must not be a symlink.'); }
        if (!file_exists($path)) {
            if (!$create) { return null; }
            if (!mkdir($path, 0700, true) && !is_dir($path)) { throw new \RuntimeException('Cannot create workspace root.'); }
        }
        if (!is_dir($path)) { throw new \RuntimeException('Invalid workspace root.'); }
        return str_replace('\\', '/', realpath($path));
    }

    private function directory(string $path): void
    {
        if (is_link($path)) { throw new \RuntimeException('Workspace directory is a symlink.'); }
        if (!is_dir($path) && !mkdir($path, 0700) && !is_dir($path)) { throw new \RuntimeException('Cannot create workspace directory.'); }
    }

    /** Nonblocking, close-on-exec locks. Catalogue serializes opening/removing per-run locks. */
    private function lock(string $path, bool $create = true)
    {
        if (is_link($path) || (file_exists($path) && !is_file($path))) { throw new \RuntimeException('Unsafe workspace lock.'); }
        if (!$create && !is_file($path)) { return null; }
        $handle = fopen($path, $create ? 'c+e' : 're');
        if (!$handle) { throw new \RuntimeException('Cannot open workspace lock.'); }
        if (!flock($handle, LOCK_EX | LOCK_NB)) { fclose($handle); return null; }
        return $handle;
    }

    private function unlock($handle): void
    {
        if (is_resource($handle)) { flock($handle, LOCK_UN); fclose($handle); }
    }

    private function defer(): void
    {
        $error = new JobRequeueException('Рабочая папка занята прежним исполнителем или очисткой.');
        $error->delay = 5;
        throw $error;
    }

    public function assertOwner(int $id, string $token): CmsJobRun
    {
        $run = CmsJobRun::findOne($id);
        if (!$run || $token === '' || $run->status !== CmsJobRun::STATUS_RUNNING
            || !hash_equals((string)$run->execution_token, $token)
            || (int)$run->lease_until < time()) {
            throw new JobFencedException('Владение рабочей папкой потеряно.');
        }
        return $run;
    }

    public function open(CmsJobRun $run, string $token): JobWorkspace
    {
        if ((int)$run->id < 1) { throw new \InvalidArgumentException('Workspace requires a persisted run.'); }
        $root = $this->root(true);
        $catalogue = $this->lock($root.'/.catalog.lock');
        if (!$catalogue) { $this->defer(); }
        $lock = null;
        try {
            $current = $this->assertOwner((int)$run->id, $token);
            $this->directory($root.'/runs');
            $this->directory($root.'/trash');
            if (file_exists($root.'/trash/'.$run->id) || is_link($root.'/trash/'.$run->id)
                || file_exists($root.'/trash/'.$run->id.'.json')) {
                throw new \RuntimeException('Workspace is already quarantined.');
            }
            $path = $root.'/runs/'.$run->id;
            $fresh = !file_exists($path) && !is_link($path);
            if ($fresh) {
                // Publish only a complete registration; a killed initializer leaves an
                // explicitly unmanaged staging directory, never a broken live workspace.
                $staging = $root.'/runs/.initializing-'.$run->id.'-'.bin2hex(random_bytes(8));
                $this->directory($staging);
                $this->writeManifest($staging, ['version' => 1, 'run_id' => (int)$run->id,
                    'job_type' => $current->job_type, 'site_id' => $current->cms_site_id,
                    'created_at' => time(), 'hold' => false]);
                $this->directory($staging.'/data');
                if (file_put_contents($staging.'/.lock', '') === false || !rename($staging, $path)) {
                    throw new \RuntimeException('Cannot publish workspace registration.');
                }
            }
            $this->directory($path);
            if (!is_file($path.'/.lock')) { throw new \RuntimeException('Workspace lock is missing.'); }
            $lock = $this->lock($path.'/.lock', false);
            if (!$lock) { $this->defer(); }
            $this->manifest($path, $current);
            if (!is_dir($path.'/data') || is_link($path.'/data')) { throw new \RuntimeException('Workspace data missing or unsafe.'); }
            $this->assertOwner((int)$run->id, $token);
            $workspace = new JobWorkspace($this, (int)$run->id, $token, $path.'/data', $lock);
            $lock = null; // ownership transferred to context, released only after handler unwinds
            return $workspace;
        } finally { $this->unlock($lock); $this->unlock($catalogue); }
    }

    private function manifest(string $path, ?CmsJobRun $run = null, ?string $receipt = null): array
    {
        $file = $receipt ?? $path.'/manifest.json';
        if (is_link($file) || !is_file($file) || filesize($file) > 16384) { throw new \RuntimeException('Missing or unsafe workspace manifest.'); }
        $data = json_decode(file_get_contents($file), true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($data) || ($data['version'] ?? null) !== 1 || (string)($data['run_id'] ?? '') !== basename($path)
            || !is_bool($data['hold'] ?? null) || !is_string($data['job_type'] ?? null)) {
            throw new \RuntimeException('Unknown workspace ownership.');
        }
        if ($run && ((int)$run->id !== $data['run_id'] || $run->job_type !== $data['job_type']
            || (string)$run->cms_site_id !== (string)($data['site_id'] ?? null))) {
            throw new \RuntimeException('Workspace owner mismatch.');
        }
        return $data;
    }

    private function writeManifest(string $path, array $data): void
    {
        $this->writeJson($path.'/manifest.json', $data);
    }

    private function writeJson(string $file, array $data): void
    {
        if (is_link($file)) { throw new \RuntimeException('Unsafe workspace metadata.'); }
        $tmp = dirname($file).'/.metadata-'.bin2hex(random_bytes(8)).'.tmp';
        $json = json_encode($data, JSON_THROW_ON_ERROR);
        if (file_put_contents($tmp, $json, LOCK_EX) !== strlen($json) || !rename($tmp, $file)) {
            throw new \RuntimeException('Cannot save workspace manifest.');
        }
    }

    /** Hold/release requires the same exclusive lock, also valid after terminal completion. */
    public function setHold(int $id, bool $hold): void
    {
        $root = $this->root();
        if (!$root || $id < 1) { throw new \RuntimeException('Workspace not found.'); }
        $catalogue = $this->lock($root.'/.catalog.lock');
        if (!$catalogue) { throw new \RuntimeException('Workspace catalogue busy.'); }
        $lock = null;
        try {
            $path = $root.'/runs/'.$id;
            if (is_link($path) || !is_dir($path)) { throw new \RuntimeException('Workspace missing or quarantined.'); }
            $lock = $this->lock($path.'/.lock');
            if (!$lock) { throw new \RuntimeException('Workspace is in use.'); }
            $data = $this->manifest($path, CmsJobRun::findOne($id));
            $data['hold'] = $hold;
            $this->writeManifest($path, $data);
        } finally { $this->unlock($lock); $this->unlock($catalogue); }
    }

    /** History cannot disappear before resources, including explicitly protected legacy roots. */
    public function protectsHistory(int $id): bool
    {
        $root = $this->root();
        $paths = $root ? [$root.'/runs/'.$id, $root.'/trash/'.$id, $root.'/trash/'.$id.'.json'] : [];
        foreach ($this->historyProtectionRoots as $legacy) { $paths[] = rtrim(\Yii::getAlias($legacy), '/\\').'/'.$id; }
        foreach ($paths as $path) { if (file_exists($path) || is_link($path)) { return true; } }
        return false;
    }

    /**
     * Inspect one page, sorted by numeric run ID. Dry-run only locks existing files opened read-only.
     * Deletion rechecks the manifest/DB under both locks and resumes quarantined trees after a crash.
     */
    public function sweep(bool $dryRun = true, int $limit = 100, int $after = 0, ?callable $checkpoint = null): array
    {
        $result = ['items' => [], 'deleted' => 0, 'bytes' => 0, 'after' => $after, 'more' => false,
            'unmanaged_entries' => 0, 'unmanaged_samples' => []];
        $root = $this->root();
        if (!$root) { return $result; }
        $ids = [];
        foreach (['runs', 'trash'] as $area) {
            $dir = $root.'/'.$area;
            if (is_link($dir)) { throw new \RuntimeException('Unsafe workspace area.'); }
            if (!is_dir($dir)) { continue; }
            foreach (new \DirectoryIterator($dir) as $entry) {
                if ($checkpoint) { $checkpoint(); }
                if ($entry->isDot()) { continue; }
                $name = $entry->getFilename();
                if ($area === 'trash' && preg_match('/^[1-9][0-9]*\.json$/D', $name)) { $name = substr($name, 0, -5); }
                if (preg_match('/^[1-9][0-9]*$/D', $name) && (string)(int)$name === $name) {
                    if ((int)$name > $after) { $ids[(int)$name] = true; }
                } else {
                    ++$result['unmanaged_entries'];
                    if (count($result['unmanaged_samples']) < 20) { $result['unmanaged_samples'][] = $area.'/'.$entry->getFilename(); }
                }
            }
        }
        ksort($ids, SORT_NUMERIC);
        $limit = max(1, min(500, $limit));
        $result['more'] = count($ids) > $limit;
        $started = microtime(true);
        foreach (array_slice(array_keys($ids), 0, $limit) as $id) {
            if (microtime(true) - $started >= 30) { $result['more'] = true; break; }
            if ($checkpoint) { $checkpoint(); }
            $item = $this->visit($root, $id, $dryRun, $checkpoint);
            $result['items'][] = $item;
            $result['after'] = $id;
            if ($item['reason'] === 'deleted') { ++$result['deleted']; $result['bytes'] += $item['bytes']; }
        }
        return $result;
    }

    private function visit(string $root, int $id, bool $dryRun, ?callable $checkpoint): array
    {
        $item = ['run_id' => $id, 'status' => null, 'age_seconds' => null, 'bytes' => 0, 'reason' => 'unknown'];
        $catalogue = $lock = null;
        try {
            $catalogue = $this->lock($root.'/.catalog.lock', !$dryRun);
            if (!$catalogue) { $item['reason'] = 'busy'; return $item; }
            $live = $root.'/runs/'.$id; $trash = $root.'/trash/'.$id;
            $receipt = $trash.'.json';
            // Durable receipt remains until all control files and the directory are gone.
            // It also blocks deletion of run history after a crash during final rmdir.
            if (is_file($receipt) && !file_exists($live) && !file_exists($trash) && !is_link($live) && !is_link($trash)) {
                $run = CmsJobRun::findOne($id);
                $this->manifest($trash, $run, $receipt);
                if (!$run || !$run->isFinished) { $item['reason'] = 'missing_or_unfinished_run'; return $item; }
                $item['status'] = $run->status;
                $item['reason'] = $dryRun ? 'candidate' : 'deleted';
                if (!$dryRun && !unlink($receipt)) { throw new \RuntimeException('Cannot remove cleanup receipt.'); }
                return $item;
            }
            $quarantined = file_exists($trash) || is_link($trash);
            if ($quarantined && (file_exists($live) || is_link($live))) { throw new \RuntimeException('Duplicate workspace locations.'); }
            $path = $quarantined ? $trash : $live;
            if (is_link($path) || !is_dir($path)) { throw new \RuntimeException('Unsafe workspace directory.'); }
            if (!is_file($path.'/.lock') && !($quarantined && is_file($receipt))) {
                throw new \RuntimeException('Workspace lock is missing.');
            }
            $lock = $this->lock($path.'/.lock', !$dryRun && $quarantined && is_file($receipt));
            if (!$lock) { $item['reason'] = 'busy'; return $item; }
            $run = CmsJobRun::findOne($id);
            $data = $this->manifest($path, $run, $quarantined && is_file($receipt) ? $receipt : null);
            if (!$run) { $item['reason'] = 'missing_run'; return $item; }
            $item['status'] = $run->status;
            if (!$run->isFinished) { $item['reason'] = 'unfinished'; return $item; }
            if ($data['hold']) { $item['reason'] = 'hold'; return $item; }
            if (!$run->finished_at || (int)$run->finished_at > time()) { $item['reason'] = 'unknown_completion'; return $item; }
            $item['age_seconds'] = time() - (int)$run->finished_at;
            $days = $run->status === CmsJobRun::STATUS_SUCCEEDED ? $this->successDays : $this->failureDays;
            if ($item['age_seconds'] < max(1, (int)$days) * 86400) { $item['reason'] = 'retained'; return $item; }
            $entries = [];
            $this->scan($path, $path, $entries, 0, $checkpoint);
            foreach ($entries as $entry) {
                if (($entry['stat']['mode'] & 0170000) === 0100000) { $item['bytes'] += $entry['stat']['size']; }
            }
            // Only known root control files and the data subtree may be removed.
            foreach (scandir($path) as $name) {
                if (!in_array($name, ['.', '..', '.lock', 'manifest.json', 'data'], true)) {
                    throw new \RuntimeException('Unexpected workspace control file.');
                }
            }
            $item['reason'] = $dryRun ? 'candidate' : 'deleted';
            if ($dryRun) { return $item; }
            if (!is_file($receipt)) {
                $this->writeJson($receipt, $data);
            }
            // Catalogue stays locked through rename; after rename the per-run inode lock survives.
            if (!$quarantined && !rename($path, $trash)) { throw new \RuntimeException('Cannot quarantine workspace.'); }
            clearstatcache(true, $trash);
            $rootStat = end($entries)['stat'];
            $movedStat = lstat($trash);
            if (!$movedStat || $movedStat['dev'] !== $rootStat['dev'] || $movedStat['ino'] !== $rootStat['ino'] || is_link($trash)) {
                throw new \RuntimeException('Workspace directory changed during quarantine.');
            }
            $this->unlock($catalogue); $catalogue = null;
            foreach ($entries as $entry) {
                $relative = substr($entry['path'], strlen($path));
                if ($relative === '/.lock' || $relative === '/manifest.json' || $relative === '') { continue; }
                if ($checkpoint) { $checkpoint(); }
                $this->removeEntry($trash.$relative, $entry['stat'], $trash);
            }
            // Never unlink a lock while another opener may wait on its inode.
            $catalogue = $this->lock($root.'/.catalog.lock');
            if (!$catalogue) { $item['reason'] = 'quarantined'; return $item; }
            if ((is_file($trash.'/manifest.json') && !unlink($trash.'/manifest.json'))
                || !unlink($trash.'/.lock') || !rmdir($trash) || !unlink($receipt)) {
                throw new \RuntimeException('Cannot finish workspace removal.');
            }
            return $item;
        } catch (\skeeks\cms\job\exceptions\JobCancelledException $error) {
            throw $error;
        } catch (JobFencedException $error) {
            throw $error;
        } catch (\Throwable $error) {
            $item['reason'] = 'unsafe_or_error';
            $item['error'] = $error->getMessage();
            return $item;
        } finally { $this->unlock($lock); $this->unlock($catalogue); }
    }

    /** Snapshot of inode identities, postorder; refuse links, devices and unbounded trees. */
    private function scan(string $root, string $path, array &$entries, int $depth, ?callable $checkpoint): void
    {
        if ($checkpoint) { $checkpoint(); }
        clearstatcache(true, $path);
        $stat = lstat($path);
        if (!$stat || $stat['dev'] !== lstat($root)['dev']) { throw new \RuntimeException('Workspace filesystem changed.'); }
        $type = $stat['mode'] & 0170000;
        if (!in_array($type, [0040000, 0100000], true) || ($type === 0100000 && $stat['nlink'] !== 1)) {
            throw new \RuntimeException('Links or special files in workspace.');
        }
        if ($depth > $this->maxDepth || count($entries) >= $this->maxEntriesPerDirectory) {
            throw new \RuntimeException('Workspace scan limit exceeded.');
        }
        if ($type === 0040000) {
            foreach (new \DirectoryIterator($path) as $file) {
                if (!$file->isDot()) { $this->scan($root, $file->getPathname(), $entries, $depth + 1, $checkpoint); }
            }
        }
        $entries[] = ['path' => $path, 'stat' => $stat];
    }

    private function removeEntry(string $path, array $expected, string $root): void
    {
        for ($parent = dirname($path); ; $parent = dirname($parent)) {
            clearstatcache(true, $parent);
            if (is_link($parent) || !is_dir($parent)) { throw new \RuntimeException('Workspace ancestor changed.'); }
            if ($parent === $root) { break; }
            if ($parent === dirname($parent)) { throw new \RuntimeException('Path escaped workspace.'); }
        }
        clearstatcache(true, $path);
        $stat = lstat($path);
        if (!$stat || $stat['dev'] !== $expected['dev'] || $stat['ino'] !== $expected['ino']
            || ($stat['mode'] & 0170000) !== ($expected['mode'] & 0170000)) {
            throw new \RuntimeException('Workspace entry changed during cleanup.');
        }
        $ok = ($stat['mode'] & 0170000) === 0040000 ? rmdir($path) : unlink($path);
        if (!$ok) { throw new \RuntimeException('Cannot remove workspace entry.'); }
    }
}
