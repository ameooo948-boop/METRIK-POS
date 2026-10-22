# Stage 43 — Production operations and rollback acceptance

## Automated acceptance

- [ ] `php artisan metrik:operations-check --strict --json` exits 0 and emits `status: ok`.
- [ ] `php artisan metrik:health-check --strict` exits 0.
- [ ] `php artisan metrik:backup-check` verifies the most recent archive.
- [ ] `composer check-platform-reqs --no-dev` passes on the actual deployment PHP runtime.
- [ ] `php artisan test` and `npm run build` pass on a clean release checkout.
- [ ] `scripts/verify-metrik-operations.ps1` produces a retained summary log.
- [ ] `scripts/plan-metrik-rollback.ps1` generates a plan for a known-good tag and refuses to run with a dirty working tree.

## Scheduled operations

- [ ] Host Task Scheduler/Cron runs `php artisan schedule:run` every minute.
- [ ] `storage/logs/metrik-operations-check.log` receives new output at least every five minutes.
- [ ] Backup and operations logs are accessible only to authorized operators.
- [ ] External monitoring checks the command/health exit codes and web-server 5xx/latency metrics; alert deduplication and an escalation contact are configured.
- [ ] A test alert is delivered to the responsible operator and an alert-delivery failure has a fallback.
- [ ] Queue worker restart/recovery and failed-job triage have been rehearsed.

## Backup and recovery drill

- [ ] Produce a fresh encrypted/off-host backup using the approved production procedure.
- [ ] Verify the copied archive's integrity.
- [ ] Restore into a separate isolated environment, never over the live database during the drill.
- [ ] Compare selected counts and totals for sales, returns, shifts, cash movements, stock balances/movements, and customer/supplier ledgers.
- [ ] Record measured RPO/RTO and the result in the release ticket.

## Rollback drill

- [ ] Identify a known-good tag/commit and the migration files that differ from it.
- [ ] Generate and review a rollback plan; verify it makes no changes by itself.
- [ ] Complete a staging code rollback following the runbook.
- [ ] Confirm the database schema is compatible; no unreviewed `migrate:rollback` or restore is used.
- [ ] Run health, operations, targeted smoke, tests/build, and financial reconciliation after the drill.

## Manual/owner sign-off

- [ ] Incident owner and support contact are named.
- [ ] Current release commit, latest verified backup, and recovery runbook are linked in the internal release record.
- [ ] Any remaining warnings have explicit owner, mitigation, and due date.

A stage is not accepted only because the command exists; the scheduler, external alert delivery, isolated restore, and staging rollback must be exercised in the target deployment environment.
