<?php
namespace skeeks\cms\job\transport;

use yii\base\Component;
use yii\base\InvalidConfigException;

/** Site-wide worker topology. Explicit --queue commands remain compatible. */
class WorkerSettings extends Component
{
    public $mode = 'dispatcher';
    public $maxProcesses = 10;
    public $channelConcurrency = 1;
    public $channels = [];

    public function init()
    {
        parent::init();
        if (!in_array($this->mode, ['dispatcher', 'workers'], true)) {
            throw new InvalidConfigException('jobWorker.mode: dispatcher or workers expected.');
        }
        if (!is_array($this->channels)) { throw new InvalidConfigException('jobWorker.channels must be a map of concurrency limits.'); }
        foreach (array_merge([$this->maxProcesses, $this->channelConcurrency], array_values($this->channels)) as $limit) {
            if (filter_var($limit, FILTER_VALIDATE_INT) === false || $limit < 1) {
                throw new InvalidConfigException('Worker concurrency must be a positive integer.');
            }
        }
    }

    public function concurrency(string $channel): int
    {
        return (int)($this->channels[$channel] ?? $this->channelConcurrency);
    }

    public function describe(): array
    {
        return ['mode' => $this->mode, 'max_processes' => (int)$this->maxProcesses,
            'channel_concurrency' => (int)$this->channelConcurrency, 'channels' => $this->channels];
    }
}
