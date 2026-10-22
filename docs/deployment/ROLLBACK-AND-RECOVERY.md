# Metrik POS — Release rollback and recovery

## Principles

- Rollback is an approved, reviewed operation. `scripts/plan-metrik-rollback.ps1 -TargetRef <tag-or-commit>` only writes a JSON review plan; it never changes Git state, code, migrations, or application data.
- Never run `git reset --hard`, `migrate:fresh`, or a database restore as an incident shortcut.
- A code rollback is safe only if the target release is compatible with the schema already deployed. Laravel migrations are not automatically reversed by switching code.
- Database restore has an explicit data-loss window. Obtain incident lead/business owner approval, record the last recoverable point, and preserve the pre-restore database for forensic comparison where lawful.

## Before each release

1. Ensure the working tree is clean and record the current known-good commit/tag.
2. Run `scripts/verify-metrik-release.ps1` and `scripts/verify-metrik-operations.ps1` (or the equivalent checks documented in the release ticket).
3. Create and verify a backup with `php artisan metrik:backup` then `php artisan metrik:backup-check`; copy it to the protected off-host destination and verify availability there.
4. Save the deployment commit, migration status, config-change list (not secrets), asset/build manifest, test log, and backup filename in the release ticket.
5. Identify migrations included in the release and decide in advance whether code-only rollback is schema-compatible. Prefer expand/migrate/contract: deploy backward-compatible schema first, deploy code, and remove old schema only in a later release.

## Prepare a rollback plan

From the project root and a clean checkout, run:

```powershell
.\scripts\plan-metrik-rollback.ps1 -TargetRef <known-good-tag-or-commit>
```

Review the JSON file under `storage/app/private/release-plans/`. It lists the current/target commits and migration files changed between them. The script intentionally refuses a dirty Git working tree and does not execute any rollback action.

## Approved rollback sequence

1. Incident lead confirms scope and authorizes maintenance/traffic reduction if needed.
2. Capture logs and current state; create and verify a fresh backup if the database is healthy enough. Do not overwrite the only recovery point.
3. Review migration compatibility in the generated plan. If schema changes are incompatible, stop: prepare a tested forward fix or a separately approved database restore plan. Do not improvise migration `down` commands in production.
4. Deploy the approved target code/artifact through the normal release mechanism, preserving `.env`, user uploads, backup archives, and private exports. Do not replace the live directory blindly from a ZIP.
5. Run health and operations checks; inspect queue worker/scheduler logs and run a targeted read-only smoke test before reopening traffic.
6. If database restoration is approved, restore only through the documented isolated recovery procedure with a recorded cutoff and explicit data-loss acknowledgement. Reconcile sales, returns, cash shifts, inventory movements, receivables/payables, and offline sync records after recovery.
7. Record actual commit, database action, downtime, RPO/RTO achieved, tests, approver, and follow-up regression tests.

## Recovery acceptance

- `php artisan metrik:health-check --strict` passes.
- `php artisan metrik:operations-check --strict --json` returns `status=ok` and exit code 0.
- `php artisan metrik:backup-check` verifies a current archive.
- `php artisan test` and `npm run build` pass on the restored release environment.
- Critical financial/inventory totals reconcile and no unresolved offline sales/shift conflicts remain.
