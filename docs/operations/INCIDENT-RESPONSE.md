# Metrik POS — Incident response runbook

## Severity and initial response

- **SEV-1:** sales unavailable across an organization/branch, suspected cross-tenant exposure, data corruption/loss, duplicate financial posting, or no usable backup during a database incident. Assign an incident lead, stop risky changes, preserve logs, and notify the business owner.
- **SEV-2:** one key function unavailable, queue/backup failures that do not yet block checkout, or severe degradation. Assign an owner and keep a timestamped incident record.
- **SEV-3:** isolated device/UI problem with a safe workaround and no financial/data-integrity risk.

Record start time in the organization's timezone and UTC, affected release commit, environment, organization/branch identifiers (avoid personal/customer information), symptoms, recent deployment/configuration changes, actions, and outcome.

## First 10 minutes

1. Confirm scope from the user-facing symptom and the server's 5xx/latency metrics; do not rely on a single browser report.
2. Capture `git rev-parse HEAD`, release timestamp, `php artisan metrik:health-check --strict`, `php artisan metrik:operations-check --json`, and relevant redacted log excerpts.
3. Check `storage/logs/metrik-operations-check.log`, `storage/logs/metrik-backup.log`, queue worker/scheduler process state, disk free space, and latest backup verification.
4. For suspected data integrity or tenant isolation failure, restrict affected actions and escalate immediately; do not continue making test sales in production.
5. Do not expose `.env`, passwords, access tokens, full job payloads, or unredacted customer records in tickets or chat.

## Issue-specific response

### Queue backlog or failed jobs

- Confirm the worker is running under the intended service account and correct release directory.
- Inspect failed-job class/name and sanitized exception in the protected log/database.
- Retry a failed job only after checking idempotency and whether any side effect may already have happened. For exports/notifications this is often safe only if the job is designed for it; for financial operations, verify records first.

### Backup failure or low disk space

- Do not delete the newest verified backup to make room. Inspect disk usage and temporary files, check the backup destination's permissions, and correct the cause.
- Create a fresh backup and run `php artisan metrik:backup-check` before any schema/data recovery operation.
- Do not restore over the production database as a diagnostic test. Restore to a separate isolated environment first and compare core record counts/totals.

### Offline/POS conflicts

- Preserve browser-local pending entries; do not clear site data or edit IndexedDB to hide a conflict.
- Record local operation UUID and the corresponding central sale, if any. Reconcile cash/stock and resolve `needs_review` records before closing the shift.
- Never submit the same operation UUID with altered contents in an attempt to force sync.

### Suspected tenant isolation/security incident

- Restrict the affected route/role or place the application in an approved maintenance/read-only posture if required.
- Preserve access logs and relevant audit records; do not edit them.
- Rotate affected secrets only through an approved procedure, assess access scope, and document notifications/compliance decisions with the accountable owner.

## Recovery criteria

Return to normal service only after the affected flow passes a targeted test, the global health/operations checks pass, financial/inventory totals reconcile for the affected window, and a named owner approves resumption. Track follow-up actions and add a regression test for every confirmed software defect.
