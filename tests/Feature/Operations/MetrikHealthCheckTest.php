<?php

namespace Tests\Feature\Operations;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MetrikHealthCheckTest extends TestCase
{
    use RefreshDatabase;

    public function test_health_check_reports_database_and_core_schema(): void
    {
        config([
            'app.key' => 'base64:'.base64_encode(str_repeat('k', 32)),
            'app.env' => 'testing',
            'app.debug' => false,
            'queue.default' => 'sync',
            'cache.default' => 'array',
        ]);

        $this->artisan('metrik:health-check')
            ->expectsOutputToContain('PASS Database connection is available.')
            ->expectsOutputToContain('PASS Core database schema is available.')
            ->assertSuccessful();
    }

    public function test_strict_mode_fails_when_a_readiness_warning_exists(): void
    {
        config([
            'app.key' => 'base64:'.base64_encode(str_repeat('k', 32)),
            'app.env' => 'testing',
            'app.debug' => true,
            'queue.default' => 'sync',
            'cache.default' => 'array',
        ]);

        $this->artisan('metrik:health-check --strict')
            ->expectsOutputToContain('APP_DEBUG is enabled.')
            ->assertExitCode(1);
    }
}
