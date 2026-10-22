# Metrik POS — Production Readiness

This is an operational checklist, not a guarantee that the application is production-ready. Run it against a staging deployment first.

## Environment and secrets

- Keep `.env` outside ZIP patches and source control. Never share the file or print `APP_KEY`, database credentials, SMTP secrets, or payment-provider secrets in logs.
- Set `APP_ENV=production`, `APP_DEBUG=false`, and `APP_URL=https://your-real-domain.example` on the production host.
- Set `SESSION_SECURE_COOKIE=true` only after HTTPS and trusted proxy settings have been verified.
- Use a unique production `APP_KEY`. Do not regenerate an existing production key casually; rotating it can invalidate encrypted application data and sessions.
- Use least-privilege database credentials; restrict remote database and Redis access to trusted hosts.
- Keep writable permissions limited to `storage/` and `bootstrap/cache/` as required by Laravel.

## Build and deployment sequence

1. Back up the database and user-generated files before deployment.
2. Deploy a reviewed Git commit/tag; install locked dependencies (`composer install --no-dev --prefer-dist --optimize-autoloader` and `npm ci`).
3. Build frontend assets with `npm run build`.
4. Apply additive migrations with `php artisan migrate --force`. Never use `migrate:fresh` on production.
5. Run `php artisan optimize:clear`, then `php artisan config:cache`, `php artisan route:cache`, and `php artisan view:cache` only after verifying the deployment configuration. If route caching fails due to closures or incompatible routes, fix that first rather than ignoring the error.
6. Run `php artisan metrik:health-check --strict` and smoke-test sign-in, branch selection, POS, report export, notifications, and offline sync.
7. Restart long-running workers after deployment (`php artisan queue:restart`) and ensure a process supervisor restarts workers that exit.

## Queue and scheduler

- Prefer a persistent `database` or `redis` queue, not `sync`, for report exports and mail notifications.
- A configured queue driver is not proof that a worker is alive. Use a supervisor appropriate to the host (e.g. systemd/Supervisor on Linux or a managed Windows service/task on Windows) and monitor failed jobs.
- Run `php artisan queue:failed` during incident review, and use `php artisan queue:retry <id>` only after correcting the cause.
- If scheduled jobs are added, configure Laravel's scheduler on the host and verify the scheduled commands.

## HTTPS and PWA/offline POS

- Serve the production application over HTTPS. Browser Service Workers generally require a secure context (localhost is an exception).
- Test install/update behavior and confirm that `/sw.js`, `/manifest.webmanifest`, and built assets are served from the expected origin.
- Test a real offline sale, reconnect/synchronize, and verify idempotency and conflict review. Do not claim offline inventory is globally current while multiple devices are disconnected.
- Do not cache authenticated HTML, customer data, or report downloads in a shared Cache API cache.
- Keep a process for reviewing pending offline sales and conflicts; never clear browser storage while sales are pending.

## Logging and privacy

- Configure rotating logs and an alert/review process. Avoid logging passwords, tokens, full payment details, or raw secrets.
- Set an appropriate log level for production. Ensure log and backup retention policies match business and legal requirements.
- Health check output is operational data; do not expose it as an unauthenticated public endpoint.

## Release acceptance

- All PHPUnit/Pest tests pass on the release commit.
- `npm run build` completes with no errors.
- `php artisan metrik:health-check --strict` passes on staging.
- Tenant/branch authorization tests pass.
- Restore from a fresh backup has been demonstrated in an isolated staging database.
- Queue worker, failed-job handling, and offline sync have been smoke-tested.
- Git working tree is clean after the release commit.
