<?php

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\RegisteredUserController;
use App\Http\Controllers\Branches\BranchController;
use App\Http\Controllers\Catalog\CategoryController;
use App\Http\Controllers\Catalog\ProductController;
use App\Http\Controllers\Customers\CustomerController;
use App\Http\Controllers\Customers\CustomerReceivablesController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\Expenses\ExpenseController;
use App\Http\Controllers\Reports\ReportController;
use App\Http\Controllers\Reports\ReportExportController;
use App\Http\Controllers\Notifications\NotificationController;
use App\Http\Controllers\Organizations\TeamMemberController;
use App\Http\Controllers\Inventory\InventoryAdjustmentController;
use App\Http\Controllers\Inventory\StockTransferController;
use App\Http\Controllers\Inventory\InventoryStockCountController;
use App\Http\Controllers\Inventory\LowStockController;
use App\Http\Controllers\Inventory\InventoryValuationController;
use App\Http\Controllers\Organizations\OrganizationSetupController;
use App\Http\Controllers\Organizations\SwitchOrganizationController;
use App\Http\Controllers\POS\CashShiftController;
use App\Http\Controllers\POS\PointOfSaleController;
use App\Http\Controllers\POS\OfflineSaleSyncController;
use App\Http\Controllers\POS\SaleCheckoutController;
use App\Http\Controllers\Sales\SaleController;
use App\Http\Controllers\Sales\SaleReturnController;
use App\Http\Controllers\Purchasing\PurchaseInvoiceController;
use App\Http\Controllers\Purchasing\SupplierPaymentController;
use App\Http\Controllers\Purchasing\PurchaseReturnController;
use App\Http\Controllers\Suppliers\SupplierController;
use App\Http\Controllers\Suppliers\SupplierPerformanceController;
use App\Http\Middleware\ResolveOrganizationContext;
use App\Http\Middleware\RequestLogContext;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return Inertia::render('Welcome', ['appName' => 'Metrik POS']);
})->middleware(SecurityHeaders::class)->name('home');

Route::middleware(['guest', SecurityHeaders::class])->group(function () {
    Route::get('/login', [AuthenticatedSessionController::class, 'create'])->name('login');
    Route::post('/login', [AuthenticatedSessionController::class, 'store'])->name('login.store');
    Route::get('/register', fn () => Inertia::render('Auth/Register'))->name('register');
    Route::post('/register', [RegisteredUserController::class, 'store'])->middleware('throttle:6,1')->name('register.store');
});

Route::middleware(['auth', SecurityHeaders::class])->group(function () {
    Route::get('/setup/organization', [OrganizationSetupController::class, 'create'])->name('organization.setup');
    Route::post('/setup/organization', [OrganizationSetupController::class, 'store'])->middleware('throttle:6,1')->name('organization.setup.store');
    Route::post('/organizations/{organization}/switch', SwitchOrganizationController::class)->name('organizations.switch');
    Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');

    Route::middleware([ResolveOrganizationContext::class, RequestLogContext::class])->group(function () {
        Route::get('/dashboard', DashboardController::class)->name('dashboard');

        Route::get('/notifications', [NotificationController::class, 'index'])->name('notifications.index');
        Route::post('/notifications/read-all', [NotificationController::class, 'markAllRead'])->name('notifications.readAll');
        Route::post('/notifications/{notificationId}/read', [NotificationController::class, 'markRead'])->name('notifications.read');

        Route::resource('team-members', TeamMemberController::class)
            ->only(['index', 'store', 'update', 'destroy'])
            ->parameters(['team-members' => 'teamMember']);

        Route::get('/branches', [BranchController::class, 'index'])->name('branches.index');
        Route::post('/branches', [BranchController::class, 'store'])->middleware('throttle:30,1')->name('branches.store');
        Route::post('/branches/{branch}/switch', [BranchController::class, 'switch'])->name('branches.switch');

        Route::get('/products/export', [ProductController::class, 'export'])->name('products.export');
        Route::get('/products/template', [ProductController::class, 'template'])->name('products.template');
        Route::post('/products/import', [ProductController::class, 'import'])->middleware('throttle:10,1,products-import')->name('products.import');
        Route::get('/products', [ProductController::class, 'index'])->name('products.index');
        Route::post('/products', [ProductController::class, 'store'])->middleware('throttle:60,1')->name('products.store');
        Route::post('/categories', [CategoryController::class, 'store'])->middleware('throttle:30,1')->name('categories.store');
        Route::post('/inventory/adjustments', [InventoryAdjustmentController::class, 'store'])->middleware('throttle:60,1')->name('inventory.adjustments.store');
        Route::get('/inventory/transfers', [StockTransferController::class, 'index'])->name('inventory.transfers.index');
        Route::post('/inventory/transfers', [StockTransferController::class, 'store'])->name('inventory.transfers.store');
        Route::get('/inventory/counts', [InventoryStockCountController::class, 'index'])->name('inventory.stock-counts.index');
        Route::post('/inventory/counts', [InventoryStockCountController::class, 'store'])->middleware('throttle:30,1,inventory-stock-counts-store')->name('inventory.stock-counts.store');
        Route::get('/inventory/low-stock', [LowStockController::class, 'index'])->name('inventory.low-stock.index');
        Route::get('/inventory/valuation/export', [InventoryValuationController::class, 'export'])->name('inventory.valuation.export');
        Route::get('/inventory/valuation', [InventoryValuationController::class, 'index'])->name('inventory.valuation.index');
        Route::patch('/inventory/low-stock/{productId}/threshold', [LowStockController::class, 'updateThreshold'])->whereNumber('productId')->middleware('throttle:60,1,inventory-reorder-threshold-update')->name('inventory.low-stock.threshold.update');

        Route::get('/suppliers/performance/export', [SupplierPerformanceController::class, 'export'])->name('suppliers.performance.export');
        Route::get('/suppliers/performance', [SupplierPerformanceController::class, 'index'])->name('suppliers.performance.index');
        Route::get('/suppliers', [SupplierController::class, 'index'])->name('suppliers.index');
        Route::post('/suppliers', [SupplierController::class, 'store'])->middleware('throttle:30,1')->name('suppliers.store');
        Route::get('/purchase-invoices', [PurchaseInvoiceController::class, 'index'])->name('purchase-invoices.index');
        Route::post('/purchase-invoices', [PurchaseInvoiceController::class, 'store'])->middleware('throttle:30,1')->name('purchase-invoices.store');
        Route::get('/supplier-payments', [SupplierPaymentController::class, 'index'])->name('supplier-payments.index');
        Route::post('/supplier-payments', [SupplierPaymentController::class, 'store'])->middleware('throttle:30,1')->name('supplier-payments.store');
        Route::get('/purchase-returns', [PurchaseReturnController::class, 'index'])->name('purchase-returns.index');
        Route::post('/purchase-invoices/{purchaseInvoice}/returns', [PurchaseReturnController::class, 'store'])->whereNumber('purchaseInvoice')->middleware('throttle:30,1')->name('purchase-invoices.returns.store');

        Route::get('/pos', [PointOfSaleController::class, 'index'])->name('pos.index');
        Route::get('/pos/products/search', [PointOfSaleController::class, 'searchProducts'])->middleware('throttle:120,1')->name('pos.products.search');
        Route::get('/pos/customers/search', [PointOfSaleController::class, 'searchCustomers'])->middleware('throttle:60,1,pos-customer-search')->name('pos.customers.search');
        Route::get('/pos/offline/catalog', [PointOfSaleController::class, 'offlineCatalog'])->middleware('throttle:30,1')->name('pos.offline.catalog');
        Route::post('/pos/offline/sync', OfflineSaleSyncController::class)->middleware('throttle:60,1')->name('pos.offline.sync');
        Route::post('/pos/shifts', [CashShiftController::class, 'open'])->middleware('throttle:20,1')->name('pos.shifts.open');
        Route::post('/pos/shifts/{shiftId}/close', [CashShiftController::class, 'close'])->whereNumber('shiftId')->middleware('throttle:20,1')->name('pos.shifts.close');
        Route::post('/pos/checkout', [SaleCheckoutController::class, 'store'])->middleware('throttle:60,1')->name('pos.checkout');
        Route::get('/sales', [SaleController::class, 'index'])->name('sales.index');
        Route::get('/sales/{saleId}', [SaleController::class, 'show'])->whereNumber('saleId')->name('sales.show');
        Route::get('/sales/{saleId}/receipt', [SaleController::class, 'receipt'])->whereNumber('saleId')->name('sales.receipt');
        Route::post('/sales/{saleId}/returns', [SaleReturnController::class, 'store'])->whereNumber('saleId')->middleware('throttle:30,1')->name('sales.returns.store');

        Route::get('/customers/receivables/export', [CustomerReceivablesController::class, 'export'])->middleware('throttle:30,1')->name('customers.receivables.export');
        Route::get('/customers/receivables', [CustomerReceivablesController::class, 'index'])->name('customers.receivables.index');
        Route::get('/customers', [CustomerController::class, 'index'])->name('customers.index');
        Route::post('/customers', [CustomerController::class, 'store'])->middleware('throttle:30,1,customers-store')->name('customers.store');
        Route::get('/customers/{customerId}', [CustomerController::class, 'show'])->whereNumber('customerId')->name('customers.show');
        Route::patch('/customers/{customerId}', [CustomerController::class, 'update'])->whereNumber('customerId')->middleware('throttle:30,1,customers-update')->name('customers.update');
        Route::post('/customers/{customerId}/payments', [CustomerController::class, 'collectPayment'])->whereNumber('customerId')->middleware('throttle:30,1,customer-account-payment')->name('customers.payments.store');

        Route::get('/reports', [ReportController::class, 'index'])->name('reports.index');
        Route::post('/reports/exports', [ReportExportController::class, 'store'])->middleware('throttle:10,1')->name('reports.exports.store');
        Route::get('/reports/exports/{reportExport}/download', [ReportExportController::class, 'download'])->whereNumber('reportExport')->name('reports.exports.download');
        Route::get('/reports/export', [ReportController::class, 'export'])->middleware('throttle:30,1')->name('reports.export');
        Route::get('/expenses', [ExpenseController::class, 'index'])->name('expenses.index');
        Route::post('/expenses', [ExpenseController::class, 'store'])->middleware('throttle:30,1')->name('expenses.store');
    });
});
