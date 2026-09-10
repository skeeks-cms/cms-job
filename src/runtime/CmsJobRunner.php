<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 * @author Semenov Alexander <semenov@skeeks.com>
 */

namespace skeeks\cms\job\runtime;

use skeeks\cms\job\exceptions\JobCancelledException;
use skeeks\cms\job\exceptions\JobFencedException;
use skeeks\cms\job\exceptions\JobRequeueException;
use skeeks\cms\job\JobRegistry;
use skeeks\cms\job\JobTypeDefinition;
use skeeks\cms\job\models\CmsJobRun;
use skeeks\cms\job\models\CmsJobRunEvent;
use yii\base\Component;
use yii\di\Instance;

/**
 * Выполнение запуска по его идентификатору.
 *
 * Это доменная точка входа. Она ничего не знает о том, чем доставлено
 * сообщение: транспорт передаёт только `run_id`, а всё остальное читается из
 * `cms_job_run`. Благодаря этому замена транспорта не затрагивает ни один
 * обработчик.
 *
 * Повторная доставка одного и того же `run_id` безопасна и является штатным
 * поведением: транспорт восстанавливает сообщение после падения процесса и не
 * обязан гарантировать однократность. Решение, что делать с повтором,
 * принимается здесь по фактическому состоянию запуска.
 */
class CmsJobRunner extends Component
{
    /**
     * @event событие после завершения задания, любым исходом
     */
    const EVENT_FINISHED = 'jobFinished';

    /**
     * Исходы обработки одного сообщения.
     */
    const OUTCOME_EXECUTED = 'executed';
    const OUTCOME_ALREADY_FINISHED = 'already-finished';
    const OUTCOME_OWNED_BY_OTHER = 'owned-by-other';
    const OUTCOME_MISSING = 'missing';
    const OUTCOME_CANCELLED = 'cancelled';
    const OUTCOME_DEFERRED = 'deferred';
    const OUTCOME_FENCED = 'fenced';
    const OUTCOME_NOT_STARTED = 'not-started';

    /** @var string|null Unique attempt token supplied by an isolated parent. */
    public $executionToken;

    /**
     * @var JobRegistry|string|array
     */
    public $registry = 'jobRegistry';

    /**
     * @var JobRunStore|string|array
     */
    public $store = 'jobRunStore';

    /**
     * @var LockManager|string|array
     */
    public $lockManager = 'jobLockManager';

    /**
     * @var JobErrorClassifier|array
     */
    public $classifier = JobErrorClassifier::class;

    /**
     * @var RetryPolicy|array
     */
    public $retryPolicy = RetryPolicy::class;

    /**
     * @var string Идентификатор процесса-владельца.
     */
    public $workerId;

    public function init()
    {
        parent::init();

        $this->registry = Instance::ensure($this->registry, JobRegistry::class);
        $this->store = Instance::ensure($this->store, JobRunStore::class);
        $this->lockManager = Instance::ensure($this->lockManager, LockManager::class);
        $this->classifier = Instance::ensure($this->classifier, JobErrorClassifier::class);
        $this->retryPolicy = Instance::ensure($this->retryPolicy, RetryPolicy::class);

        if (!$this->workerId) {
            $this->workerId = gethostname().':'.(function_exists('getmypid') ? getmypid() : '0');
        }
    }

    /**
     * Обработать одно транспортное сообщение.
     *
     * @return string один из OUTCOME_*
     */
    public function execute($runId)
    {
        $run = CmsJobRun::findOne(['id' => (int)$runId]);

        if (!$run) {
            // Запись удалена: эфемерное задание отработало и убрало себя, или
            // сработала политика хранения. Сообщение подтверждаем.
            return self::OUTCOME_MISSING;
        }

        if ($run->getIsFinished()) {
            // Штатный случай для отменённой в очереди операции: транспортное
            // сообщение осталось, но выполнять уже нечего.
            return self::OUTCOME_ALREADY_FINISHED;
        }

        if (!$this->registry->has($run->job_type)) {
            $this->failUnclaimed(
                $run,
                'unknown_job_type',
                "Тип задания '{$run->job_type}' не зарегистрирован."
            );

            return self::OUTCOME_EXECUTED;
        }

        $definition = $this->registry->get($run->job_type);

        if ($run->status === CmsJobRun::STATUS_RUNNING) {
            return $this->handleRedeliveryOfRunning($run, $definition);
        }

        // Отмену, запрошенную пока задание ждало в очереди, обрабатываем до
        // захвата: запускать обработчик незачем.
        if ($run->cancel_requested_at) {
            $token = $this->store->claim((int)$run->id, $this->workerId, $definition->leaseSeconds, $this->executionToken);
            if ($token === null) {
                return self::OUTCOME_OWNED_BY_OTHER;
            }

            return $this->finishCancelled($run, $definition, $token)
                ? self::OUTCOME_CANCELLED : self::OUTCOME_FENCED;
        }

        if ($run->available_at > time()) {
            // Сообщение пришло раньше срока: ждём отложенного повтора.
            return self::OUTCOME_DEFERRED;
        }

        if (!$definition->isWindowOpen()) {
            $token = $this->store->claim((int)$run->id, $this->workerId, $definition->leaseSeconds, $this->executionToken);
            if ($token === null) {
                return self::OUTCOME_OWNED_BY_OTHER;
            }

            $requeued = $this->store->requeue(
                (int)$run->id,
                $token,
                $run->queue_name,
                max(60, $definition->nextWindowOpening() - time()),
                true
            );

            // false означает, что запуск уже не наш. Считать его отложенным
            // нельзя: статус, сообщение и блокировка принадлежат другому
            // владельцу, и трогать их мы не вправе.
            return $requeued ? self::OUTCOME_DEFERRED : self::OUTCOME_FENCED;
        }

        $token = $this->store->claim((int)$run->id, $this->workerId, $definition->leaseSeconds, $this->executionToken);

        if ($token === null) {
            // Гонку выиграл другой воркер.
            return self::OUTCOME_OWNED_BY_OTHER;
        }

        $run->refresh();

        return $this->runClaimed($run, $definition, $token);
    }

    /**
     * Зафиксировать отказ по неподдерживаемой версии конверта.
     *
     * Вызывается транспортным адаптером, когда сообщение прочитать можно, а
     * доверять ему нельзя. Отказ терминальный и видимый: иначе запуск навсегда
     * остался бы ожидающим без единого сообщения в очереди.
     */
    public function failUnsupportedEnvelope(int $runId, int $version)
    {
        $run = CmsJobRun::findOne(['id' => $runId]);

        if (!$run || $run->getIsFinished()) {
            return self::OUTCOME_MISSING;
        }

        $this->failUnclaimed(
            $run,
            'unsupported_envelope',
            "Транспортное сообщение версии {$version} не поддерживается текущим кодом."
            .' Операцию нужно запустить заново.'
        );

        return self::OUTCOME_EXECUTED;
    }

    /**
     * Дочерний процесс задания был убит по истечении TTR.
     *
     * Отличается от истёкшей аренды тем, что момент известен точно: процесс
     * уже мёртв, продолжать работу некому. Поэтому решение принимается сразу,
     * а не откладывается до уборки.
     */
    public function failHardTimeout(int $runId, int $ttr, ?string $executionToken = null)
    {
        return $this->failStoppedExecution($runId, $executionToken, JobErrorClassifier::TIMEOUT,
            "Задание превысило предельное время выполнения ({$ttr} с) и было прервано.", CmsJobRun::STATUS_TIMED_OUT);
    }

    /** Called only after the parent has observed its isolated child exit. */
    public function failChildCrash(int $runId, int $exitCode, ?string $executionToken = null)
    {
        return $this->failStoppedExecution($runId, $executionToken, 'worker_crashed',
            "Процесс задания аварийно завершился (код {$exitCode}). Подробности в журнале воркера.", CmsJobRun::STATUS_FAILED);
    }

    protected function failStoppedExecution(int $runId, ?string $executionToken, string $errorCode, string $message, string $status)
    {
        $run = CmsJobRun::findOne(['id' => $runId]);

        if (!$run || $run->getIsFinished()) {
            return self::OUTCOME_ALREADY_FINISHED;
        }

        if ($run->status !== CmsJobRun::STATUS_RUNNING) {
            return self::OUTCOME_NOT_STARTED;
        }

        // Only the token issued by the parent identifies the killed attempt.
        // Never adopt the current owner's token from a database read or PID.
        if (!$executionToken || !hash_equals((string)$run->execution_token, $executionToken)) {
            return self::OUTCOME_FENCED;
        }

        $token = $executionToken;

        $definition = $this->registry->has($run->job_type) ? $this->registry->get($run->job_type) : null;
        $cancelled = $run->cancel_requested_at !== null;
        if ($cancelled) {
            $status = CmsJobRun::STATUS_CANCELLED;
        }

        $event = $this->eventValues($run, CmsJobRunEvent::LEVEL_ERROR, $message);

        // Повторить можно только объявленное идемпотентным: процесс убит на
        // неизвестном шаге, и внешний вызов мог успеть пройти.
        if (!$cancelled && $definition && $definition->idempotent && $run->attempt < $run->max_attempts) {
            $delay = $this->retryPolicy->delayFor((int)$run->attempt);

            if ($this->store->requeue((int)$run->id, $token, $run->queue_name, $delay, false, [
                'error_code' => $errorCode,
                'error_message' => $message,
            ], $event)) {
                $this->lockManager->release($run->resource_key, $token);
                return self::OUTCOME_DEFERRED;
            }

            return self::OUTCOME_OWNED_BY_OTHER;
        }

        $now = time();
        $values = [
            'status' => $status,
            'error_code' => $errorCode,
            'error_message' => $message,
            'finished_at' => $now,
            'dedup_active' => null,
            'updated_at' => $now,
        ];

        if ($definition && $definition->retentionDays > 0) {
            $values['retention_until'] = $now + $definition->retentionDays * 86400;
        }

        if (!$this->store->finish((int)$run->id, $token, $values, $event)) {
            return self::OUTCOME_OWNED_BY_OTHER;
        }

        $this->lockManager->release($run->resource_key, $token);
        $run->refresh();
        $this->triggerFinished($run, $status);

        return self::OUTCOME_EXECUTED;
    }

    /**
     * Сообщение доставлено повторно, а запуск числится работающим.
     */
    protected function handleRedeliveryOfRunning(CmsJobRun $run, JobTypeDefinition $definition)
    {
        $now = time();

        if ($run->lease_until !== null && $run->lease_until >= $now) {
            // Аренда жива: работу ведёт другой процесс, дублировать нельзя.
            return self::OUTCOME_OWNED_BY_OTHER;
        }

        // Аренда истекла. Перезапустить можно только объявленное
        // идемпотентным: воркер мог упасть уже после внешнего вызова, и
        // повтор привёл бы к повторному side effect.
        if (!$definition->idempotent || $run->attempt >= $run->max_attempts) {
            $finished = $this->store->finish((int)$run->id, (string)$run->execution_token, [
                'status' => CmsJobRun::STATUS_TIMED_OUT,
                'error_code' => 'lease_expired',
                'error_message' => 'Аренда воркера истекла, результат операции неизвестен.',
                'finished_at' => $now,
                'dedup_active' => null,
                'updated_at' => $now,
            ]);

            if (!$finished) {
                return self::OUTCOME_OWNED_BY_OTHER;
            }

            $run->refresh();
            $this->triggerFinished($run, CmsJobRun::STATUS_TIMED_OUT);

            return self::OUTCOME_EXECUTED;
        }

        $token = $this->store->reclaimExpired(
            (int)$run->id,
            $run->execution_token,
            $this->workerId,
            $definition->leaseSeconds,
            $this->executionToken
        );

        if ($token === null) {
            return self::OUTCOME_OWNED_BY_OTHER;
        }

        $run->refresh();

        return $this->runClaimed($run, $definition, $token);
    }

    /**
     * Выполнить захваченный запуск.
     */
    protected function runClaimed(CmsJobRun $run, JobTypeDefinition $definition, $token)
    {
        if ($run->resource_key) {
            $acquired = $this->lockManager->acquire(
                $run->resource_key,
                (int)$run->id,
                $token,
                $this->workerId,
                $definition->leaseSeconds * 3
            );

            if (!$acquired) {
                // Ресурс занят: возвращаем в очередь, попытку не расходуем.
                $requeued = $this->store->requeue((int)$run->id, $token, $run->queue_name, 15, true);

                return $requeued ? self::OUTCOME_DEFERRED : self::OUTCOME_FENCED;
            }
        }

        $reporter = new JobReporter([
            'run' => $run,
            'definition' => $definition,
            'store' => $this->store,
            'lockManager' => $this->lockManager,
            'executionToken' => $token,
        ]);

        $context = new JobContext([
            'run' => $run,
            'definition' => $definition,
        ]);
        $reporter->context = $context;

        $outcome = self::OUTCOME_EXECUTED;

        try {
            $handler = $definition->createHandler();
            $handler->run($context, $reporter);

            $reporter->finalize();
            $outcome = $this->finishSucceeded($run, $definition, $token)
                ? self::OUTCOME_EXECUTED : self::OUTCOME_FENCED;
        } catch (JobFencedException $e) {
            // Запуск уже не наш: ничего не пишем и не освобождаем.
            \Yii::warning($e->getMessage(), 'skeeks/job');

            return self::OUTCOME_FENCED;
        } catch (JobRequeueException $e) {
            $this->safeFinalize($reporter);
            $requeued = $this->store->requeue((int)$run->id, $token, $run->queue_name, $e->delay, true);
            $outcome = $requeued ? self::OUTCOME_DEFERRED : self::OUTCOME_FENCED;
        } catch (JobCancelledException $e) {
            $this->safeFinalize($reporter);
            $outcome = $this->finishCancelled($run, $definition, $token)
                ? self::OUTCOME_CANCELLED : self::OUTCOME_FENCED;
        } catch (\Throwable $e) {
            $this->safeFinalize($reporter);
            $outcome = $this->handleFailure($run, $definition, $token, $e);
        } finally {
            if ($run->resource_key) {
                $this->lockManager->release($run->resource_key, $token);
            }
        }

        return $outcome;
    }

    /**
     * Досохранить буфер репортера, не превращая потерю владения в отказ.
     */
    protected function safeFinalize(JobReporter $reporter)
    {
        try {
            $reporter->finalize();
        } catch (JobFencedException $e) {
            \Yii::warning($e->getMessage(), 'skeeks/job');
        }
    }

    /**
     * Итоговый статус считается по счётчикам репортера, а не по коду возврата.
     */
    protected function finishSucceeded(CmsJobRun $run, JobTypeDefinition $definition, $token)
    {
        $run->refresh();

        if ($run->cancel_requested_at) {
            return $this->finishCancelled($run, $definition, $token);
        }

        $status = ($run->error_count > 0 || $run->warning_count > 0)
            ? CmsJobRun::STATUS_SUCCEEDED_WITH_WARNINGS
            : CmsJobRun::STATUS_SUCCEEDED;

        // Техническое сообщение не оставляет следа при успехе: иначе журнал
        // операций превратился бы в перечень каждого отправленного письма.
        if ($run->visibility === CmsJobRun::VISIBILITY_TRANSIENT
            && $status === CmsJobRun::STATUS_SUCCEEDED) {
            // Сначала владение, потом событие.
            //
            // Прежний порядок поднимал EVENT_FINISHED до проверки владения:
            // перехваченный воркер успевал разослать уведомление об операции,
            // которую в этот момент заново выполнял другой процесс. Проверка
            // же была отдельным SELECT, между которым и DELETE оставалось
            // окно. Удаление под маркером решает и то, и другое.
            if (!$this->store->deleteOwned((int)$run->id, (string)$token)) {
                \Yii::warning(
                    "Запуск #{$run->id} перехвачен до удаления технической записи;"
                    .' финал прежнего владельца отброшен.',
                    'skeeks/job'
                );

                return false;
            }

            $this->triggerFinished($run, $status);

            return true;
        }

        return $this->applyFinalState($run, $definition, $status, $token);
    }

    protected function finishCancelled(CmsJobRun $run, $definition, $token)
    {
        return $this->applyFinalState($run, $definition, CmsJobRun::STATUS_CANCELLED, $token,
            $this->eventValues($run, CmsJobRunEvent::LEVEL_WARNING, 'Операция отменена.'));
    }

    /**
     * Отказ до захвата: писать под маркером нечего.
     */
    protected function failUnclaimed(CmsJobRun $run, $code, $message)
    {
        $now = time();

        $changed = $run::getDb()->transaction(function () use ($run, $code, $message, $now) {
            $affected = CmsJobRun::updateAll([
                'status' => CmsJobRun::STATUS_FAILED,
                'error_code' => $code,
                'error_message' => $message,
                'finished_at' => $now,
                'dedup_active' => null,
                'execution_token' => null,
                'lease_until' => null,
                'updated_at' => $now,
            ], ['id' => $run->id, 'status' => CmsJobRun::STATUS_QUEUED, 'execution_token' => null]);
            if ($affected !== 1) {
                return false;
            }
            $this->writeEvent($run, CmsJobRunEvent::LEVEL_ERROR, $message);
            return true;
        });
        if (!$changed) {
            return;
        }

        $run->refresh();
        $this->triggerFinished($run, CmsJobRun::STATUS_FAILED);
    }

    /**
     * Решение о повторе.
     *
     * Бизнес-повторами владеет ядро: транспорт восстанавливает только
     * потерянные сообщения, а сколько раз и с какой паузой повторять
     * прикладную операцию, знает определение типа.
     */
    protected function handleFailure(CmsJobRun $run, JobTypeDefinition $definition, $token, \Throwable $error)
    {
        $kind = $this->classifier->classify($error, $definition);

        \Yii::error(
            "Задание #{$run->id} {$run->job_type} завершилось ошибкой ({$kind}): ".$error->getMessage()
            ."\n".$error->getTraceAsString(),
            'skeeks/job'
        );

        $run->error_code = $kind;
        $run->error_message = $error->getMessage();

        if ($kind === JobErrorClassifier::CANCELLED) {
            return $this->finishCancelled($run, $definition, $token)
                ? self::OUTCOME_CANCELLED : self::OUTCOME_FENCED;
        }

        $canRetry = $this->classifier->isRetryable($kind) && $run->attempt < $run->max_attempts;

        if ($canRetry) {
            $delay = $this->retryPolicy->delayFor($run->attempt);

            $event = $this->eventValues(
                $run,
                CmsJobRunEvent::LEVEL_WARNING,
                "Попытка {$run->attempt} из {$run->max_attempts} не удалась: ".$error->getMessage()
                .". Повтор через {$delay} с."
            );

            // Перевод в очередь и публикация нового сообщения — одна
            // транзакция. Прежнее сообщение к этому моменту подтверждено
            // обработчиком транспорта, поэтому без публикации запуск остался
            // бы ожидающим, но никем не разбуженным.
            $requeued = $this->store->requeue((int)$run->id, (string)$token, $run->queue_name, $delay, false, [
                'error_code' => $kind,
                'error_message' => $error->getMessage(),
            ], $event);

            return $requeued ? self::OUTCOME_DEFERRED : self::OUTCOME_FENCED;
        }

        $finished = $this->applyFinalState($run, $definition, CmsJobRun::STATUS_FAILED, $token,
            $this->eventValues($run, CmsJobRunEvent::LEVEL_ERROR, $error->getMessage()));

        return $finished ? self::OUTCOME_EXECUTED : self::OUTCOME_FENCED;
    }

    /**
     * Общий финал: статус, сроки, снятие ключа дедупликации.
     */
    protected function applyFinalState(CmsJobRun $run, $definition, $status, $token, array $event = [])
    {
        $now = time();

        $values = [
            'status' => $status,
            'finished_at' => $now,
            'updated_at' => $now,
            // Ключ дедупликации снимается только при завершении: пока он равен
            // dedup_key, повторная постановка того же задания невозможна, а
            // после NULL в уникальном индексе не конфликтует.
            'dedup_active' => null,
        ];

        if ($run->error_code) {
            $values['error_code'] = $run->error_code;
            $values['error_message'] = $run->error_message;
        }

        if ($definition && $definition->retentionDays > 0) {
            $values['retention_until'] = $now + $definition->retentionDays * 86400;
        }

        // Запуск мог быть перехвачен, пока обработчик доделывал работу. Тогда
        // прежний владелец не вправе ни менять статус, ни поднимать событие
        // завершения: уведомление ушло бы об операции, которую в этот момент
        // заново выполняет другой процесс.
        if (!$this->store->finish((int)$run->id, (string)$token, $values, $event)) {
            \Yii::warning(
                "Запуск #{$run->id} перехвачен до записи результата, финал прежнего владельца отброшен.",
                'skeeks/job'
            );

            return false;
        }

        $run->refresh();
        $this->triggerFinished($run, $status);
        return true;
    }

    protected function eventValues(CmsJobRun $run, $level, $message): array
    {
        return ['level' => $level, 'stage' => $run->stage, 'message' => $message];
    }

    protected function triggerFinished(CmsJobRun $run, $status)
    {
        $this->trigger(self::EVENT_FINISHED, new JobFinishedEvent([
            'run' => $run,
            'status' => $status,
        ]));
    }

    protected function writeEvent(CmsJobRun $run, $level, $message)
    {
        $event = new CmsJobRunEvent([
            'cms_job_run_id' => $run->id,
            'level' => $level,
            'stage' => $run->stage,
            'message' => $message,
        ]);

        $event->save(false);
    }
}
