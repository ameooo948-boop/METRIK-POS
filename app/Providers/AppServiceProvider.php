<?php

namespace App\Providers;

use App\Contracts\Catalog\ProductRepository;
use App\Repositories\Eloquent\EloquentProductRepository;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Dependency inversion: application services depend on the contract, not Eloquent.
        $this->app->bind(ProductRepository::class, EloquentProductRepository::class);
    }

    public function boot(): void
    {
        // Catch accidental N+1 queries during local development without changing production behavior.
        Model::preventLazyLoading((bool) config('metrik.performance.prevent_lazy_loading', false));

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
