# Metrik POS — Release Candidate Checklist

**Purpose:** A repeatable go/no-go review before inviting real supermarket customers. Passing automated commands is not the same as proving production readiness.

## A. Build and repository hygiene

- [ ] `php artisan test` passes on the target branch.
- [ ] `npm run build` passes.
- [ ] `composer check-platform-reqs --no-dev` passes on the deployment PHP runtime.
- [ ] `php artisan metrik:health-check --strict` passes in a production-like environment.
- [ ] `php artisan metrik:backup` completes and `php artisan metrik:backup-check` verifies a fresh archive.
- [ ] `git diff --check` passes and `git status --short` is understood.
- [ ] No `.env`, credentials, private exports, customer data, or database dumps are tracked.
- [ ] All intended work is committed; the release commit hash is recorded.
- [ ] Dependencies and PHP/Node versions are documented for the deployment environment.

## B. Tenant and authorization boundaries

Use two test organizations (A and B) with different users and branches.

- [ ] A user from A cannot view or mutate B's products, inventory, sales, sale returns, purchase invoices, supplier payments, expenses, reports, exports, notifications, or team members.
- [ ] Changing `organization_id`, `branch_id`, model IDs, UUIDs, or form fields in a request cannot bypass authorization.
- [ ] Cashier, inventory manager, accountant, branch manager, organization admin, and owner permissions match the agreed matrix.
- [ ] Removing a user's membership prevents further access to that organization without deleting their personal account or other memberships.
- [ ] Download endpoints verify ownership and authorization server-side.

## C. Sales and cash integrity

- [ ] A sale, its items, payments, inventory movements, and cash movement either commit together or roll back together.
- [ ] Replaying the same idempotency UUID does not create another sale or decrement inventory again.
- [ ] Reusing an idempotency UUID with different payload data is rejected.
- [ ] Insufficient stock is rejected by the server, including concurrent requests.
- [ ] A return cannot exceed the sold quantity less previous accepted returns.
- [ ] A return cannot restore inventory or issue a refund twice.
- [ ] Cash refunds require an eligible open shift and affect expected cash correctly.
- [ ] Non-cash refunds are not described as completed externally unless the payment provider confirms them.
- [ ] Shift closing reconciles opening cash, cash sales, cash expenses, cash refunds, and declared cash.

## D. Purchasing and inventory integrity

- [ ] Purchase receipt and inventory movements are atomic.
- [ ] Supplier payments cannot exceed the outstanding payable unless an explicit credit policy allows it.
- [ ] Purchase returns cannot exceed eligible received quantity or current available stock.
- [ ] Purchase return and supplier balance movements are atomic.
- [ ] Branch transfers (if enabled) have a clear sent/received state and cannot duplicate stock.
- [ ] Corrections use traceable compensating movements; approved ledger rows are not silently overwritten.
- [ ] Product units and conversion factors are validated; fractional quantities follow an explicit precision policy.

## E. Offline-first acceptance

Test on the actual supported browser/device, not only by mocking `navigator.onLine`.

- [ ] Install the PWA and load the POS once while online.
- [ ] Disconnect the network and reload the offline fallback.
- [ ] Create a cash sale using the locally available catalog and verify it remains in the outbox.
- [ ] Restore connectivity and verify the operation syncs once.
- [ ] Force a timeout after the server commits; retry and verify no duplicate sale or stock movement.
- [ ] Change a product price or exhaust stock on the server while the device is offline; confirm the conflict is surfaced for review.
- [ ] Verify pending operations survive a browser restart.
- [ ] Confirm the UI distinguishes local/pending sales from server-confirmed sales.
- [ ] Document device loss, browser storage clearing, and backup/recovery procedures. Browser storage alone is not a durable backup.

## F. Queues, notifications, and monitoring

- [ ] Queue driver and worker process are configured for the target environment.
- [ ] Failed jobs are observable and can be retried safely.
- [ ] Queue retries cannot duplicate exports, notifications, sales, or financial movements.
- [ ] Export files are private, expire or are cleaned up according to policy, and ownership is checked at download time.
- [ ] Logs include useful request context without passwords, tokens, payment secrets, or sensitive customer data.
- [ ] Alerting exists for failed jobs, repeated sync conflicts, database failures, disk space, and backup failures.

## G. Backup and restore

- [ ] A database backup is created outside the application web root.
- [ ] Backup access is restricted and encryption is enabled where appropriate.
- [ ] `php artisan metrik:restore <archive> --verify-only` succeeds; restore payload is extracted into an isolated directory and its hashes/database integrity are verified.
- [ ] A MySQL dump (if applicable) was imported and tested in a separate staging database; a SQLite restore was opened and smoke-tested in staging.
- [ ] Restored data is checked for organizations, products, stock balances/movements, sales, returns, supplier balances, and users.
- [ ] Recovery Point Objective (RPO) and Recovery Time Objective (RTO) are documented.
- [ ] A backup is not considered valid until a restore test succeeds.

## H. Production configuration

- [ ] `APP_ENV=production`, `APP_DEBUG=false`, and `APP_URL` is correct.
- [ ] HTTPS is enforced; secure cookies and trusted proxy settings are correct for the host.
- [ ] Secrets are provided through the deployment environment and not committed.
- [ ] Database least-privilege credentials are used.
- [ ] `storage` and `bootstrap/cache` are writable by the application runtime, while source/config files are not broadly writable.
- [ ] Scheduler and queue worker are supervised and restart after deployment/reboot.
- [ ] Rate limiting, login throttling, error pages, and security headers are tested.
- [ ] Legal/tax requirements, receipt formats, retention, and payment/refund provider behavior are reviewed for the intended Egyptian deployment.

## Go / No-Go rule

**No-Go** if any tenant isolation, authorization, sale idempotency, stock integrity, refund, queue duplication, backup restore, or offline conflict test is failing or has not been evaluated. Document known limitations and assign an owner before inviting pilot customers.
