<?php

namespace App\Providers;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Bind application services here when they need explicit container bindings.
    }

    public function boot(): void
    {
        if (! config('metrik.performance.slow_query_logging_enabled', false)) {
            return;
        }

        DB::listen(function (QueryExecuted $query): void {
            $threshold = (int) config('metrik.performance.slow_query_threshold_ms', 250);

            if ($query->time < $threshold) {
                return;
            }

            // Do not log query bindings; they may contain customer or transaction data.
            Log::warning('Metrik POS slow database query detected.', [
                'connection' => $query->connectionName,
                'duration_ms' => round($query->time, 2),
                'sql' => $query->sql,
                'threshold_ms' => $threshold,
            ]);
        });
    }
}
