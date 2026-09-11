# Changelog
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
