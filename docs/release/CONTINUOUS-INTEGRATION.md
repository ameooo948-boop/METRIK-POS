# Metrik POS continuous integration

The workflow at `.github/workflows/metrik-pos-ci.yml` runs on pushes, pull requests, and manual dispatches. It checks Composer metadata and production PHP platform requirements, installs dependencies, builds the Vue/Vite assets, runs database migrations against a temporary SQLite file, executes the Laravel test suite, and runs the strict Metrik health check.

## Environment isolation

- The workflow creates an ephemeral `.env` from `.env.example` and generates a CI-only application key.
- Tests and migrations use `database/ci.sqlite`; no production database credentials or GitHub secrets are required.
- Cache uses the `file` store and the queue uses Laravel's `database` driver. The workflow does not require a running Redis service.
- The workflow does not deploy the application or publish artifacts to a production server.

## Before relying on the workflow

1. Commit `.env.example`, `composer.json`, `composer.lock`, `package.json`, and `package-lock.json` to the repository. Never commit `.env` or secrets.
2. Verify `.env.example` contains only safe local defaults. Do not place live credentials in it.
3. Push the workflow to your GitHub repository. Open the **Actions** tab to see its result; pull requests will show the CI status as well.
4. A green CI run is an automated signal, not a substitute for manually testing offline sale synchronization, backup restoration, cashier/owner permissions, and the real database driver used by your deployment.

## If the workflow fails

Open the failed job and expand the first failing step. Fix the underlying issue and rerun the workflow; do not hide a failure by skipping migrations, tests, platform checks, or the health check. If the project uses a PHP version different from the supported production version, update the workflow intentionally and keep local/prod versions aligned.
