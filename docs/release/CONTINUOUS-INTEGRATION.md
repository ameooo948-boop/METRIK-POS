# Metrik POS continuous integration

The workflow at `.github/workflows/metrik-pos-ci.yml` runs on pushes, pull requests, and manual dispatches. The workflow uses `concurrency.queue: max` so runs for the same ref wait in a queue instead of replacing an older pending run. GitHub permits up to 100 pending runs per concurrency group; additional runs beyond that limit may be canceled. It pins the runner to `ubuntu-24.04` so the image does not change automatically during the Ubuntu 26 migration, and uses current checkout/setup-node action major versions compatible with the Node 24 runtime. It checks Composer metadata and production PHP platform requirements, installs dependencies, builds the Vue/Vite assets, runs database migrations against a temporary SQLite file, executes the Laravel test suite, and runs the strict Metrik health check.

## Environment isolation

- The workflow creates an ephemeral `.env` from `.env.example` and generates a CI-only application key.
- Tests and migrations use `database/ci.sqlite`; no production database credentials or GitHub secrets are required.
- Cache and session use Laravel's in-memory `array` drivers for test isolation; this prevents rate-limit counters from leaking between test cases. The queue uses Laravel's `database` driver. The workflow does not require a running Redis service.
- The workflow does not deploy the application or publish artifacts to a production server.

## Before relying on the workflow

1. Commit `.env.example`, `composer.json`, `composer.lock`, `package.json`, and `package-lock.json` to the repository. Never commit `.env` or secrets.
2. Verify `.env.example` contains only safe local defaults. Do not place live credentials in it.
3. Push the workflow to your GitHub repository. Open the **Actions** tab to see its result; pull requests will show the CI status as well.
4. A green CI run is an automated signal, not a substitute for manually testing offline sale synchronization, backup restoration, cashier/owner permissions, and the real database driver used by your deployment.

## If the workflow fails

Open the failed job and expand the first failing step. Fix the underlying issue and rerun the workflow; do not hide a failure by skipping migrations, tests, platform checks, or the health check. If the project uses a PHP version different from the supported production version, update the workflow intentionally and keep local/prod versions aligned.


## CI runner and action maintenance

- `actions/checkout@v7` and `actions/setup-node@v7` are used to avoid the retired Node 20 action runtime. Keep GitHub Actions runner compatibility in mind if using self-hosted runners; current Node 24 actions require a sufficiently recent runner.
- The workflow is pinned to `ubuntu-24.04` for reproducibility. Upgrade to `ubuntu-26.04` deliberately after testing this application against that image.
- `queue: max` prevents the default behavior where a new pending run replaces an older pending run. GitHub allows at most 100 pending runs for a concurrency group.
