<?php

namespace App\Console\Commands;

use App\Support\BackupArchiveVerifier;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Schema;
use Throwable;

class MetrikOperationsCheck extends Command
{
    protected $signature = 'metrik:operations-check {--strict : Return a failure status when any warning exists} {--json : Emit a machine-readable JSON report only}';

    protected $description = 'Report production operations readiness: database, queue backlog, failed jobs, backup age/integrity, disk space, and writable paths.';

    /** @var list<array{check:string,status:string,message:string}> */
    private array $checks = [];

    public function handle(BackupArchiveVerifier $backupVerifier): int
    {
        $this->checks = [];
        $this->checkApplicationConfiguration();
        $this->checkDatabase();
        $this->checkCoreSchema();
        $this->checkQueue();
        $this->checkLatestBackup($backupVerifier);
        $this->checkDiskSpace();
        $this->checkWritablePaths();

        $failures = count(array_filter($this->checks, static fn (array $check): bool => $check['status'] === 'FAIL'));
        $warnings = count(array_filter($this->checks, static fn (array $check): bool => $check['status'] === 'WARN'));
        $overall = $failures > 0 || ($this->option('strict') && $warnings > 0)
            ? 'fail'
            : ($warnings > 0 ? 'warning' : 'ok');

        $report = [
            'application' => (string) config('app.name', 'Metrik POS'),
            'checked_at' => now()->toIso8601String(),
            'environment' => app()->environment(),
            'status' => $overall,
            'failure_count' => $failures,
            'warning_count' => $warnings,
            'checks' => $this->checks,
        ];

        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        } else {
            foreach ($this->checks as $check) {
                $color = match ($check['status']) {
                    'PASS' => 'green',
                    'WARN' => 'yellow',
                    default => 'red',
                };
                $this->line(sprintf('<fg=%s>%s</> %s — %s', $color, $check['status'], $check['check'], $check['message']));
            }

            $this->newLine();
            $this->line(sprintf('Operations check: %s (%d failure(s), %d warning(s)).', strtoupper($overall), $failures, $warnings));
        }

        return $overall === 'fail' ? self::FAILURE : self::SUCCESS;
    }

    private function checkApplicationConfiguration(): void
    {
        $production = app()->environment('production');
        $keyIsSet = filled(config('app.key'));
        $this->recordValue('APP_KEY', $keyIsSet, $production, 'A configured application key is required.', 'Configured.');

        if ((bool) config('app.debug')) {
            if ($production) {
                $this->record('APP_DEBUG', 'FAIL', 'Debug mode must be disabled in production.');
            } else {
                $this->record('APP_DEBUG', 'WARN', 'Debug mode is enabled; acceptable only in local development.');
            }
        } else {
            $this->record('APP_DEBUG', 'PASS', 'Debug mode is disabled.');
        }

        $scheme = strtolower((string) parse_url((string) config('app.url'), PHP_URL_SCHEME));
        if ($production && $scheme !== 'https') {
            $this->record('APP_URL', 'FAIL', 'Production APP_URL must use HTTPS.');
        } elseif ($scheme === 'https' || ! $production) {
            $this->record('APP_URL', 'PASS', 'Application URL scheme is acceptable for this environment.');
        } else {
            $this->record('APP_URL', 'WARN', 'Use the actual HTTPS URL before production.');
        }
    }

    private function checkDatabase(): void
    {
        try {
            DB::select('SELECT 1');
            $this->record('database', 'PASS', 'Database connection is responding.');
        } catch (Throwable $exception) {
            Log::error('Metrik operations check: database connection failed', [
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);
            $this->record('database', 'FAIL', 'Database query failed; inspect the protected application log.');
        }
    }

    private function checkCoreSchema(): void
    {
        try {
            $required = [
                'users', 'organizations', 'branches', 'products',
                'inventory_balances', 'inventory_movements', 'sales',
                'sale_items', 'cash_shifts',
            ];
            $missing = array_values(array_filter($required, static fn (string $table): bool => ! Schema::hasTable($table)));
            if ($missing !== []) {
                $this->record('core_schema', 'FAIL', 'Required tables are missing: '.implode(', ', $missing).'.');

                return;
            }
            $this->record('core_schema', 'PASS', 'Required business tables are present.');
        } catch (Throwable $exception) {
            Log::error('Metrik operations check: schema check failed', ['message' => $exception->getMessage()]);
            $this->record('core_schema', 'FAIL', 'Could not verify required tables; inspect the protected application log.');
        }
    }

    private function checkQueue(): void
    {
        try {
            $this->inspectQueue();
        } catch (Throwable $exception) {
            Log::error('Metrik operations check: queue inspection failed', [
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);
            $this->record('queue_check', 'FAIL', 'Could not inspect queue status; inspect the protected application log.');
        }
    }

    private function inspectQueue(): void
    {
        $driver = (string) config('queue.default', 'sync');
        if ($driver === 'sync') {
            $this->record(
                'queue_driver',
                app()->environment('production') ? 'WARN' : 'PASS',
                app()->environment('production')
                    ? 'The sync driver runs queued jobs inline; use a persistent queue for production.'
                    : 'The sync driver is configured for this non-production environment.',
            );
        } elseif ($driver === 'redis') {
            try {
                Redis::connection()->ping();
                $this->record('queue_driver', 'PASS', 'Redis queue connection responded; backlog depth is not inferred by this generic check.');
            } catch (Throwable $exception) {
                Log::error('Metrik operations check: Redis queue connection failed', ['message' => $exception->getMessage()]);
                $this->record('queue_driver', 'FAIL', 'Redis queue connection failed; inspect the protected application log.');
            }
        } elseif ($driver === 'database') {
            $missing = array_values(array_filter(['jobs', 'failed_jobs'], static fn (string $table): bool => ! Schema::hasTable($table)));
            if ($missing !== []) {
                $this->record('queue_schema', 'FAIL', 'Required queue tables are missing: '.implode(', ', $missing).'.');

                return;
            }

            $this->record('queue_driver', 'PASS', 'Database queue schema is present.');
            try {
                $maxPending = max(1, (int) config('operations.queue_max_pending', 500));
                $maxAgeMinutes = max(1, (int) config('operations.queue_max_age_minutes', 15));
                $pending = (int) DB::table('jobs')->count();
                if ($pending > $maxPending) {
                    $this->record('queue_backlog', 'WARN', sprintf('%d queued job(s) exceed the configured threshold of %d.', $pending, $maxPending));
                } else {
                    $this->record('queue_backlog', 'PASS', sprintf('%d queued job(s); threshold is %d.', $pending, $maxPending));
                }

                $cutoff = now()->subMinutes($maxAgeMinutes)->timestamp;
                $oldPending = (int) DB::table('jobs')
                    ->whereNull('reserved_at')
                    ->where('available_at', '<=', $cutoff)
                    ->count();
                if ($oldPending > 0) {
                    $this->record('queue_lag', 'WARN', sprintf('%d unreserved job(s) are older than %d minute(s).', $oldPending, $maxAgeMinutes));
                } else {
                    $this->record('queue_lag', 'PASS', sprintf('No unreserved job is older than %d minute(s).', $maxAgeMinutes));
                }
            } catch (Throwable $exception) {
                Log::error('Metrik operations check: queue backlog check failed', ['message' => $exception->getMessage()]);
                $this->record('queue_backlog', 'FAIL', 'Could not inspect the database queue; inspect the protected application log.');
            }
        } else {
            $this->record('queue_driver', 'WARN', 'Queue driver "'.$driver.'" is configured; configure a driver-specific backlog probe.');
        }

        if (Schema::hasTable('failed_jobs')) {
            try {
                $failed = (int) DB::table('failed_jobs')->count();
                if ($failed > 0) {
                    $this->record('failed_jobs', 'WARN', sprintf('%d failed job(s) require triage.', $failed));
                } else {
                    $this->record('failed_jobs', 'PASS', 'No failed queue jobs are recorded.');
                }
            } catch (Throwable $exception) {
                Log::error('Metrik operations check: failed-job inspection failed', ['message' => $exception->getMessage()]);
                $this->record('failed_jobs', 'WARN', 'Could not read failed-job count.');
            }
        }
    }

    private function checkLatestBackup(BackupArchiveVerifier $verifier): void
    {
        $directory = trim((string) config('backup.directory', '')) ?: storage_path('app/private/backups');
        if (! str_starts_with($directory, DIRECTORY_SEPARATOR) && preg_match('~^[A-Za-z]:[\\\\/]~', $directory) !== 1) {
            $directory = base_path($directory);
        }
        $files = glob(rtrim($directory, '/\\').DIRECTORY_SEPARATOR.'metrik-backup_*.zip') ?: [];
        if ($files === []) {
            $this->record('backup', 'FAIL', 'No Metrik backup archive was found in the configured backup directory.');

            return;
        }

        usort($files, static fn (string $left, string $right): int => (int) filemtime($right) <=> (int) filemtime($left));
        $latest = $files[0];
        $timestamp = filemtime($latest);
        if (! is_int($timestamp) && ! is_float($timestamp)) {
            $this->record('backup', 'FAIL', 'Could not determine the latest backup timestamp.');

            return;
        }

        $ageHours = max(0, now()->timestamp - (int) $timestamp) / 3600;
        $maxAgeHours = max(1, (int) config('backup.max_age_hours', 26));
        if ($ageHours > $maxAgeHours) {
            $this->record('backup', 'FAIL', sprintf('Latest backup is %.1f hour(s) old; configured maximum is %d hours.', $ageHours, $maxAgeHours));

            return;
        }

        try {
            $manifest = $verifier->verify($latest);
            $this->record('backup', 'PASS', sprintf(
                'Latest archive integrity verified (%.1f hour(s) old; format v%d).',
                $ageHours,
                (int) ($manifest['format_version'] ?? 1),
            ));
        } catch (Throwable $exception) {
            Log::critical('Metrik operations check: latest backup failed verification', [
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);
            $this->record('backup', 'FAIL', 'Latest backup failed integrity verification; inspect the protected application log.');
        }
    }

    private function checkDiskSpace(): void
    {
        $freeBytes = @disk_free_space(storage_path());
        if (! is_float($freeBytes) && ! is_int($freeBytes)) {
            $this->record('disk_space', 'WARN', 'Free disk space could not be determined on this platform.');

            return;
        }

        $freeMb = (int) floor($freeBytes / 1024 / 1024);
        $criticalMb = max(1, (int) config('operations.disk_free_critical_mb', 512));
        $warningMb = max($criticalMb, (int) config('operations.disk_free_warning_mb', 2048));
        if ($freeMb < $criticalMb) {
            $this->record('disk_space', 'FAIL', sprintf('%d MB free; critical threshold is %d MB.', $freeMb, $criticalMb));
        } elseif ($freeMb < $warningMb) {
            $this->record('disk_space', 'WARN', sprintf('%d MB free; warning threshold is %d MB.', $freeMb, $warningMb));
        } else {
            $this->record('disk_space', 'PASS', sprintf('%d MB free.', $freeMb));
        }
    }

    private function checkWritablePaths(): void
    {
        foreach ([storage_path('app'), storage_path('logs'), base_path('bootstrap/cache')] as $directory) {
            if (is_dir($directory) && is_writable($directory)) {
                $this->record('writable_path:'.$this->relativePath($directory), 'PASS', 'Directory is writable.');
            } else {
                $this->record('writable_path:'.$this->relativePath($directory), 'FAIL', 'Directory is missing or not writable by the application process.');
            }
        }
    }

    private function recordValue(string $name, bool $passed, bool $critical, string $failure, string $success): void
    {
        $this->record($name, $passed ? 'PASS' : ($critical ? 'FAIL' : 'WARN'), $passed ? $success : $failure);
    }

    private function record(string $name, string $status, string $message): void
    {
        $this->checks[] = [
            'check' => $name,
            'status' => $status,
            'message' => $message,
        ];
    }

    private function relativePath(string $path): string
    {
        return str_replace(base_path().DIRECTORY_SEPARATOR, '', $path);
    }
}
