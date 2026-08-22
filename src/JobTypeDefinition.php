<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 * @author Semenov Alexander <semenov@skeeks.com>
 */

namespace skeeks\cms\job;

use skeeks\cms\job\contracts\JobHandlerInterface;
use skeeks\cms\job\models\CmsJobRun;
use yii\base\BaseObject;
use yii\base\InvalidConfigException;

/**
 * Описание зарегистрированного типа задания.
 *
 * Тип — единственный источник правды о полосе, повторах, блокировках и сроке
 * хранения. Эти значения копируются в строку прогона для индексов и истории,
 * но не редактируются: иначе они разойдутся с определением типа.
 */
class JobTypeDefinition extends BaseObject
{
    /**
     * @var string Стабильный ключ, например `hosting.dns.create-zone`.
     */
    public $type;

    /**
     * @var string|array Класс обработчика или конфигурация для Yii::createObject().
     */
    public $handler;

    /**
     * @var string Человекочитаемое название операции.
     */
    public $title = '';

    /**
     * @var string Полоса очереди.
     */
    public $queue = CmsJobRun::QUEUE_DEFAULT;

    /**
     * @var int Версия контракта payload. Несовместимые изменения оформляются
     *          новым типом (`...v2`), а не миграцией payload.
     */
    public $version = 1;

    /**
     * @var string visible — операция видна в интерфейсе и хранится;
     *             transient — техническое сообщение, запись удаляется при успехе.
     */
    public $visibility = CmsJobRun::VISIBILITY_VISIBLE;

    /**
     * @var int Максимум попыток, включая первую.
     */
    public $maxAttempts = 1;

    /**
     * @var bool Безопасно ли повторить задание, про которое неизвестно,
     *           успело ли оно выполнить внешнее действие.
     *
     * Определяет поведение при истёкшей аренде и при неизвестном исключении.
     * По умолчанию false: не повторяем то, про что не знаем.
     */
    public $idempotent = false;

    /**
     * @var int Аренда воркера, сек. Продлевается при каждом сохранении прогресса.
     */
    public $leaseSeconds = 120;

    /**
     * @var int Предельная длительность одной попытки, сек.
     */
    public $timeout = 3600;

    /**
     * @var string skip|coalesce|queue|replace
     */
    public $overlapPolicy = CmsJobRun::OVERLAP_QUEUE;

    /**
     * @var bool
     */
    public $cancellable = true;

    /**
     * @var int Пауза между порциями, мс.
     */
    public $chunkDelayMs = 0;

    /**
     * @var float|null Доля времени под работой, 0..1. Воркер измеряет
     *                 длительность порции и спит пропорционально, поэтому
     *                 подстраивается под железо без знания его ёмкости.
     */
    public $dutyCycle;

    /**
     * @var array|null Окно выполнения, например ['02:00', '07:00'].
     */
    public $allowedWindow;

    /**
     * @var string|null Право, необходимое для запуска и просмотра.
     */
    public $permission;

    /**
     * @var int Сколько дней хранить завершённый прогон.
     */
    public $retentionDays = 30;

    /**
     * @var int Потолок записей в ленте событий одного прогона.
     */
    public $maxEvents = 500;

    /**
     * @var callable|null Вычисляет ключ ресурса по payload.
     *                    function (array $payload, CmsJobRun $run): ?string
     */
    public $resourceKey;

    /**
     * @var callable|null Вычисляет ключ дедупликации по payload.
     */
    public $dedupKey;

    public function init()
    {
        parent::init();

        if (!$this->type) {
            throw new InvalidConfigException('JobTypeDefinition::$type is required.');
        }
        if (!$this->handler) {
            throw new InvalidConfigException("JobTypeDefinition::\$handler is required for '{$this->type}'.");
        }
        if (!$this->title) {
            $this->title = $this->type;
        }
        if ($this->maxAttempts < 1) {
            $this->maxAttempts = 1;
        }
    }

    /**
     * @throws InvalidConfigException
     */
    public function createHandler(): JobHandlerInterface
    {
        $handler = \Yii::createObject($this->handler);

        if (!$handler instanceof JobHandlerInterface) {
            throw new InvalidConfigException(
                "Handler for job type '{$this->type}' must implement JobHandlerInterface."
            );
        }

        return $handler;
    }

    /**
     * Разрешено ли начинать работу прямо сейчас.
     */
    public function isWindowOpen(?int $time = null): bool
    {
        if (!$this->allowedWindow) {
            return true;
        }

        $time = $time ?? time();
        $minutes = (int)date('G', $time) * 60 + (int)date('i', $time);

        $from = $this->parseWindowPoint($this->allowedWindow[0] ?? '00:00');
        $to = $this->parseWindowPoint($this->allowedWindow[1] ?? '23:59');

        if ($from <= $to) {
            return $minutes >= $from && $minutes <= $to;
        }

        // Окно через полночь, например с 22:00 до 06:00.
        return $minutes >= $from || $minutes <= $to;
    }

    /**
     * Ближайший момент открытия окна.
     */
    public function nextWindowOpening(?int $time = null): int
    {
        $time = $time ?? time();

        if ($this->isWindowOpen($time)) {
            return $time;
        }

        $from = $this->parseWindowPoint($this->allowedWindow[0] ?? '00:00');
        $today = mktime(0, 0, 0, (int)date('n', $time), (int)date('j', $time), (int)date('Y', $time)) + $from * 60;

        return $today > $time ? $today : $today + 86400;
    }

    protected function parseWindowPoint(string $value): int
    {
        $parts = explode(':', $value);

        return ((int)($parts[0] ?? 0)) * 60 + ((int)($parts[1] ?? 0));
    }
}
