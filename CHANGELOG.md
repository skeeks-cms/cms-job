# Changelog
## 1.3.0 — 2026-09-28

- Управляемые временные папки через JobContext::getWorkspace(): один каталог на запуск, сохранение при продолжениях и автоматических повторах.
- Системная ежечасная очистка рабочих папок: успех — 7 суток, остальные завершённые статусы — 14 суток; активные, удержанные и неоднозначные папки сохраняются.
- Файловые блокировки, проверка владельца, карантин и возобновление прерванного удаления; dry-run и ручное удержание.
- Защита истории запусков, пока существуют рабочие файлы; отдельная защита явно указанных старых каталогов без их автоматического удаления.
- Руководство WORKSPACES.md для разработчиков и эксплуатации. Миграций БД нет; при переходе нужны согласованное обновление обработчиков и мягкий перезапуск воркеров.
- Проверки: 58 сценариев жизненного цикла рабочих папок и 40 проверок обслуживания; проектный адаптер дополнительно проверен 8 сценариями.

## 1.2.0 — 2026-09-28

- Системные расписания ежедневной очистки истории и ежечасной очистки логов/CSV.
- Регистрация через cmsAgent/init, ручной запуск и совместимость с cms-agent >= 3.2.5.
- Общая блокировка, порционная обработка с продолжениями, отмена и heartbeat.
- Сохранены ручные CLI-команды; рабочие файлы supplier-imports не удаляются.
- 33 изолированные проверки и успешные ручные/автоматические запуски на aney.ru.

## 1.1.0 — 2026-09-22

- Один диспетчер на сайт: до 10 дочерних заданий суммарно и одно на канал
  по умолчанию, с переопределением лимитов в `jobWorker`.
- Сохранены отдельные воркеры `--queue` и ограниченный режим Cron.
- Общий жизненный цикл дочерних процессов, TTR, обработка падений и мягкая
  остановка; нативное резервирование yii2-queue без второго механизма доставки.
- Метаданные диспетчера в `worker/queues --json` для управления из хостинга.

## 1.0.9 — 2026-09-22

- Воркеры освобождают подключения MySQL при пустой очереди и перед запуском
  изолированного дочернего процесса. Следующий запрос открывает соединение заново.
- Сохранены транзакционная постановка, резервирование и подтверждение доставки,
  активные транзакции и mutex. Поведение других драйверов БД не изменено.
- Для собственного состояния DB-сессии доступен releaseIdleConnection=false.
- Добавлен тест на приватном канале: 13 проверок прошли на PHP 8.2 / MariaDB 10.5.
- Миграций нет; после обновления необходимо перезапустить воркеры.

## 1.0.6 — 2026-09-11

- Display «Ожидает продолжения» for local chunked jobs explicitly reporting
  `_job_execution.state = awaiting_continuation` while queued between deliveries.
- Keep initial queueing, real execution and terminal statuses distinct.
- Preserve stored statuses, claiming, retries, cancellation and remote execution observations.
- No migrations. Consumers opt in through result metadata before requeueing.
- Verified 23 display-status checks and supplier-import integration fixtures.

## 1.0.1 — 2026-09-08

- Store error-report CSV artifacts in private job runtime instead of CMS file storage.
- Preserve complete CSV reports and use unique filenames for continuation fragments.
- Protect report downloads with site and permission checks, attachment headers and expiry.
- Include error reports in diagnostic retention, orphan cleanup and history cleanup.
- Preserve existing CMS-storage artifacts; no migration or automatic deletion of old files.
- Verified PHP syntax and 70 isolated release checks on MariaDB.

## 1.0.0 — 2026-09-08

- Initial release: background jobs, queue workers and backend operations.
