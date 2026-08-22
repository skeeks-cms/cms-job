<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 * @author Semenov Alexander <semenov@skeeks.com>
 */

namespace skeeks\cms\job\handlers;

use skeeks\cms\job\contracts\JobReporterInterface;
use skeeks\cms\job\exceptions\JobCancelledException;
use skeeks\cms\job\runtime\JobContext;

/**
 * Обработчик, идущий по данным порциями с сохраняемым курсором.
 *
 * Заменяет рекурсию вида `$this->actionUpdateProducts($page + 1)`: состояние
 * живёт в базе, а не в кадрах стека и приватных полях контроллера, поэтому
 * прогон переживает падение процесса и виден снаружи.
 *
 * Порция — единица не только прогресса, но и снижения нагрузки: справочники
 * подгружаются на всю порцию, а транзакция открывается одна на порцию, а не на
 * элемент. Само по себе замедление уменьшает только пик, но не объём работы.
 *
 * Наследнику остаётся описать загрузку порции, работу над ней и переход
 * курсора; цикл, троттлинг, окно выполнения, отмену и самоперепостановку
 * берёт на себя ядро.
 */
abstract class ChunkedJobHandler extends AbstractJobHandler
{
    /**
     * @var int Сколько секунд работать в одном процессе, прежде чем сохранить
     *          курсор и продолжить заново. Перезапуск с чистой памятью дешевле
     *          борьбы с ростом identity map на десятках тысяч записей.
     */
    public $maxRunSeconds = 300;

    /**
     * @var float Доля memory_limit, после которой процесс перезапускается.
     */
    public $memoryLimitRatio = 0.75;

    /**
     * Загрузить очередную порцию.
     *
     * @return array пустой массив означает, что данные закончились
     */
    abstract protected function loadChunk(JobContext $context, array $cursor);

    /**
     * Обработать порцию.
     */
    abstract protected function processChunk(array $items, JobContext $context, JobReporterInterface $reporter);

    /**
     * Курсор для следующей порции или null, если это была последняя.
     *
     * @return array|null
     */
    abstract protected function nextCursor(array $cursor, array $items, JobContext $context);

    /**
     * Необязательный завершающий этап: пересчёты, публикация, очистка.
     *
     * Выполняется один раз после последней порции.
     */
    protected function finish(JobContext $context, JobReporterInterface $reporter)
    {
    }

    /**
     * @inheritdoc
     */
    final public function run(JobContext $context, JobReporterInterface $reporter): void
    {
        $definition = $context->getDefinition();
        $startedAt = microtime(true);

        while (true) {
            if ($reporter->isCancelled()) {
                throw new JobCancelledException('Операция остановлена по запросу пользователя.');
            }

            if (!$definition->isWindowOpen()) {
                $context->requestRequeue(max(60, $definition->nextWindowOpening() - time()));
            }

            if ($this->shouldRestart($startedAt)) {
                $context->requestRequeue(0);
            }

            $cursor = $context->getCursor();
            $chunkStartedAt = microtime(true);

            $items = $this->loadChunk($context, $cursor);
            if (!$items) {
                break;
            }

            $this->processChunk($items, $context, $reporter);

            $next = $this->nextCursor($cursor, $items, $context);
            if ($next === null) {
                $reporter->heartbeat();
                break;
            }

            $context->setCursor($next);
            $reporter->heartbeat();

            $this->throttle($definition, microtime(true) - $chunkStartedAt);
        }

        $this->finish($context, $reporter);
    }

    /**
     * Пора ли сохранить курсор и продолжить в новом процессе.
     *
     * @return bool
     */
    protected function shouldRestart($startedAt)
    {
        if ($this->maxRunSeconds > 0 && (microtime(true) - $startedAt) > $this->maxRunSeconds) {
            return true;
        }

        $limit = $this->memoryLimitBytes();
        if ($limit > 0 && memory_get_usage(true) > $limit * $this->memoryLimitRatio) {
            return true;
        }

        return false;
    }

    /**
     * Пауза между порциями.
     *
     * dutyCycle измеряет фактическую длительность порции и спит
     * пропорционально, поэтому подстраивается под конкретное железо без
     * знания его абсолютной ёмкости.
     */
    protected function throttle($definition, $chunkSeconds)
    {
        $sleepMicroseconds = 0;

        if ($definition->chunkDelayMs > 0) {
            $sleepMicroseconds += $definition->chunkDelayMs * 1000;
        }

        if ($definition->dutyCycle !== null && $definition->dutyCycle > 0 && $definition->dutyCycle < 1) {
            $target = $chunkSeconds * (1 - $definition->dutyCycle) / $definition->dutyCycle;
            $sleepMicroseconds += (int)round($target * 1000000);
        }

        if ($sleepMicroseconds > 0) {
            usleep((int)min($sleepMicroseconds, 60 * 1000000));
        }
    }

    /**
     * @return int байт, 0 — без ограничения
     */
    protected function memoryLimitBytes()
    {
        $value = trim((string)ini_get('memory_limit'));

        if ($value === '' || $value === '-1') {
            return 0;
        }

        $unit = strtolower(substr($value, -1));
        $number = (int)$value;

        switch ($unit) {
            case 'g':
                return $number * 1024 * 1024 * 1024;
            case 'm':
                return $number * 1024 * 1024;
            case 'k':
                return $number * 1024;
            default:
                return $number;
        }
    }
}
