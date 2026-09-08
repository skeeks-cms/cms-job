<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 * @author Semenov Alexander <semenov@skeeks.com>
 */

namespace skeeks\cms\job\runtime;

use skeeks\cms\job\contracts\JobPublisherInterface;
use skeeks\cms\job\models\CmsJobRun;
use skeeks\cms\job\models\CmsJobRunEvent;
use skeeks\cms\job\transport\JobTransportMessage;
use yii\base\Component;
use yii\db\Expression;
use yii\di\Instance;

/**
 * Переходы состояния запуска, защищённые маркером владения.
 *
 * Здесь же живёт единственное правило, нарушение которого теряет задание:
 * **перевод running → queued обязан в той же транзакции создать ровно одно
 * транспортное сообщение**. Иначе строка остаётся ожидающей, но будить её
 * некому, и операция висит до уборки. Слепая перепубликация всех ожидающих
 * строк эту дыру не закрывает, а добавляет дубликаты.
 *
 * Почему условия `WHERE id = ?` недостаточно: идентификатор запуска один и тот
 * же у прежнего и у нового владельца. После истечения аренды запуск законно
 * перехватывает другой воркер, но прежний процесс может быть жив — он не
 * упал, а, например, завис на сетевом вызове. Очнувшись, он допишет свой
 * прогресс поверх чужого и завершит чужую работу.
 */
class JobRunStore extends Component
{
    /**
     * @var JobPublisherInterface|string|array
     */
    public $publisher = 'jobPublisher';

    /**
     * @var \yii\db\Connection|string|array
     */
    public $db = 'db';

    public function init()
    {
        parent::init();

        $this->publisher = Instance::ensure($this->publisher, JobPublisherInterface::class);
        $this->db = Instance::ensure($this->db, \yii\db\Connection::class);
    }

    /**
     * Захватить ожидающий запуск.
     *
     * Счётчик попыток входит в тот же UPDATE. Разделение на два запроса
     * оставляло окно, в котором строку успевал перехватить другой воркер, и
     * попытка засчитывалась чужому владельцу. Побочно выражение `attempt + 1`
     * гарантирует, что строка действительно меняется, поэтому число изменённых
     * строк здесь однозначно.
     *
     * @return string|null маркер владения или null, если запуск уже перехвачен
     */
    public function claim(int $runId, string $workerId, int $leaseSeconds, ?string $executionToken = null): ?string
    {
        $now = time();
        $token = $executionToken ?? $this->generateToken();

        $affected = CmsJobRun::updateAll(
            [
                'status' => CmsJobRun::STATUS_RUNNING,
                'execution_token' => $token,
                'worker_id' => $workerId,
                'worker_pid' => function_exists('getmypid') ? (int)getmypid() : null,
                'lease_until' => $now + $leaseSeconds,
                'started_at' => $now,
                'attempt' => new Expression('attempt + 1'),
                'updated_at' => $now,
            ],
            [
                'id' => $runId,
                'status' => CmsJobRun::STATUS_QUEUED,
            ]
        );

        return $affected === 1 ? $token : null;
    }

    /**
     * Перезахватить запуск с истёкшей арендой.
     *
     * Условие по прежнему маркеру гарантирует, что перезахват не отберёт
     * запуск у живого владельца, успевшего продлить аренду между чтением и
     * записью.
     */
    public function reclaimExpired(int $runId, ?string $previousToken, string $workerId, int $leaseSeconds, ?string $executionToken = null): ?string
    {
        $now = time();
        $token = $executionToken ?? $this->generateToken();

        $affected = CmsJobRun::updateAll(
            [
                'status' => CmsJobRun::STATUS_RUNNING,
                'execution_token' => $token,
                'worker_id' => $workerId,
                'worker_pid' => function_exists('getmypid') ? (int)getmypid() : null,
                'lease_until' => $now + $leaseSeconds,
                'attempt' => new Expression('attempt + 1'),
                'updated_at' => $now,
            ],
            [
                'and',
                ['id' => $runId, 'status' => CmsJobRun::STATUS_RUNNING],
                ['execution_token' => $previousToken],
                ['<', 'lease_until', $now],
            ]
        );

        return $affected === 1 ? $token : null;
    }

    /**
     * Записать прогресс и продлить аренду одним запросом.
     *
     * @return bool false — запуск больше не наш, писать нельзя
     */
    public function writeProgress(int $runId, string $token, array $values, int $leaseSeconds): bool
    {
        $values['lease_until'] = time() + $leaseSeconds;
        $values['updated_at'] = time();

        return $this->fencedUpdate($runId, $token, $values);
    }

    /**
     * Продлить только аренду.
     */
    public function extendLease(int $runId, string $token, int $leaseSeconds): bool
    {
        return $this->fencedUpdate($runId, $token, [
            'lease_until' => time() + $leaseSeconds,
            'updated_at' => time(),
        ]);
    }

    /**
     * Перевести запуск в конечное состояние.
     *
     * @return bool false — запуск перехвачен, вызывающий не вправе ни менять
     *              статус, ни публиковать повтор, ни поднимать событие
     */
    public function finish(int $runId, string $token, array $values, array $event = []): bool
    {
        $values['execution_token'] = null;
        $values['lease_until'] = null;
        $values['worker_id'] = null;
        $values['worker_pid'] = null;

        return $this->db->transaction(function () use ($runId, $token, $values, $event) {
            if (!$this->fencedUpdate($runId, $token, $values)) {
                return false;
            }
            $this->appendEvent($runId, $event);
            return true;
        });
    }

    /**
     * Вернуть запуск в очередь и создать ровно одно новое сообщение.
     *
     * Оба действия в одной транзакции: DB-транспорт пишет сообщение тем же
     * соединением, поэтому либо строка ожидает и сообщение существует, либо не
     * изменилось ничего. Сбой публикации откатывает и перевод статуса — запуск
     * остаётся работающим с истёкшей арендой, и его штатно подберёт уборка.
     *
     * @param bool $refundAttempt попытка не была израсходована по существу —
     *                            занят ресурс, закрыто окно, сохранён курсор
     * @return bool false — запуск больше не наш, ничего не сделано
     */
    public function requeue(
        int $runId,
        string $token,
        string $queueName,
        int $delay = 0,
        bool $refundAttempt = false,
        array $extraValues = [],
        array $event = []
    ): bool {
        $now = time();

        $values = array_merge($extraValues, [
            'status' => CmsJobRun::STATUS_QUEUED,
            'execution_token' => null,
            'worker_id' => null,
            'worker_pid' => null,
            'lease_until' => null,
            'available_at' => $now + $delay,
            'updated_at' => $now,
        ]);

        if ($refundAttempt) {
            $values['attempt'] = new Expression('GREATEST(attempt - 1, 0)');
        }

        $transaction = $this->db->beginTransaction();

        try {
            if (!$this->fencedUpdate($runId, $token, $values)) {
                $transaction->rollBack();

                return false;
            }

            $this->publishRun(CmsJobRun::findOne($runId), $queueName, $delay);
            $this->appendEvent($runId, $event);

            $transaction->commit();
        } catch (\Throwable $e) {
            $transaction->rollBack();

            throw $e;
        }

        return true;
    }

    /**
     * Разобрать запуски с истёкшей арендой.
     *
     * Каждая строка обрабатывается собственным условным UPDATE в собственной
     * транзакции, поэтому две параллельные уборки не могут обработать одну
     * строку дважды и не могут создать два сообщения на один запуск.
     *
     * @param callable $isIdempotent function (CmsJobRun $run): bool
     * @return array счётчики по исходам
     */
    public function reapExpired(callable $isIdempotent, int $limit = 100): array
    {
        $now = time();
        $result = ['requeued' => 0, 'timed_out' => 0];

        $expired = CmsJobRun::find()
            ->andWhere(['status' => CmsJobRun::STATUS_RUNNING])
            ->andWhere(['<', 'lease_until', $now])
            ->limit($limit)
            ->all();

        foreach ($expired as $run) {
            $token = (string)$run->execution_token;

            // Повторять можно только объявленное идемпотентным: воркер мог
            // упасть уже после внешнего вызова, и результат неизвестен.
            $canRetry = $token !== ''
                && call_user_func($isIdempotent, $run)
                && $run->attempt < $run->max_attempts;

            if ($canRetry) {
                if ($this->requeueExpired($run, $token)) {
                    $result['requeued']++;
                }

                continue;
            }

            $affected = CmsJobRun::updateAll(
                [
                    'status' => CmsJobRun::STATUS_TIMED_OUT,
                    'execution_token' => null,
                    'worker_id' => null,
                    'worker_pid' => null,
                    'lease_until' => null,
                    'dedup_active' => null,
                    'error_code' => 'lease_expired',
                    'error_message' => 'Аренда воркера истекла, задание не завершилось.',
                    'finished_at' => $now,
                    'updated_at' => $now,
                ],
                [
                    'and',
                    ['id' => $run->id, 'status' => CmsJobRun::STATUS_RUNNING],
                    ['execution_token' => $run->execution_token],
                    ['<', 'lease_until', $now],
                ]
            );

            if ($affected === 1) {
                $result['timed_out']++;
            }
        }

        return $result;
    }

    /**
     * Вернуть в очередь запуск с истёкшей арендой, с публикацией сообщения.
     */
    protected function requeueExpired(CmsJobRun $run, string $token): bool
    {
        $now = time();
        $transaction = $this->db->beginTransaction();

        try {
            $affected = CmsJobRun::updateAll(
                [
                    'status' => CmsJobRun::STATUS_QUEUED,
                    'execution_token' => null,
                    'worker_id' => null,
                    'worker_pid' => null,
                    'lease_until' => null,
                    'available_at' => $now,
                    'updated_at' => $now,
                ],
                [
                    'and',
                    ['id' => $run->id, 'status' => CmsJobRun::STATUS_RUNNING],
                    ['execution_token' => $token],
                    ['<', 'lease_until', $now],
                ]
            );

            if ($affected !== 1) {
                $transaction->rollBack();

                return false;
            }

            $this->publishRun($run, $run->queue_name, 0);

            $transaction->commit();
        } catch (\Throwable $e) {
            $transaction->rollBack();

            throw $e;
        }

        return true;
    }

    /**
     * Удалить запуск, если он всё ещё принадлежит этому маркеру.
     *
     * Нужно для технических сообщений, которые не оставляют следа при успехе.
     * Проверка владения отдельным запросом с последующим DELETE оставляла окно
     * между ними; условие в самом DELETE его закрывает.
     *
     * @return bool false — запуск перехвачен, удалять нельзя
     */
    public function deleteOwned(int $runId, string $token): bool
    {
        $affected = CmsJobRun::deleteAll([
            'id' => $runId,
            'execution_token' => $token,
            'status' => CmsJobRun::STATUS_RUNNING,
        ]);

        return $affected === 1;
    }

    /**
     * Владеет ли указанный маркер этим запуском прямо сейчас.
     */
    public function stillOwns(int $runId, string $token): bool
    {
        return CmsJobRun::find()
            ->andWhere([
                'id' => $runId,
                'execution_token' => $token,
                'status' => CmsJobRun::STATUS_RUNNING,
            ])
            ->exists();
    }

    /**
     * @return string
     */
    public function generateToken(): string
    {
        return bin2hex(random_bytes(16));
    }

    /** Повтор использует приоритет запуска и TTR определения типа. */
    protected function publishRun(CmsJobRun $run, string $queue, int $delay): void
    {
        $registry = \Yii::$app->get('jobRegistry');
        $definition = $registry->has($run->job_type) ? $registry->get($run->job_type) : null;
        $ttr = $definition ? (int)$definition->timeout + (int)$definition->leaseSeconds : 0;
        $this->publisher->publish(new JobTransportMessage((int)$run->id), $queue, $delay, (int)$run->priority, $ttr);
    }

    protected function appendEvent(int $runId, array $values): void
    {
        if ($values) {
            $event = new CmsJobRunEvent(array_merge($values, ['cms_job_run_id' => $runId]));
            if (!$event->save(false)) {
                throw new \RuntimeException('Could not persist job transition event.');
            }
        }
    }

    /**
     * UPDATE только для владельца. MySQL считает изменённые строки, поэтому
     * при нуле проверяем владение отдельно: heartbeat в ту же секунду может
     * не изменить ни одного значения, хотя маркер по-прежнему наш.
     */
    protected function fencedUpdate(int $runId, string $token, array $values): bool
    {
        $affected = CmsJobRun::updateAll($values, [
            'id' => $runId,
            'execution_token' => $token,
            'status' => CmsJobRun::STATUS_RUNNING,
        ]);

        if ($affected >= 1) {
            return true;
        }

        return $this->stillOwns($runId, $token);
    }
}
