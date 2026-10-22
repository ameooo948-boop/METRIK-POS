<?php

namespace Tests\Feature\Operations;

use App\Support\BackupArchiveVerifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class MetrikOperationsCheckTest extends TestCase
{
    use RefreshDatabase;

    private string $backupDirectory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->backupDirectory = storage_path('framework/testing/operations-backups-'.bin2hex(random_bytes(5)));
        File::ensureDirectoryExists($this->backupDirectory);
        config([
            'app.key' => 'base64:'.base64_encode(str_repeat('k', 32)),
            'app.env' => 'testing',
            'app.debug' => false,
            'app.url' => 'https://metrik.test',
            'queue.default' => 'sync',
            'cache.default' => 'array',
            'backup.directory' => $this->backupDirectory,
            'backup.max_age_hours' => 26,
            'operations.disk_free_warning_mb' => 1,
            'operations.disk_free_critical_mb' => 1,
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->backupDirectory);
        parent::tearDown();
    }

    public function test_operations_check_emits_machine_readable_report_and_passes_when_dependencies_are_healthy(): void
    {
        $this->addVerifiedBackupFixture();

        $this->mock(BackupArchiveVerifier::class, function ($mock): void {
            $mock->shouldReceive('verify')->once()->andReturn(['format_version' => 2]);
        });

        // Decode the complete JSON report instead of registering multiple
        // PendingCommand substring expectations against one multi-line write.
        // Some Laravel/Mockery combinations only satisfy one doWrite matcher
        // when the command emits the whole pretty-printed JSON in one call.
        $exitCode = Artisan::call('metrik:operations-check', ['--json' => true]);
        $output = Artisan::output();
        $report = json_decode($output, true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exitCode);
        $this->assertSame('ok', $report['status']);
        $this->assertContains('backup', array_column($report['checks'], 'check'));
    }

    public function test_operations_check_fails_when_no_recent_backup_exists(): void
    {
        $exitCode = Artisan::call('metrik:operations-check');
        $output = Artisan::output();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('FAIL backup', $output);
    }

    public function test_strict_mode_fails_when_failed_jobs_are_recorded(): void
    {
        $this->addVerifiedBackupFixture();
        $this->mock(BackupArchiveVerifier::class, function ($mock): void {
            $mock->shouldReceive('verify')->once()->andReturn(['format_version' => 2]);
        });

        DB::table('failed_jobs')->insert([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'connection' => 'database',
            'queue' => 'default',
            'payload' => '{}',
            'exception' => 'sanitized test exception',
            'failed_at' => now(),
        ]);

        $exitCode = Artisan::call('metrik:operations-check', [
            '--strict' => true,
            '--json' => true,
        ]);
        $output = Artisan::output();
        $report = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
        $failedJobsCheck = collect($report['checks'])->firstWhere('check', 'failed_jobs');

        $this->assertSame(1, $exitCode);
        $this->assertSame('fail', $report['status']);
        $this->assertNotNull($failedJobsCheck);
        $this->assertSame('WARN', $failedJobsCheck['status']);
    }

    private function addVerifiedBackupFixture(): void
    {
        $archive = $this->backupDirectory.DIRECTORY_SEPARATOR.'metrik-backup_test.zip';
        file_put_contents($archive, 'test fixture only');
        touch($archive, time());
    }
}
