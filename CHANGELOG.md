# Changelog

## 1.0.1 — 2026-09-08

- Store error-report CSV artifacts in private job runtime instead of CMS file storage.
- Preserve complete CSV reports and use unique filenames for continuation fragments.
- Protect report downloads with site and permission checks, attachment headers and expiry.
- Include error reports in diagnostic retention, orphan cleanup and history cleanup.
- Preserve existing CMS-storage artifacts; no migration or automatic deletion of old files.
- Verified PHP syntax and 70 isolated release checks on MariaDB.

## 1.0.0 — 2026-09-08

- Initial release: background jobs, queue workers and backend operations.
