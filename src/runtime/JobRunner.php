<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 * @author Semenov Alexander <semenov@skeeks.com>
 */

namespace skeeks\cms\job\runtime;

use skeeks\cms\job\contracts\JobTransportInterface;
use skeeks\cms\job\exceptions\JobCancelledException;
use skeeks\cms\job\exceptions\JobRequeueException;
use skeeks\cms\job\JobRegistry;
use skeeks\cms\job\models\CmsJobRun;
use skeeks\cms\job\models\CmsJobRunEvent;
use yii\base\Component;
use yii\di\Instance;

/**
 * Выполнение одного захваченного задания.
 *
 * Здесь живут решения, которые нельзя отдавать обработчику: блокировка
 * ресурса, окно выполнения, итоговый статус, повторы и освобождение всего
 * захваченного.
 */
class JobRunner extends Component
{
    /**
     * @event событие после завершения задания, любым исходом
     */
    const EVENT_FINISHED = 'jobFinished';

    /**
     * @var JobRegistry|string|array
     */
    public $registry = 'jobRegistry';

    /**
     * @var JobTransportInterface|string|array
     */
    public $transport = 'jobTransport';

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

    public function init()
    {
        parent::init();

        $this->registry = Instance::ensure($this->registry, JobRegistry::class);
        $this->transport = Instance::ensure($this->transport, JobTransportInterface::class);
        $this->lockManager = Instance::ensure($this->lockManager, LockManager::class);
        $this->classifier = Instance::ensure($this->classifier, JobErrorClassifier::class);
        $this->retryPolicy = Instance::ensure($this->retryPolicy, RetryPolicy::class);
    }

    /**
     * Выполнить захваченное задание.
     */
    public function run(CmsJobRun $run)
    {
        if (!$this->registry->has($run->job_type)) {
            $this->finishFailed($run, 'unknown_job_type', "Тип задания '{$run->job_type}' не зарегистрирован.");

            return;
        }

        $definition = $this->registry->get($run->job_type);

        // Окно выполнения проверяется до захвата ресурса, чтобы не держать
        // блокировку впустую.
        if (!$definition->isWindowOpen()) {
            $this->transport->release($run, max(60, $definition->nextWindowOpening() - time()));

            return;
        }

        if ($run->resource_key
            && !$this->lockManager->acquire($run->resource_key, $run, $definition->leaseSeconds * 3)) {
            // Ресурс занят: возвращаем в очередь, попытку не расходуем.
            $run->updateAttributes(['attempt' => max(0, (int)$run->attempt - 1)]);
            $this->transport->release($run, 15);

            return;
        }

        $reporter = new JobReporter([
            'run' => $run,
            'definition' => $definition,
        ]);

        $context = new JobContext([
            'run' => $run,
            'definition' => $definition,
        ]);
        $reporter->context = $context;

        try {
            $handler = $definition->createHandler();
            $handler->run($context, $reporter);

            $reporter->finalize();
            $this->finishSucceeded($run, $definition);
        } catch (JobRequeueException $e) {
            // Не отказ: курсор сохранён, продолжаем в новом процессе,
            // попытка не расходуется.
            $reporter->finalize();
            $run->updateAttributes(['attempt' => max(0, (int)$run->attempt - 1)]);
            $this->transport->release($run, $e->delay);
        } catch (JobCancelledException $e) {
            $reporter->finalize();
            $this->finishCancelled($run);
        } catch (\Throwable $e) {
            $reporter->finalize();
            $this->handleFailure($run, $definition, $e);
        } finally {
            if ($run->resource_key) {
                $this->lockManager->release($run->resource_key, $run);
            }
        }
    }

    /**
     * Итоговый статус считается по счётчикам репортера, а не по коду возврата.
     */
    protected function finishSucceeded(CmsJobRun $run, $definition)
    {
        $run->refresh();

        if ($run->cancel_requested_at) {
            $this->finishCancelled($run);

            return;
        }

        $status = ($run->error_count > 0 || $run->warning_count > 0)
            ? CmsJobRun::STATUS_SUCCEEDED_WITH_WARNINGS
            : CmsJobRun::STATUS_SUCCEEDED;

        // Техническое сообщение не оставляет следа при успехе: иначе журнал
        // операций превратился бы в перечень каждого отправленного письма.
        if ($run->visibility === CmsJobRun::VISIBILITY_TRANSIENT
            && $status === CmsJobRun::STATUS_SUCCEEDED) {
            $this->trigger(self::EVENT_FINISHED, new JobFinishedEvent([
                'run' => $run,
                'status' => $status,
            ]));

            $run->delete();

            return;
        }

        $this->applyFinalState($run, $definition, $status);
    }

    protected function finishCancelled(CmsJobRun $run)
    {
        $definition = $this->registry->has($run->job_type) ? $this->registry->get($run->job_type) : null;

        $this->writeEvent($run, CmsJobRunEvent::LEVEL_WARNING, 'Операция отменена.');
        $this->applyFinalState($run, $definition, CmsJobRun::STATUS_CANCELLED);
    }

    protected function finishFailed(CmsJobRun $run, $code, $message)
    {
        $definition = $this->registry->has($run->job_type) ? $this->registry->get($run->job_type) : null;

        $run->error_code = $code;
        $run->error_message = $message;

        $this->writeEvent($run, CmsJobRunEvent::LEVEL_ERROR, $message);
        $this->applyFinalState($run, $definition, CmsJobRun::STATUS_FAILED);
    }

    /**
     * Решение о повторе.
     */
    protected function handleFailure(CmsJobRun $run, $definition, \Throwable $error)
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
            $this->finishCancelled($run);

            return;
        }

        $canRetry = $this->classifier->isRetryable($kind) && $run->attempt < $run->max_attempts;

        if ($canRetry) {
            $delay = $this->retryPolicy->delayFor($run->attempt);

            $this->writeEvent(
                $run,
                CmsJobRunEvent::LEVEL_WARNING,
                "Попытка {$run->attempt} из {$run->max_attempts} не удалась: ".$error->getMessage()
                .". Повтор через {$delay} с."
            );

            $run->status = CmsJobRun::STATUS_QUEUED;
            $run->available_at = time() + $delay;
            $run->worker_id = null;
            $run->worker_pid = null;
            $run->lease_until = null;
            $run->save(false);

            return;
        }

        $this->writeEvent($run, CmsJobRunEvent::LEVEL_ERROR, $error->getMessage());
        $this->applyFinalState($run, $definition, CmsJobRun::STATUS_FAILED);
    }

    /**
     * Общий финал: статус, сроки, снятие ключа дедупликации.
     */
    protected function applyFinalState(CmsJobRun $run, $definition, $status)
    {
        $now = time();

        $run->status = $status;
        $run->finished_at = $now;
        $run->lease_until = null;
        $run->worker_id = null;
        $run->worker_pid = null;

        // Ключ дедупликации снимается только при завершении: пока он равен
        // dedup_key, повторная постановка того же задания невозможна, а после
        // NULL в уникальном индексе не конфликтует и запуск снова разрешён.
        $run->dedup_active = null;

        if ($definition && $definition->retentionDays > 0) {
            $run->retention_until = $now + $definition->retentionDays * 86400;
        }

        $run->save(false);

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
