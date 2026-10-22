# Metrik POS — Backup and Restore Runbook

This is the primary runbook for the Laravel backup commands. The PowerShell backup script remains available for operators who explicitly need a SQL/SQLite command-line dump, but do not schedule both mechanisms without a written storage/retention policy.

## What is backed up

`php artisan metrik:backup` creates a timestamped ZIP containing:

- A consistent SQLite snapshot made with `VACUUM INTO`, or a MySQL logical dump streamed to disk by `mysqldump`.
- The configured project-relative file paths from `METRIK_BACKUP_PATHS` (defaults include `storage/app/public` and `storage/app/private/report-exports`).
- `manifest.json` with format version, creation time, database artifact, included paths, and SHA-256 hashes for every payload file.

The command validates the completed ZIP and checksums before publishing it under its final name. Files are written with restrictive permissions where the operating system supports them. The destination must be outside the public web root and must not overlap any path being backed up.

## Configuration

```dotenv
# Empty/unset uses storage/app/private/backups. Prefer an external protected volume in production.
METRIK_BACKUP_DIRECTORY=
METRIK_BACKUP_KEEP=14
METRIK_BACKUP_PATHS=storage/app/public,storage/app/private/report-exports
METRIK_MYSQLDUMP_BINARY=mysqldump
METRIK_BACKUP_DUMP_TIMEOUT=300
METRIK_BACKUP_SCHEDULE=02:15
METRIK_BACKUP_MAX_AGE_HOURS=26
# Optional: a real recipient and working mail transport are required for email alerts.
# METRIK_BACKUP_ALERT_EMAIL=ops@example.com
```

Do not copy the sample alert address into production unless it is a real mailbox. Keep the actual `.env` out of Git and web-accessible directories. For MySQL, ensure `mysqldump`/`mariadb-dump` is installed and accessible to the PHP process. The command checks `PATH` and common Windows locations (including standard XAMPP installations). If PHP still cannot find it, set `METRIK_MYSQLDUMP_BINARY` to the executable's full path in `.env`, for example `METRIK_MYSQLDUMP_BINARY=C:/xampp/mysql/bin/mysqldump.exe`, then run `php artisan config:clear` (or rebuild the config cache). Do not put the executable path in the database password or other DB settings. Both MySQL and SQLite require PHP's `zip` extension for archive creation and verification. SQLite backups also require file-backed SQLite and the PDO SQLite extension. Do not point a backup path at the backup destination itself.

## Manual operations

Create and verify a fresh backup:

```bash
php artisan metrik:backup
php artisan metrik:backup-check
```

Verify a chosen archive without extracting it:

```bash
php artisan metrik:restore "storage/app/private/backups/metrik-backup_YYYYMMDD_HHMMSS.zip" --verify-only
```

Extract and validate a restore payload in a new isolated directory:

```bash
php artisan metrik:restore "storage/app/private/backups/metrik-backup_YYYYMMDD_HHMMSS.zip" --destination="storage/app/private/restore-drills/drill-YYYYMMDD"
```

The restore command never replaces the configured/live database and will not extract into the public web root. For a SQLite backup, it runs `PRAGMA integrity_check` on the extracted database. For a MySQL backup, it validates the archived SQL file's checksum; import that dump only into a separate staging database using the MySQL client, then compare records and exercise workflows. Never use a restore drill as a live-production overwrite.

## Scheduling and monitoring

Laravel Scheduler contains a daily backup (default 02:15) and an hourly check. The check fails if no archive exists, the latest archive is older than `METRIK_BACKUP_MAX_AGE_HOURS`, the ZIP is corrupt, or payload checksums do not match. Inspect:

- `storage/logs/metrik-backup.log`
- `storage/logs/metrik-backup-check.log`
- Laravel application logs for `Metrik backup failed` / `Metrik backup check failed`

The server must run the scheduler every minute. On Windows, use Task Scheduler to run `php artisan schedule:run` from the project directory every minute. On Linux, use the documented Laravel cron entry. Configure a real `METRIK_BACKUP_ALERT_EMAIL` and a working mail transport for email alerts; otherwise use an external log/monitoring service to alert on non-zero exit codes and stale backups.

Example Windows task command (adjust PHP path and project path):

```powershell
C:\path\to\php.exe C:\laravel\metrik-pos\artisan schedule:run
```

Do not launch two backup implementations against the same directory unless they share a retention and naming policy.

## Retention, off-site storage, and recovery objectives

- Default retention is 14 archives; tune it to data change rate, disk capacity, and business requirements.
- Keep at least one encrypted copy on a separate system/account/volume. The application command does not itself encrypt archives or upload them off-site.
- Backups contain sensitive customer/business records. Restrict directory access and encrypt at rest and in transit when moving them.
- Define a target Recovery Point Objective (RPO) and Recovery Time Objective (RTO); daily backup implies that the possible data-loss window can approach a day.
- Monitor available disk space and alert if backup storage is nearing capacity.

## Restore-drill procedure

1. Record the archive name, size, last-write time, and SHA-256 checksum.
2. Run `metrik:restore ... --verify-only`.
3. Extract with `--destination` to a new isolated location.
4. For SQLite, confirm the command reports a successful `PRAGMA integrity_check`, then point a staging copy of the app at that file.
5. For MySQL, import `database.sql` into an empty staging database, not production. Keep the import credentials out of the shell history where possible.
6. Verify product count, latest sale and purchase invoice, inventory balance/movement consistency, cash-shift totals, customer/supplier balances, user login, organization isolation, and required stored files.
7. Execute representative workflows in staging and document duration (RTO) and the timestamp of the newest recovered transaction (RPO evidence).
8. Remove the staging data securely and record pass/fail, operator, date, archive identifier, and corrective actions.

Perform a restore drill at least monthly and after changing the backup format, storage provider, or database configuration.

## Safety

- Never use `migrate:fresh` on a database with business data.
- Never experiment with restoring directly over production.
- Restore files/database to an isolated destination first. The command deliberately does not change Laravel's configured database or execute SQL dumps.
- A ZIP's existence is not proof of recoverability: archive integrity, checksums, database integrity, and an application-level restore drill are all required.
