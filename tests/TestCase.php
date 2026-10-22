<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Tests can reuse SQLite user IDs, so stale throttle counters must not leak
        // from one test case into another. Rate-limiting behavior remains enabled
        // and can still be exercised within an individual test.
        $this->app->make('cache')->store()->flush();
    }
}
