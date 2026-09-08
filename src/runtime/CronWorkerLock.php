<?php
namespace skeeks\cms\job\runtime;

use yii\base\InvalidConfigException;
use yii\helpers\FileHelper;

/** Local process lock, separate from transport reservation and resource locks. */
final class CronWorkerLock
{
    private $path;
    private $handle;

    public function __construct(string $path)
    {
        $this->path = $path;
    }

    public function acquire(string $queue): bool
    {
        if ($this->handle !== null) {
            throw new \LogicException('Cron lock is already acquired.');
        }
        $directory = \Yii::getAlias($this->path);
        FileHelper::createDirectory($directory, 0770, true);
        $file = $directory.'/cron-'.hash('sha256', $queue).'.lock';
        if (is_link($file)) {
            throw new InvalidConfigException('Cron lock must not be a symbolic link.');
        }
        // Never truncate/unlink the inode: competing processes must lock the
        // same file. Close-on-exec prevents isolated children inheriting it.
        $handle = @fopen($file, 'c+e');
        if ($handle === false) {
            throw new InvalidConfigException('Cannot open cron lock directory: '.$directory);
        }
        $wouldBlock = 0;
        if (!flock($handle, LOCK_EX | LOCK_NB, $wouldBlock)) {
            fclose($handle);
            if ($wouldBlock) { return false; }
            throw new InvalidConfigException('The filesystem does not support cron locking.');
        }
        $this->handle = $handle;
        return true;
    }

    public function release(): void
    {
        if ($this->handle !== null) {
            flock($this->handle, LOCK_UN);
            fclose($this->handle);
            $this->handle = null;
        }
    }

    public function __destruct()
    {
        $this->release();
    }
}
