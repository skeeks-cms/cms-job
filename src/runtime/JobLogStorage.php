<?php
namespace skeeks\cms\job\runtime;

use skeeks\cms\job\models\CmsJobRun;
use skeeks\cms\job\models\CmsJobRunArtifact;
use yii\base\Component;
use yii\helpers\FileHelper;

/** Private, disposable diagnostics. Result files still belong to CMS storage. */
class JobLogStorage extends Component
{
    public $basePath = '@root/console/runtime/cms-jobs/logs';
    public $maxBytes = 5242880;
    public $retentionDays = 14;

    /** Byte cursor protocol. Base64 preserves UTF-8 and terminal sequences across chunks. */
    public function readChunk(string $key, ?int $offset = null): array
    {
        $path = $this->resolve($key);
        $handle = $path ? @fopen($path, 'rb') : false;
        if (!$handle) { throw new \RuntimeException('Файл лога недоступен.'); }
        try {
            $stat = fstat($handle);
            if ($stat === false) { throw new \RuntimeException('Не удалось прочитать лог.'); }
            $size = (int)$stat['size'];
            $reset = $offset === null || $offset < 0 || $offset > $size;
            $start = $reset ? max(0, $size - 262144) : $offset;
            if (fseek($handle, $start) !== 0) { throw new \RuntimeException('Не удалось прочитать лог.'); }
            $bytes = fread($handle, $reset ? 262144 : 65536);
            if ($bytes === false) { throw new \RuntimeException('Не удалось прочитать лог.'); }
            $next = $start + strlen($bytes);
            return [
                'bytes' => base64_encode($bytes), 'offset' => $next,
                'reset' => $reset, 'truncated' => $reset && $start > 0,
                'hasMore' => $next < $size,
            ];
        } finally {
            fclose($handle);
        }
    }

    /** Read only the last 256 KiB, never the whole potentially large file. */
    public function preview(string $key): array
    {
        $path = $this->resolve($key);
        $handle = $path ? @fopen($path, 'rb') : false;
        if (!$handle) { throw new \RuntimeException('Файл лога недоступен.'); }
        try {
            $size = (int)fstat($handle)['size'];
            $limit = 256 * 1024;
            $offset = max(0, $size - $limit);
            if (fseek($handle, $offset) !== 0) { throw new \RuntimeException('Не удалось прочитать лог.'); }
            $text = stream_get_contents($handle, $limit);
            if ($text === false) { throw new \RuntimeException('Не удалось прочитать лог.'); }
            // ANSI colors/control bytes are terminal formatting, not HTML.
            $text = preg_replace('/\x1b\[[0-?]*[ -\/]*[@-~]/', '', $text);
            $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $text);
            return ['text' => $text, 'truncated' => $offset > 0];
        } finally {
            fclose($handle);
        }
    }

    public function root(): string
    {
        $path = \Yii::getAlias($this->basePath);
        FileHelper::createDirectory($path, 02770);
        $root = realpath($path);
        if ($root === false) { throw new \RuntimeException('Cannot resolve job log directory.'); }
        return str_replace('\\', '/', $root);
    }

    public function create(CmsJobRun $run, string $extension = 'log'): string
    {
        if (!in_array($extension, ['log', 'csv'], true)) {
            throw new \InvalidArgumentException('Invalid diagnostic file extension.');
        }
        if (!preg_match('/^[a-zA-Z0-9_-]{1,32}$/D', $run->queue_name) || $run->id < 1) {
            throw new \InvalidArgumentException('Invalid job log identity.');
        }
        // At most 1000 run IDs per date/bucket; separate files for retries.
        $key = $run->queue_name.'/'.gmdate('Y/m/d').'/'.intdiv((int)$run->id, 1000)
            .'/'.$run->id.'-'.bin2hex(random_bytes(16)).'.'.$extension;
        $path = $this->root().'/'.$key;
        // Validate each existing ancestor before creating children (no symlink escape).
        $parent = $this->root();
        foreach (explode('/', dirname($key)) as $part) {
            $parent .= '/'.$part;
            if (is_link($parent)) { throw new \RuntimeException('Symlinks are not allowed in job log paths.'); }
            if (!is_dir($parent)) { FileHelper::createDirectory($parent, 02770, false); }
            $this->assertInside(realpath($parent));
        }
        $handle = fopen($path, 'x');
        if (!$handle) { throw new \RuntimeException('Cannot create job log.'); }
        fclose($handle);
        chmod($path, 0660);
        return $path;
    }

    public function key(string $path): string
    {
        $real = realpath($path);
        $this->assertInside($real);
        return substr(str_replace('\\', '/', $real), strlen($this->root()) + 1);
    }

    public function resolve(string $key): ?string
    {
        if (!preg_match('~^[a-zA-Z0-9_-]{1,32}/[0-9]{4}/[0-9]{2}/[0-9]{2}/[0-9]+/[0-9]+-[a-f0-9]{32}\.(?:log|csv)$~D', $key)) {
            throw new \InvalidArgumentException('Invalid job log path.');
        }
        $path = $this->root().'/'.$key;
        $parent = $this->root();
        foreach (explode('/', $key) as $part) {
            $parent .= '/'.$part;
            if (is_link($parent)) { throw new \RuntimeException('Symlinks are not allowed in job log paths.'); }
        }
        if (!file_exists($path)) { return null; }
        $this->assertInside(realpath($path));
        return is_file($path) ? $path : null;
    }

    private function assertInside($path): void
    {
        if (!$path || strpos(str_replace('\\', '/', $path), $this->root().'/') !== 0) {
            throw new \RuntimeException('Job log path escapes its private directory.');
        }
    }

    /** Import a handler-supplied log, bounded in size; never upload to cms_storage_file. */
    public function import(string $path, CmsJobRun $run, bool $errorReport = false): string
    {
        $real = realpath($path);
        if ($real && strpos(str_replace('\\', '/', $real), $this->root().'/') === 0) {
            $key = $this->key($path);
            $this->resolve($key);
            return $key;
        }
        $target = $this->create($run, $errorReport ? 'csv' : 'log');
        $input = fopen($path, 'rb');
        $output = fopen($target, 'wb');
        try {
            if (!$input || !$output) { throw new \RuntimeException('Cannot import job log.'); }
            // CSV is a complete report, not a truncated console tail. Stream it
            // without loading it into memory and retain every row.
            if (stream_copy_to_stream($input, $output, $errorReport ? -1 : max(1, (int)$this->maxBytes)) === false) {
                throw new \RuntimeException('Cannot copy job log.');
            }
        } finally {
            if (is_resource($input)) { fclose($input); }
            if (is_resource($output)) { fclose($output); }
        }
        return $this->key($target);
    }

    public function remove(string $key): void
    {
        $path = $this->resolve($key);
        if ($path !== null && !@unlink($path)) {
            clearstatcache(true, $path);
            if (file_exists($path)) { throw new \RuntimeException('Cannot delete expired job log.'); }
        }
        // Only empty parents, never recursive deletion and never the storage root.
        $dir = dirname($this->root().'/'.$key);
        while ($dir !== $this->root() && is_dir($dir) && !is_link($dir)) {
            $this->assertInside(realpath($dir));
            if (!@rmdir($dir)) { break; }
            $dir = dirname($dir);
        }
    }

    /** Bounded pass; keep artifact metadata so the UI can explain expiry. */
    public function cleanup(int $limit = 500): int
    {
        $artifacts = CmsJobRunArtifact::find()->alias('a')->innerJoin(
            ['r' => CmsJobRun::tableName()], 'r.id = a.cms_job_run_id'
        )->where(['r.status' => CmsJobRun::finishedStatuses()])
            ->andWhere(['not', ['a.log_path' => null]])
            ->andWhere(['<=', 'a.expires_at', time()])
            ->orderBy(['a.expires_at' => SORT_ASC, 'a.id' => SORT_ASC])->limit($limit)->all();
        $count = 0;
        foreach ($artifacts as $artifact) {
            $this->remove($artifact->log_path);
            CmsJobRunArtifact::updateAll(['log_path' => null], ['id' => $artifact->id, 'log_path' => $artifact->log_path]);
            $count++;
        }
        return $count;
    }

    /** Old files whose runs/artifacts were deleted or never registered after a crash. */
    public function cleanupOrphans(int $limit = 500): int
    {
        $root = $this->root();
        $cutoff = time() - max(1, (int)$this->retentionDays) * 86400;
        $keys = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->isLink() || !$file->isFile() || $file->getMTime() > $cutoff) { continue; }
            $key = substr(str_replace('\\', '/', $file->getPathname()), strlen($root) + 1);
            if (!preg_match('~^[a-zA-Z0-9_-]{1,32}/[0-9]{4}/[0-9]{2}/[0-9]{2}/[0-9]+/([0-9]+)-[a-f0-9]{32}\.(?:log|csv)$~D', $key, $matches)) { continue; }
            if (CmsJobRunArtifact::find()->where(['log_path' => $key])->exists()) { continue; }
            $run = CmsJobRun::findOne($matches[1]);
            if ($run && !$run->isFinished) { continue; }
            $keys[] = $key;
            if (count($keys) >= max(1, $limit)) { break; }
        }
        unset($iterator);
        foreach ($keys as $key) { $this->remove($key); }
        return count($keys);
    }
}
