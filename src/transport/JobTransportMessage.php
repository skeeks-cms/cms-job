<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 * @author Semenov Alexander <semenov@skeeks.com>
 */

namespace skeeks\cms\job\transport;

/**
 * Транспортное сообщение.
 *
 * Через очередь передаётся минимум: идентификатор запуска и версия формата.
 * Payload, настройки, модели и тем более сериализованные обработчики остаются
 * в `cms_job_run`. Это единственный способ пережить обновление кода, пока
 * сообщения лежат в очереди: старое сообщение всегда означает одно и то же —
 * «выполни запуск с таким номером», а что именно выполнять, решает актуальный
 * код по актуальной строке.
 */
final class JobTransportMessage
{
    /**
     * Текущая версия формата.
     *
     * При несовместимом изменении формата версию увеличить, а старые
     * транспортные очереди осушить до выката: потребитель откажется
     * обрабатывать сообщение неизвестной версии.
     */
    const VERSION = 1;

    /**
     * @var int
     */
    public $runId;

    /**
     * @var int
     */
    public $version;

    public function __construct(int $runId, int $version = self::VERSION)
    {
        $this->runId = $runId;
        $this->version = $version;
    }

    public function isSupported(): bool
    {
        return $this->version === self::VERSION;
    }
}
