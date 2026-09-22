# Changelog
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
