# Metrik POS — Stages 36–37: Backup and pre-launch readiness

## Stage 36 — Backup, retention, restore, and failure monitoring

### What the implementation provides
- `php artisan metrik:backup` creates a timestamped ZIP outside `public/` and refuses a destination inside the public web root or inside a configured input path.
- SQLite uses `VACUUM INTO` to create a consistent snapshot that includes committed WAL data; in-memory SQLite is rejected.
- MySQL dump output is streamed to disk with a process timeout instead of buffering the entire database dump in PHP memory.
- The archive manifest uses format v2 and contains SHA-256 checksums for each payload file. The finished ZIP is consistency-checked and every checksum is verified before the archive is published.
- Backup payload supports configured project-relative file paths. Defaults include `storage/app/public` and private report exports under `storage/app/private/report-exports`; the backup destination itself is not included.
- Retention keeps the newest `METRIK_BACKUP_KEEP` verified archives (default 14).
- `php artisan metrik:backup-check` fails when no backup exists, the newest archive is stale, ZIP integrity fails, or payload checksums do not match.
- `php artisan metrik:restore <archive> --verify-only` validates an archive without extracting it. Adding `--destination=<private-empty-path>` extracts the payload, rechecks file hashes and runs SQLite `PRAGMA integrity_check` when the archive contains a SQLite snapshot. It never overwrites the live database or imports a MySQL dump.
- Scheduler runs daily backup and hourly backup freshness/integrity check, logs failures, and optionally emails command output to `METRIK_BACKUP_ALERT_EMAIL` when configured with a valid address and working mail transport.

### Deployment requirements
1. Enable PHP `zip`. SQLite backups require `pdo_sqlite` and a file-backed DB. For MySQL, install `mysqldump` and make it available to the PHP/CLI user.
2. Configure `METRIK_BACKUP_DIRECTORY` to a protected location on a different disk/host when possible. Empty/unset uses `storage/app/private/backups`; restrict access to the application/service account.
3. Review `METRIK_BACKUP_PATHS` explicitly for every required uploaded/document directory. They must be project-relative and must not include the backup destination.
4. Set retention and max age to business needs. Configure a real `METRIK_BACKUP_ALERT_EMAIL` and a working mail transport, or configure external log monitoring; the application command does not upload or encrypt archives.
5. Run `php artisan config:cache` after setting production values.
6. Ensure the host invokes Laravel's scheduler every minute (`php artisan schedule:run` via cron/system scheduler). For multiple hosts, configure a shared lock-capable cache for `onOneServer()`.

### Restore drill (must be performed on staging, never over production)
1. Select a recent archive and run `php artisan metrik:restore "<archive.zip>" --verify-only`.
2. Extract using `php artisan metrik:restore "<archive.zip>" --destination="storage/app/private/restore-drills/<unique-id>"`.
3. For SQLite, ensure the command reports a successful `PRAGMA integrity_check`, then point a staging copy of the application at the extracted `database.sqlite`. For MySQL, import `database.sql` into a newly created empty staging database with the MySQL client; the application command deliberately does not execute SQL imports.
4. Restore included files to a clean staging copy of their configured paths; check ownership and permissions.
5. Run `php artisan migrate:status`, `php artisan metrik:health-check --strict`, `php artisan test`, and smoke-test login, organization isolation, product/stock operations, sale/return, payments, supplier settlement, reports, and offline sync reconciliation.
6. Record archive timestamp, duration, database/file checks, test outcome and the person completing the drill. Define recovery point/time (RPO/RTO) with the business owner.
7. Repeat at least monthly and after changing backup format, storage, or database configuration.

### Failure monitoring
- The hourly `metrik:backup-check` reports a missing/stale or corrupt latest archive with a non-zero exit code.
- Scheduled command output is appended to `storage/logs/metrik-backup.log` and `storage/logs/metrik-backup-check.log`.
- If a valid alert email is configured, scheduler failure output is emailed; otherwise forward Laravel logs and command exit codes to an external monitoring service.
- Monitor disk capacity separately. File existence is not proof of recoverability; periodic restore drills are mandatory.

## Stage 37 — Pre-launch review

### Automated release gate
Run from the project root in a clean staging/release checkout:

```powershell
.\scripts\verify-metrik-release.ps1
```

The script checks platform requirements, clears cached configuration, runs tests, builds Vite assets, runs the strict health check, checks Git whitespace/status and ensures `.env` is not tracked. A passing script is not a substitute for the manual acceptance gates below.

### Required manual acceptance gates
- **Business workflows:** organization setup/invitation; login/logout/password reset; product and variant CRUD; barcode/search; customer/supplier flows; purchase receiving and purchase returns; stock adjustments/counts/transfers; POS sale, discounts/taxes, payment/refund/void; cash movements; supplier payments/settlements; customer balances; reports/export; role management; audit trail.
- **Tenant isolation:** use two organizations with deliberately overlapping IDs/records. Verify list, detail, search, export, update, delete, nested resources, background jobs, API endpoints and file downloads never expose the other tenant's data. Verify every tenant-owned query is scoped server-side and tenant context cannot be overridden by request payloads. Include cross-tenant negative tests for each resource family.
- **Authorization:** test each role against permitted and forbidden actions, including direct URL/API requests. Confirm policies/middleware apply to exports, bulk actions, queued jobs and offline sync. Verify no secrets or sensitive payloads enter logs.
- **Concurrency/integrity:** simultaneous sales against low stock; duplicate/idempotent payment and sync requests; retry after timeout; duplicate webhook delivery; concurrent stock transfers and returns. Confirm transactions, idempotency and audit records remain consistent.
- **Offline synchronization:** disconnect network, create and edit supported records, reconnect, retry sync, simulate duplicate/out-of-order requests and server conflicts, expire authentication, and verify conflict resolution, idempotency and visible user feedback. Confirm unsupported offline operations are blocked clearly.
- **Performance/load:** define target concurrent users and peak transactions with the business owner. Run representative k6/JMeter scenarios against staging, not production. Capture p50/p95/p99 latency, throughput, error rate, DB query time, CPU/memory and queue lag. Check slow queries/indexes and ensure p95/error-rate objectives are met at expected peak plus safety margin.
- **Production security/config:** `APP_ENV=production`, `APP_DEBUG=false`, strong unique `APP_KEY`, HTTPS, secure cookies, correct trusted proxies/hosts, least-privilege DB user, secrets only in environment/secret manager, no `.env` in Git or public root, restricted storage/symlinks, rate limiting, CORS/session/CSRF review, dependency audit, error/log redaction, backup encryption/access control, and no demo/test credentials.
- **Operations:** queue workers and scheduler supervised; failed jobs monitored; log rotation/retention set; disk and DB capacity alerts configured; deployment rollback procedure tested; migrations reviewed for locks/downtime; health endpoint behavior understood; restore drill passed; support/contact and incident procedure documented.
- **Final sign-off:** attach test run, load report, tenant-isolation evidence, restore-drill record, unresolved-risk list and named business/technical approval. Do not launch with a failed critical workflow, tenant leak, unrecoverable backup, or known critical/high security issue.

## Environment notes
Add these optional values to the deployment environment (never commit secrets):

```dotenv
METRIK_BACKUP_DIRECTORY=/absolute/private/path/metrik-backups
METRIK_BACKUP_KEEP=14
METRIK_BACKUP_PATHS=storage/app/public,storage/app/private/report-exports
METRIK_MYSQLDUMP_BINARY=mysqldump
METRIK_BACKUP_DUMP_TIMEOUT=300
METRIK_BACKUP_SCHEDULE=02:15
METRIK_BACKUP_MAX_AGE_HOURS=26
# METRIK_BACKUP_ALERT_EMAIL=ops@example.com
```

The implementation does not encrypt archives itself and does not upload them off-site; use encrypted storage/volume or an external backup service and verify access controls. Restore extraction is intentionally isolated and will never overwrite the configured database.
