# CI rate-limit isolation

The CI suite uses SQLite and creates users repeatedly across feature tests. Because SQLite can reuse rolled-back auto-increment IDs, rate-limit cache keys can collide between tests if prior counters remain in the cache.

Two changes address this without disabling application throttling:

- `tests/TestCase.php` flushes the default testing cache after the Laravel test environment is initialized and before each test runs. Rate limits remain active during each individual test.
- Each route-level `throttle` middleware in `routes/web.php` has a distinct prefix. This prevents unrelated actions (for example, opening a cash shift and creating an expense) from consuming the same route-level counter for one authenticated user.

The workflow keeps `CACHE_STORE=array` for CI and remains otherwise aligned with the release validation flow. Re-run the full workflow after applying this patch; the patch itself has not been run against the user's local checkout or GitHub runner.
