<?php
namespace skeeks\cms\job\transport\yii2queue;

/** DB transport which does not occupy a MySQL session while the worker waits. */
class DbQueue extends \yii\queue\db\Queue
{
    /** Disable for custom workers that intentionally retain session state/locks. */
    public $releaseIdleConnection = true;

    protected function reserve()
    {
        // The library owns reservation and releases its mutex before returning.
        $payload = parent::reserve();
        if (!$payload) {
            $this->releaseWorkerConnection();
        }
        return $payload;
    }

    /** Called only at idle or isolated-child boundaries, never by publishers. */
    public function releaseWorkerConnection(): void
    {
        // SQLite in-memory databases must never be destroyed by an idle poll.
        // Other drivers retain their previous lifecycle until verified.
        if (!$this->releaseIdleConnection || $this->db->driverName !== 'mysql'
            || $this->db->getTransaction() !== null
            || $this->mutex->isAcquired(\yii\queue\db\Queue::class.$this->channel)) {
            return;
        }
        $this->db->close();
    }
}
