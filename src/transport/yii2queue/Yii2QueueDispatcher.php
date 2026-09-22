<?php
namespace skeeks\cms\job\transport\yii2queue;

use skeeks\cms\job\runtime\CronWorkerLock;
use skeeks\cms\job\runtime\JobRecovery;
use skeeks\cms\job\transport\WorkerOptions;
use skeeks\cms\job\transport\WorkerSettings;
use yii\base\Component;
use yii\base\InvalidConfigException;

/** One local parent, native DB reservations and bounded isolated deliveries. */
class Yii2QueueDispatcher extends Component
{
    public $lockPath = '@root/console/runtime/cms-jobs/locks';

    public function consume(array $names, WorkerOptions $options, WorkerSettings $settings): int
    {
        if (!$names || !$options->isolate || !function_exists('pcntl_signal')) {
            throw new InvalidConfigException('Dispatcher requires channels, process isolation and pcntl.');
        }
        $factory = \Yii::$app->get('jobQueueFactory');
        $consumer = \Yii::$app->get('jobConsumer');
        if (!$consumer instanceof Yii2QueueConsumer) { throw new InvalidConfigException('Dispatcher requires Yii2QueueConsumer.'); }
        $queues = [];
        foreach (array_unique($names) as $name) {
            $queue = $factory->get($name);
            if (!$queue instanceof DbQueue) { throw new InvalidConfigException('Dispatcher requires DbQueue: '.$name); }
            $queues[$name] = $queue;
        }
        $lock = new CronWorkerLock($this->lockPath);
        if (!$lock->acquire('@dispatcher')) { throw new InvalidConfigException('A dispatcher for this site is already running.'); }
        $recovery = new JobRecovery();
        $loop = new WorkerLoop(reset($queues), ['options' => $options]);
        $active = []; $counts = array_fill_keys(array_keys($queues), 0);
        $accept = true; $failure = null; $cursor = 0; $nextPoll = 0;
        $names = array_keys($queues);
        try {
            do {
                foreach ($active as $key => $item) {
                    try {
                        $done = $item['delivery']->poll();
                        if ($done === null) { continue; }
                        if ($done) { $queues[$item['name']]->acknowledgeDelivery($item['payload']); }
                    } catch (\Throwable $e) {
                        // Keep other children alive and drain them before surfacing the error.
                        $failure = $failure ?? $e; $accept = false;
                    }
                    --$counts[$item['name']]; unset($active[$key]);
                }
                if ($accept) {
                    try {
                        $accept = $loop->canContinue();
                        if ($accept && microtime(true) >= $nextPoll) {
                            $recovery->tick(max(1, (int)$consumer->recoveryInterval));
                            $started = false;
                            // Rotating start prevents a busy first lane starving later lanes.
                            for ($i = 0, $n = count($names); $i < $n && count($active) < $settings->maxProcesses; ++$i) {
                                if (!$loop->canContinue()) { $accept = false; break; }
                                $name = $names[$cursor]; $cursor = ($cursor + 1) % $n;
                                if ($counts[$name] >= $settings->concurrency($name)) { continue; }
                                if (!$payload = $queues[$name]->reserveDelivery()) { continue; }
                                $childOptions = clone $options; $childOptions->queue = $name;
                                $delivery = $consumer->startDelivery($queues[$name], $childOptions,
                                    $payload['id'], $payload['job'], $payload['ttr'], $payload['attempt']);
                                $active[] = ['name' => $name, 'payload' => $payload, 'delivery' => $delivery];
                                ++$counts[$name]; ++$loop->processed; $started = true;
                            }
                            if (!$started && !$active && $options->once) { $accept = false; }
                            $nextPoll = microtime(true) + ($started ? 0 : max(1, $options->timeout));
                        }
                    } catch (\Throwable $e) { $failure = $e; $accept = false; }
                }
                foreach ($queues as $queue) {
                    try { $queue->releaseWorkerConnection(); }
                    catch (\Throwable $e) { $failure = $failure ?? $e; $accept = false; }
                }
                if ($accept || $active) { usleep(50000); }
            } while ($accept || $active);
            if ($failure) { throw $failure; }
            return 0;
        } finally { $lock->release(); }
    }
}
