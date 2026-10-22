<?php

namespace App\Providers;

use App\Contracts\Catalog\ProductRepository;
use App\Repositories\Eloquent\EloquentProductRepository;
use App\Observers\AuditedModelObserver;
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
        // Audit only explicit business entities; credentials and free-text payloads are excluded by the observer.
        $auditedModels = [
            \App\Models\Product::class,
            \App\Models\Category::class,
            \App\Models\ProductBarcode::class,
            \App\Models\ProductUnitConversion::class,
            \App\Models\Customer::class,
            \App\Models\CustomerAccountTransaction::class,
            \App\Models\Supplier::class,
            \App\Models\SupplierPayment::class,
            \App\Models\Promotion::class,
            \App\Models\InventoryBalance::class,
            \App\Models\InventoryMovement::class,
            \App\Models\InventoryLot::class,
            \App\Models\StockTransfer::class,
            \App\Models\InventoryStockCount::class,
            \App\Models\Sale::class,
            \App\Models\SaleItem::class,
            \App\Models\SaleReturn::class,
            \App\Models\SaleReturnItem::class,
            \App\Models\PurchaseInvoice::class,
            \App\Models\PurchaseInvoiceItem::class,
            \App\Models\PurchaseOrder::class,
            \App\Models\PurchaseOrderItem::class,
            \App\Models\PurchaseReturn::class,
            \App\Models\PurchaseReturnItem::class,
            \App\Models\CashShift::class,
            \App\Models\CashMovement::class,
            \App\Models\Expense::class,
        ];

        foreach ($auditedModels as $modelClass) {
            if (class_exists($modelClass)) {
                $modelClass::observe(AuditedModelObserver::class);
            }
        }

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
