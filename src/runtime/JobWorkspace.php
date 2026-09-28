<?php
namespace skeeks\cms\job\runtime;

/** Owned by JobContext. Do not retain it or spawn detached writers after run() returns. */
final class JobWorkspace
{
    private $storage;
    private $runId;
    private $token;
    private $directory;
    private $lock;

    public function __construct(JobWorkspaceStorage $storage, int $id, string $token, string $directory, $lock)
    {
        $this->storage = $storage; $this->runId = $id; $this->token = $token;
        $this->directory = $directory; $this->lock = $lock;
    }

    /** Empty name returns the data directory; subdirectories are explicitly created by the caller. */
    public function path(string $name = ''): string
    {
        if (!is_resource($this->lock)) { throw new \LogicException('Workspace is closed.'); }
        $this->storage->assertOwner($this->runId, $this->token);
        if ($name !== '' && !preg_match('~^[a-zA-Z0-9_-][a-zA-Z0-9_.-]*(/[a-zA-Z0-9_-][a-zA-Z0-9_.-]*)*$~D', $name)) {
            throw new \InvalidArgumentException('Use a relative workspace filename without traversal.');
        }
        $path = $this->directory;
        foreach ($name === '' ? [] : explode('/', $name) as $part) {
            $path .= '/'.$part;
            if (is_link($path)) { throw new \RuntimeException('Workspace path contains a symlink.'); }
        }
        return $path;
    }

    /** Internal: runner releases the lock in finally, including requeue, cancellation and errors. */
    public function close(): void
    {
        if (is_resource($this->lock)) { flock($this->lock, LOCK_UN); fclose($this->lock); }
        $this->lock = null;
    }
    public function __destruct() { $this->close(); }
}
