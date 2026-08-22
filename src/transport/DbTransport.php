<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 * @author Semenov Alexander <semenov@skeeks.com>
 */

namespace skeeks\cms\job\transport;

use skeeks\cms\job\contracts\JobTransportInterface;
use skeeks\cms\job\JobRegistry;
use skeeks\cms\job\models\CmsJobRun;
use yii\base\Component;
use yii\di\Instance;

/**
 * Транспорт на таблице приложения.
 *
 * Захват задания — UPDATE с проверкой числа изменённых строк. В MariaDB 10.5
 * нет SELECT ... SKIP LOCKED, а глобальный мьютекс сериализовал бы всех
 * воркеров, поэтому побеждает тот, чей UPDATE изменил строку. Паттерн уже
 * отработан в DnsTaskService::lock().
 */
class DbTransport extends Component implements JobTransportInterface
{
    /**
     * @var JobRegistry|string|array
     */
    public $registry = 'jobRegistry';

    /**
     * @var string|\yii\db\Connection
     */
    public $db = 'db';

    /**
     * @var int Сколько строк-кандидатов читать за один проход.
     */
    public $candidateBatch = 20;

    public function init()
    {
        parent::init();

        $this->registry = Instance::ensure($this->registry, JobRegistry::class);
        $this->db = Instance::ensure($this->db, \yii\db\Connection::class);
    }

    /**
     * @inheritdoc
     */
    public function push(CmsJobRun $run): void
    {
        if (!$run->save()) {
            throw new \RuntimeException(
                'Unable to push job run: '.print_r($run->errors, true)
            );
        }

        // root_id известен только после вставки, если задание корневое.
        if (!$run->root_id) {
            $run->updateAttributes(['root_id' => $run->parent_id ? $run->parent_id : $run->id]);
        }
    }

    /**
     * @inheritdoc
     */
    public function claim(array $queues, int $limit, string $workerId): array
    {
        $now = time();
        $claimed = [];

        $candidates = CmsJobRun::find()
            ->select(['id', 'job_type', 'attempt'])
            ->andWhere(['status' => CmsJobRun::STATUS_QUEUED])
            ->andWhere(['queue_name' => $queues])
            ->andWhere(['<=', 'available_at', $now])
            ->orderBy(['priority' => SORT_ASC, 'id' => SORT_ASC])
            ->limit(max($limit, $this->candidateBatch))
            ->asArray()
            ->all();

        foreach ($candidates as $candidate) {
            if (count($claimed) >= $limit) {
                break;
            }

            $lease = $this->leaseSecondsFor($candidate['job_type']);

            $affected = CmsJobRun::updateAll(
                [
                    'status' => CmsJobRun::STATUS_RUNNING,
                    'worker_id' => $workerId,
                    'worker_pid' => function_exists('getmypid') ? (int)getmypid() : null,
                    'lease_until' => $now + $lease,
                    'attempt' => (int)$candidate['attempt'] + 1,
                    'started_at' => $now,
                    'updated_at' => $now,
                ],
                [
                    'id' => $candidate['id'],
                    'status' => CmsJobRun::STATUS_QUEUED,
                ]
            );

            if ($affected !== 1) {
                // Задание перехватил другой воркер.
                continue;
            }

            $run = CmsJobRun::findOne($candidate['id']);
            if ($run) {
                $claimed[] = $run;
            }
        }

        return $claimed;
    }

    /**
     * @inheritdoc
     */
    public function extendLease(CmsJobRun $run, int $seconds): bool
    {
        $affected = CmsJobRun::updateAll(
            ['lease_until' => time() + $seconds],
            ['id' => $run->id, 'status' => CmsJobRun::STATUS_RUNNING]
        );

        return $affected === 1;
    }

    /**
     * @inheritdoc
     */
    public function release(CmsJobRun $run, int $delay = 0): void
    {
        $now = time();

        CmsJobRun::updateAll(
            [
                'status' => CmsJobRun::STATUS_QUEUED,
                'worker_id' => null,
                'worker_pid' => null,
                'lease_until' => null,
                'available_at' => $now + $delay,
                'updated_at' => $now,
            ],
            ['id' => $run->id]
        );

        $run->refresh();
    }

    /**
     * @inheritdoc
     */
    public function reapExpired(): int
    {
        $now = time();
        $handled = 0;

        $expired = CmsJobRun::find()
            ->andWhere(['status' => CmsJobRun::STATUS_RUNNING])
            ->andWhere(['<', 'lease_until', $now])
            ->limit(100)
            ->all();

        foreach ($expired as $run) {
            $definition = $this->registry->has($run->job_type) ? $this->registry->get($run->job_type) : null;
            $idempotent = $definition ? $definition->idempotent : false;
            $canRetry = $idempotent && $run->attempt < $run->max_attempts;

            if ($canRetry) {
                // Повторять можно только то, что объявлено идемпотентным:
                // воркер мог упасть уже после внешнего вызова.
                $this->release($run);
            } else {
                $run->status = CmsJobRun::STATUS_TIMED_OUT;
                $run->error_code = 'lease_expired';
                $run->error_message = 'Аренда воркера истекла, задание не завершилось.';
                $run->finished_at = $now;
                $run->dedup_active = null;
                $run->lease_until = null;
                $run->save(false);
            }

            $handled++;
        }

        return $handled;
    }

    /**
     * @return int
     */
    protected function leaseSecondsFor($jobType)
    {
        if (!$this->registry->has($jobType)) {
            return 120;
        }

        return $this->registry->get($jobType)->leaseSeconds;
    }
}
