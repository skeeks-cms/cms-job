<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 * @author Semenov Alexander <semenov@skeeks.com>
 */

namespace skeeks\cms\job\contracts;

use skeeks\cms\job\models\CmsJobRun;
use yii\web\View;

/**
 * Предметный отчёт по прогону конкретного типа задания.
 *
 * Регистрируется в определении типа ({@see \skeeks\cms\job\JobTypeDefinition::$report}),
 * а не подменой контроллера `cmsJob/admin-cms-job-run`: у каждого типа свой
 * ключ в реестре, поэтому отчёты нескольких пакетов уживаются в одном
 * приложении. Стандартная карточка, действия отмены и повтора, логи и проверки
 * доступа остаются за контроллером cms-job; отчёт выводится над ними.
 */
interface JobRunReportInterface
{
    /**
     * Можно ли показать отчёт текущему пользователю. Иначе выводится только
     * стандартная карточка. Доступ к самой карточке проверяет контроллер.
     */
    public function canView(CmsJobRun $run): bool;

    /**
     * HTML отчёта над стандартной карточкой.
     *
     * @param array $context `detailsUrl` — адрес опроса подробностей; ответ
     *                       содержит их в `data[<id>].report`.
     */
    public function render(CmsJobRun $run, View $view, array $context): string;

    /**
     * Подробности для опроса прогресса с `details=1`. Должны быть ограничены
     * по объёму: страница опрашивает их каждые несколько секунд.
     */
    public function details(CmsJobRun $run): array;
}
