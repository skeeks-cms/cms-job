# Release verification — 2026-09-06

## 1.0.0 candidate — 2026-09-08

Repeated the disposable-DB fresh installation/log/cron suite (61 checks) and
all six regression suites (249 checks) successfully against local candidates.
Configuration-only queue listing (8) and cron facade/locking (14) checks pass.
This does not repeat clean dependency resolution or the full upgrade matrix;
the separate public cms-agent integration requirement below still applies.

## Follow-up — 2026-09-07

Core lane defaults reduced to `default` and `maintenance`. Consumer lanes now
come only from test fixture configuration. Rechecked: 246 regression checks,
23 fresh-schema checks with isolated workers, and 10 Composer configuration
checks including consumer merge and project overrides. This follow-up did not
repeat the full upgrade/prefix matrix below or publish a release.

Environment: Windows checkout, fast-profile PHP 8.2, disposable MariaDB 10.5
container without published ports or the site's database volume.

## Passed

- Clean Composer resolution and installation: 98 packages, cms-job candidate
  mirrored from the local path repository, other packages from public releases.
  Resolved CMS 6.4.9.25, cms-admin 3.1.2.4, yii2-queue 2.3.8.
- Generated Composer configuration: 6 checks (web/console registration,
  explicit migration path, public worker route, eight lanes, queue autoload).
- Schema matrix with clean dependencies: 152 checks. Fresh installation,
  legacy upgrade and foreign-table collision, each without a prefix and with
  `sx_`; real isolated workers on every lane, rollback and acknowledgements.
- Local development candidates: all 246 regression checks pass (core 43,
  fencing 45, requeue 47, transport 39, process recovery 39, console/scheduler 33).
- Public dependencies: all 213 core/fencing/requeue/transport/recovery checks
  pass. Console execution and allowlist checks also pass before the scheduler
  integration test reports the missing public bridge.

## Release gate still open

Public cms-agent 3.1.0.1 lacks `CmsAgentModel::pushJob()` and the job columns
migration. These changes are present only in the local development candidate.
Release and verify that package before claiming the scheduler integration is
deployable from published dependencies. No cms-job release was published by
this verification step.

## Scope

Tests create/drop only their own random database. They use minimal CMS FK
tables and a minimal scheduler table, not the whole CMS installation. Storage
upload, full cms-agent migrations, production deployment and end-to-end admin
authorization are not covered by this run. Earlier browser checks are not a
substitute for a final deployment smoke test.
