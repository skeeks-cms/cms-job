<?php
namespace skeeks\cms\job\transport\yii2queue;

use Symfony\Component\Process\Process;
use Symfony\Component\Process\Exception\ProcessTimedOutException;

/** One child; null means running, bool is the transport acknowledgement decision. */
final class IsolatedDelivery
{
    private $process;
    private $timeout;
    private $crash;
    private $result;
    private $stderr = '';

    public function __construct(Process $process, callable $timeout, callable $crash)
    {
        $this->process = $process;
        $this->timeout = $timeout;
        $this->crash = $crash;
    }

    public function poll(): ?bool
    {
        if ($this->result !== null) { return $this->result; }
        try {
            $this->process->checkTimeout();
            $running = $this->process->isRunning();
            $this->stderr = substr($this->stderr.$this->process->getErrorOutput(), -65536);
            $this->process->clearErrorOutput();
            if ($running) {
                // Output has already reached the callback; do not retain large job logs.
                $this->process->clearOutput();
                return null;
            }
        } catch (ProcessTimedOutException $e) {
            return $this->result = ($this->timeout)();
        }
        $code = $this->process->getExitCode();
        if ($this->process->hasBeenSignaled()) { $code = 128 + $this->process->getTermSignal(); }
        if (!in_array($code, [Yii2QueueConsumer::EXEC_DONE, Yii2QueueConsumer::EXEC_RETRY], true)) {
            return $this->result = ($this->crash)($code, $this->stderr);
        }
        return $this->result = $code === Yii2QueueConsumer::EXEC_DONE;
    }
}
