<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

class MetrikHealthCheck extends Command
{
    protected $signature = 'metrik:health-check {--strict : Treat warnings as failures}';

    protected $description = 'Check Metrik POS runtime dependencies and production-readiness basics.';

    private int $failures = 0;

    private int $warnings = 0;

    public function handle(): int
    {
        $isProduction = app()->environment('production');

        $this->checkValue('APP_KEY', filled(config('app.key')), $isProduction, 'A configured APP_KEY is required in production.');

        if ($isProduction && (bool) config('app.debug')) {
            $this->recordFailure('APP_DEBUG', 'APP_DEBUG must be false in production.');
        } elseif ((bool) config('app.debug')) {
            $this->recordWarning('APP_DEBUG is enabled. This is acceptable locally, but disable it in production.');
        } else {
            $this->pass('APP_DEBUG is disabled.');
        }

        $this->runCheck('Database connection', function (): void {
            DB::select('SELECT 1');
        });

        $requiredTables = [
            'users', 'organizations', 'branches', 'products',
            'inventory_balances', 'inventory_movements', 'sales',
            'sale_items', 'cash_shifts',
        ];

        $this->runCheck('Core database schema', function () use ($requiredTables): void {
            $missing = array_values(array_filter(
                $requiredTables,
                fn (string $table): bool => ! Schema::hasTable($table),
            ));

            if ($missing !== []) {
                throw new \RuntimeException('Missing tables: '.implode(', ', $missing));
            }
        });

        $queue = (string) config('queue.default');
        if ($queue === 'database') {
            $this->runCheck('Database queue schema', function (): void {
                $missing = array_values(array_filter(
                    ['jobs', 'failed_jobs'],
                    fn (string $table): bool => ! Schema::hasTable($table),
                ));

                if ($missing !== []) {
                    throw new \RuntimeException('Missing tables: '.implode(', ', $missing));
                }
            });
        } elseif ($queue === 'redis') {
            $this->runCheck('Redis queue connection', function (): void {
                Redis::connection()->ping();
            });
        } elseif ($queue === 'sync' && $isProduction) {
            $this->recordWarning('QUEUE_CONNECTION=sync means queued work executes inline in production. Configure a persistent queue driver.');
        } else {
            $this->pass('Queue driver configured: '.$queue);
        }

        $this->runCheck('Cache store', function (): void {
            $store = Cache::store();
            $key = 'metrik:health-check:'.Str::uuid();
            $store->put($key, 'ok', now()->addSeconds(15));
            $value = $store->get($key);
            $store->forget($key);

            if ($value !== 'ok') {
                throw new \RuntimeException('Cache value did not round-trip.');
            }
        });

        foreach ([storage_path('app'), storage_path('logs'), storage_path('framework/cache'), base_path('bootstrap/cache')] as $directory) {
            $this->checkValue(
                'Writable directory '.$this->relativePath($directory),
                is_dir($directory) && is_writable($directory),
                $isProduction,
                'Ensure the PHP process can write to this directory.',
            );
        }

        $manifestPresent = is_file(public_path('manifest.webmanifest'));
        $workerPresent = is_file(public_path('sw.js'));
        $this->checkValue('PWA manifest', $manifestPresent, $isProduction, 'Build/package the PWA manifest before a production release.');
        $this->checkValue('Service worker', $workerPresent, $isProduction, 'The offline POS service worker is missing.');

        if ($isProduction && ! is_file(public_path('build/manifest.json'))) {
            $this->recordFailure('Vite production build', 'public/build/manifest.json was not found. Run npm run build before deployment.');
        } elseif (is_file(public_path('build/manifest.json'))) {
            $this->pass('Vite production build manifest is present.');
        } else {
            $this->recordWarning('Vite build manifest is absent; run npm run build before testing the production build.');
        }

        $this->newLine();
        $this->line(sprintf('Health check finished: %d failure(s), %d warning(s).', $this->failures, $this->warnings));

        return $this->failures > 0 || ($this->option('strict') && $this->warnings > 0)
            ? self::FAILURE
            : self::SUCCESS;
    }

    private function runCheck(string $label, callable $check): void
    {
        try {
            $check();
            $this->pass($label.' is available.');
        } catch (Throwable $exception) {
            $this->recordFailure($label, $exception->getMessage());
        }
    }

    private function checkValue(string $label, bool $passed, bool $critical, string $failureDetail): void
    {
        if ($passed) {
            $this->pass($label);

            return;
        }

        if ($critical) {
            $this->recordFailure($label, $failureDetail);

            return;
        }

        $this->recordWarning($label.' — '.$failureDetail);
    }

    private function pass(string $message): void
    {
        $this->line('<fg=green>PASS</> '.$message);
    }

    private function recordWarning(string $message): void
    {
        $this->warnings++;
        $this->line('<fg=yellow>WARN</> '.$message);
    }

    private function recordFailure(string $label, string $detail): void
    {
        $this->failures++;
        $this->line('<fg=red>FAIL</> '.$label.' — '.$detail);
    }

    private function relativePath(string $path): string
    {
        return str_replace(base_path().DIRECTORY_SEPARATOR, '', $path);
    }
}
