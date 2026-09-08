<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 * @author Semenov Alexander <semenov@skeeks.com>
 */

namespace skeeks\cms\job;

use skeeks\cms\job\contracts\JobPublisherInterface;
use skeeks\cms\job\models\CmsJobRun;
use skeeks\cms\job\runtime\JobRunStore;
use skeeks\cms\job\transport\JobTransportMessage;
use skeeks\cms\job\JobTypeDefinition;
use yii\base\Component;
use yii\base\InvalidConfigException;
use yii\db\IntegrityException;
use yii\di\Instance;
use yii\helpers\ArrayHelper;

/**
 * Точка входа подсистемы заданий: Yii::$app->jobs.
 *
 * Постановка задания — обычная запись в ту же базу, поэтому её можно
 * выполнить внутри транзакции бизнес-события. Именно это снимает
 * необходимость в outbox: терять между коммитом и публикацией нечего.
 */
class CmsJobComponent extends Component
{
    /**
     * @var JobRegistry|string|array
     */
    public $registry = 'jobRegistry';

    /**
     * @var JobPublisherInterface|string|array
     */
    public $publisher = 'jobPublisher';

    /**
     * @var JobRunStore|string|array
     */
    public $store = 'jobRunStore';

    /**
     * @var \yii\db\Connection|string|array
     *
     * Обязан быть тем же экземпляром соединения, что использует транспорт:
     * на этом держится общая транзакция постановки.
     */
    public $db = 'db';

    public function init()
    {
        parent::init();

        $this->registry = Instance::ensure($this->registry, JobRegistry::class);
        $this->publisher = Instance::ensure($this->publisher, JobPublisherInterface::class);
        $this->store = Instance::ensure($this->store, JobRunStore::class);
        $this->db = Instance::ensure($this->db, \yii\db\Connection::class);
    }

    /**
     * @return JobRegistry
     */
    public function getRegistry()
    {
        return $this->registry;
    }

    /**
     * Поставить задание в очередь.
     *
     * @param string $type  зарегистрированный тип; произвольная строка
     *                      обработчиком не становится
     * @param array  $options delay, priority, dedupKey, resourceKey, title,
     *                        siteId, triggerType, triggerRef, correlationId,
     *                        createdBy, parent, maxAttempts
     * @return CmsJobRun|null null, если постановка отброшена политикой пересечения
     */
    public function push($type, array $payload = [], array $options = [])
    {
        $definition = $this->registry->get($type);

        $run = new CmsJobRun();
        $run->job_type = $definition->type;
        $run->job_version = $definition->version;
        $run->queue_name = $definition->queue;
        $run->visibility = $definition->visibility;
        $run->max_attempts = (int)ArrayHelper::getValue($options, 'maxAttempts', $definition->maxAttempts);
        $run->overlap_policy = ArrayHelper::getValue($options, 'overlapPolicy', $definition->overlapPolicy);
        $run->title = ArrayHelper::getValue($options, 'title', $definition->title);
        $run->priority = (int)ArrayHelper::getValue($options, 'priority', 100);
        $run->trigger_type = ArrayHelper::getValue($options, 'triggerType', CmsJobRun::TRIGGER_MANUAL);
        $run->trigger_ref = ArrayHelper::getValue($options, 'triggerRef');
        $run->correlation_id = ArrayHelper::getValue($options, 'correlationId');
        $run->available_at = time() + (int)ArrayHelper::getValue($options, 'delay', 0);
        $run->setPayload($payload);

        $run->cms_site_id = ArrayHelper::getValue($options, 'siteId', $this->currentSiteId());
        $run->created_by = ArrayHelper::getValue($options, 'createdBy', $this->currentUserId());

        /** @var CmsJobRun|null $parent */
        $parent = ArrayHelper::getValue($options, 'parent');
        if ($parent instanceof CmsJobRun) {
            $run->parent_id = $parent->id;
            $run->root_id = $parent->root_id ? $parent->root_id : $parent->id;
            $run->trigger_type = CmsJobRun::TRIGGER_CHILD;
        }

        $run->resource_key = $this->resolveKey(
            ArrayHelper::getValue($options, 'resourceKey'),
            $definition->resourceKey,
            $payload,
            $run
        );

        $run->dedup_key = $this->resolveKey(
            ArrayHelper::getValue($options, 'dedupKey'),
            $definition->dedupKey,
            $payload,
            $run
        );

        // Политики `skip` и `coalesce` обещают, что второй постановки не
        // будет. Обещание должна держать база: без ключа дедупликации
        // остаётся только проверка чтением, а она проигрывает гонке двух
        // одновременных постановок. Если ключ не задан явно, выводим его из
        // ресурса — единицей взаимного исключения всё равно являются данные.
        if (!$run->dedup_key
            && $run->resource_key
            && in_array($run->overlap_policy, [CmsJobRun::OVERLAP_SKIP, CmsJobRun::OVERLAP_COALESCE], true)) {
            $run->dedup_key = 'resource:'.$run->resource_key;
        }

        if (!$this->applyOverlapPolicy($run)) {
            return null;
        }

        $delay = max(0, $run->available_at - time());

        // Строка запуска и транспортное сообщение фиксируются вместе.
        //
        // DB-драйвер пишет сообщение тем же соединением, что и приложение,
        // поэтому обе вставки попадают в одну транзакцию. Это и есть причина,
        // по которой transactional outbox не нужен: невозможно состояние
        // «запуск создан, а доставки не будет» или наоборот. При переходе на
        // брокер вне базы это свойство исчезнет, и outbox придётся вводить.
        try {
            $this->db->transaction(function () use ($run, $definition, $delay) {
                if (!$run->save()) {
                    throw new \RuntimeException(
                        'Не удалось создать запуск задания: '.print_r($run->errors, true)
                    );
                }

                // root_id известен только после вставки, если задание корневое.
                if (!$run->root_id) {
                    $run->updateAttributes([
                        'root_id' => $run->parent_id ? $run->parent_id : $run->id,
                    ]);
                }

                $this->publishFor($run, $definition, $delay);
            });
        } catch (IntegrityException $e) {
            // Гонку выиграла параллельная постановка: проверка чтением в
            // applyOverlapPolicy() не видела активного задания, но уникальный
            // индекс по dedup_active увидел. Это штатный исход, а не сбой, —
            // именно ради него индекс и существует.
            if (!$run->dedup_active) {
                throw $e;
            }

            return $this->resolveLostOverlapRace($run, $definition, $payload, $delay);
        }

        return $run;
    }

    /**
     * Разобрать проигранную гонку постановки.
     *
     * @return CmsJobRun|null
     */
    protected function resolveLostOverlapRace(CmsJobRun $run, JobTypeDefinition $definition, array $payload, $delay)
    {
        $active = CmsJobRun::find()
            ->andWhere(['dedup_active' => $run->dedup_key])
            ->one();

        if (in_array($run->overlap_policy, [CmsJobRun::OVERLAP_SKIP, CmsJobRun::OVERLAP_COALESCE], true)) {
            if ($active) {
                CmsJobRun::updateAllCounters(['skipped_runs' => 1], ['id' => $active->id]);
            }

            return null;
        }

        // Для `queue` и `replace` вторая постановка законна: она просто
        // становится в очередь без признака активности, а сериализацию по
        // ресурсу обеспечит блокировка.
        $run->dedup_active = null;
        $run->id = null;
        $run->isNewRecord = true;

        $this->db->transaction(function () use ($run, $definition, $delay) {
            if (!$run->save()) {
                throw new \RuntimeException(
                    'Не удалось создать запуск задания: '.print_r($run->errors, true)
                );
            }

            if (!$run->root_id) {
                $run->updateAttributes([
                    'root_id' => $run->parent_id ? $run->parent_id : $run->id,
                ]);
            }

            $this->publishFor($run, $definition, $delay);
        });

        return $run;
    }

    /**
     * Повторно опубликовать сообщение для уже существующего запуска.
     *
     * Нужно для отложенного бизнес-повтора: прежнее сообщение обработчик
     * транспорта уже подтвердил, поэтому доставку следующей попытки надо
     * запросить заново.
     */
    public function republish(CmsJobRun $run, $delay = 0)
    {
        if (!$this->registry->has($run->job_type)) {
            return;
        }

        $this->publishFor($run, $this->registry->get($run->job_type), (int)$delay);
    }

    /**
     * Отправить техническое сообщение в транспорт.
     */
    protected function publishFor(CmsJobRun $run, JobTypeDefinition $definition, $delay = 0)
    {
        if (!$this->publisher->supports($run->queue_name)) {
            throw new InvalidConfigException(
                "Полоса '{$run->queue_name}' типа '{$run->job_type}' не настроена в jobQueueFactory."
            );
        }

        // TTR берём с запасом от предельной длительности попытки: если он
        // окажется меньше реального времени работы, транспорт вернёт
        // сообщение в очередь, пока операция ещё выполняется. Повторной
        // работы это не вызовет — маркер владения не даст второму процессу
        // тронуть чужой запуск, — но лишняя доставка бесполезна.
        $ttr = (int)$definition->timeout + (int)$definition->leaseSeconds;

        $this->publisher->publish(
            new JobTransportMessage((int)$run->id),
            $run->queue_name,
            (int)$delay,
            (int)$run->priority,
            $ttr
        );
    }

    /**
     * @return CmsJobRun|null
     */
    public function pushChild(CmsJobRun $parent, $type, array $payload = [], array $options = [])
    {
        $options['parent'] = $parent;
        $options['siteId'] = ArrayHelper::getValue($options, 'siteId', $parent->cms_site_id);
        $options['createdBy'] = ArrayHelper::getValue($options, 'createdBy', $parent->created_by);
        $options['correlationId'] = ArrayHelper::getValue($options, 'correlationId', $parent->correlation_id);

        return $this->push($type, $payload, $options);
    }

    /**
     * Запросить отмену.
     *
     * Отмена кооперативная: обработчик остановится на ближайшей проверке.
     * Статус здесь не меняется — задание всё ещё выполняется.
     *
     * @return bool
     */
    public function cancel(CmsJobRun $run, $byUserId = null)
    {
        if ($run->getIsFinished()) {
            return false;
        }

        $definition = $this->registry->has($run->job_type) ? $this->registry->get($run->job_type) : null;
        if ($definition && !$definition->cancellable) {
            return false;
        }

        $now = time();

        if ($run->status === CmsJobRun::STATUS_QUEUED) {
            // Ещё не начиналось — можно завершить сразу.
            $affected = CmsJobRun::updateAll(
                [
                    'status' => CmsJobRun::STATUS_CANCELLED,
                    'cancel_requested_at' => $now,
                    'cancel_requested_by' => $byUserId,
                    'finished_at' => $now,
                    'dedup_active' => null,
                    'updated_at' => $now,
                ],
                ['id' => $run->id, 'status' => CmsJobRun::STATUS_QUEUED]
            );

            if ($affected === 1) {
                $run->refresh();

                return true;
            }
        }

        CmsJobRun::updateAll(
            [
                'cancel_requested_at' => $now,
                'cancel_requested_by' => $byUserId,
                'updated_at' => $now,
            ],
            ['id' => $run->id]
        );

        $run->refresh();

        return true;
    }

    /**
     * Ручной перезапуск — новая запись со ссылкой на исходную.
     *
     * Автоматический повтор живёт в той же строке через attempt; смешивать их
     * нельзя, иначе история попыток и история перезапусков перестают
     * различаться.
     *
     * @return CmsJobRun|null
     */
    public function retry(CmsJobRun $run)
    {
        $new = $this->push($run->job_type, $run->getPayload(), [
            'title' => $run->title,
            'siteId' => $run->cms_site_id,
            'priority' => $run->priority,
            'correlationId' => $run->correlation_id,
            'triggerType' => CmsJobRun::TRIGGER_MANUAL,
            'createdBy' => $this->currentUserId(),
        ]);

        if ($new) {
            $new->updateAttributes(['retry_of_id' => $run->id]);
        }

        return $new;
    }

    /**
     * Политика пересечения.
     *
     * Взаимное исключение строится вокруг данных, а не вокруг строки
     * расписания: полное и инкрементальное обновление одного поставщика —
     * разные задания, но один ресурс.
     *
     * @return bool можно ли ставить задание
     */
    protected function applyOverlapPolicy(CmsJobRun $run)
    {
        if (!$run->dedup_key) {
            return true;
        }

        $active = CmsJobRun::find()
            ->andWhere(['dedup_active' => $run->dedup_key])
            ->one();

        if (!$active) {
            $run->dedup_active = $run->dedup_key;

            return true;
        }

        switch ($run->overlap_policy) {
            case CmsJobRun::OVERLAP_SKIP:
                // Отброшенная постановка фиксируется, иначе останется загадкой,
                // почему задание «не работало» неделю.
                CmsJobRun::updateAllCounters(['skipped_runs' => 1], ['id' => $active->id]);

                return false;

            case CmsJobRun::OVERLAP_COALESCE:
                $pending = CmsJobRun::find()
                    ->andWhere(['dedup_key' => $run->dedup_key])
                    ->andWhere(['status' => CmsJobRun::STATUS_QUEUED])
                    ->andWhere(['not', ['id' => $active->id]])
                    ->exists();

                if ($pending) {
                    CmsJobRun::updateAllCounters(['skipped_runs' => 1], ['id' => $active->id]);

                    return false;
                }

                // dedup_active занят работающим заданием, поэтому ожидающее
                // ставится без него.
                return true;

            case CmsJobRun::OVERLAP_REPLACE:
                $this->cancel($active, $run->created_by);

                return true;

            case CmsJobRun::OVERLAP_QUEUE:
            default:
                // Честная очередь: dedup_active занят, сериализацию обеспечит
                // resource_key.
                return true;
        }
    }

    /**
     * @return string|null
     */
    protected function resolveKey($explicit, $callback, array $payload, CmsJobRun $run)
    {
        if ($explicit !== null) {
            return $explicit;
        }

        if ($callback && is_callable($callback)) {
            return call_user_func($callback, $payload, $run);
        }

        return null;
    }

    /**
     * @return int|null
     */
    protected function currentSiteId()
    {
        $skeeks = \Yii::$app->get('skeeks', false);

        return $skeeks && $skeeks->site ? $skeeks->site->id : null;
    }

    /**
     * @return int|null
     */
    protected function currentUserId()
    {
        if (\Yii::$app instanceof \yii\console\Application) {
            return null;
        }

        $user = \Yii::$app->get('user', false);

        return $user && !$user->isGuest ? $user->id : null;
    }
}
