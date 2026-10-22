# Metrik POS — Production monitoring

## Health signals

Run `php artisan metrik:operations-check --strict --json` from the deployment checkout. The command exits non-zero when a check fails or when strict mode sees a warning. It reports application configuration, database/schema reachability, database queue backlog/age, failed jobs, latest backup age/integrity, free space on the volume containing `storage`, and required writable paths. It deliberately does not return credentials, connection strings, job payloads, or exception text in the JSON report.

For interactive diagnostics, omit `--json`. For the existing dependency/readiness probe, also run `php artisan metrik:health-check --strict`; for backup-specific validation, run `php artisan metrik:backup-check`.

## Scheduled probe

`routes/console.php` schedules a strict JSON probe every five minutes and appends output to `storage/logs/metrik-operations-check.log`. The host must run Laravel's scheduler every minute:

- Windows Task Scheduler: run `php artisan schedule:run` from the application root every minute, using the intended PHP executable and service account.
- Linux: add a cron entry for `cd /path/to/metrik-pos && php artisan schedule:run >> /dev/null 2>&1` every minute.

Confirm a fresh line appears in the operations log after enabling the scheduler. A configured schedule alone is not evidence the scheduler is running. Monitor exit codes/logs from outside the application (Windows Event Forwarding, a process monitor, or a central log/alerting platform) and configure deduplication/rate limits there. Do not email on every five-minute check without alert suppression.

## Thresholds

Environment variables are optional and shown in `.env.example`:

- `METRIK_DISK_FREE_WARNING_MB` — default 2048 MB.
- `METRIK_DISK_FREE_CRITICAL_MB` — default 512 MB.
- `METRIK_QUEUE_MAX_PENDING` — default 500 jobs for the database queue.
- `METRIK_QUEUE_MAX_AGE_MINUTES` — default 15 minutes for unreserved database jobs.

Choose thresholds based on the host and volume size. Redis queue backlog depth is not inferred by this generic check; monitor Redis queue length through the selected queue worker/monitoring integration. `failed_jobs` records are reported as warnings so strict monitoring can alert; investigate and retry only after understanding idempotency and side effects.

## Required external alerts

Create deduplicated alerts for: operations check failure; a backup missing/stale/corrupt; any failed queue job; a growing old-job backlog; low disk space; elevated HTTP 5xx and latency from the web server/monitor; queue worker/scheduler process stopped; mail delivery failure; and failure to deliver the alert itself. Test each alert in a staging environment. An exit code and a log line are telemetry sources, not a delivery guarantee.

## Data protection

Keep operations logs and snapshots private; do not publish `storage` or expose this command as an unauthenticated HTTP endpoint. Retain only the log detail required for investigation, protect access, and redact customer data from incident tickets. For database/backup failures, preserve logs and verify the latest backup before destructive maintenance.
