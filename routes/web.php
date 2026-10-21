<?php

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\RegisteredUserController;
use App\Http\Controllers\Branches\BranchController;
use App\Http\Controllers\Catalog\CategoryController;
use App\Http\Controllers\Catalog\ProductController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\Inventory\InventoryAdjustmentController;
use App\Http\Controllers\Organizations\OrganizationSetupController;
use App\Http\Controllers\Organizations\SwitchOrganizationController;
use App\Http\Middleware\ResolveOrganizationContext;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return Inertia::render('Welcome', [
        'appName' => 'Metrik POS',
    ]);
})->name('home');

Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthenticatedSessionController::class, 'create'])
        ->name('login');

    Route::post('/login', [AuthenticatedSessionController::class, 'store'])
        ->name('login.store');

    Route::get('/register', fn () => Inertia::render('Auth/Register'))
        ->name('register');

    Route::post('/register', [RegisteredUserController::class, 'store'])
        ->middleware('throttle:6,1')
        ->name('register.store');
});

Route::middleware('auth')->group(function () {
    Route::get('/setup/organization', [OrganizationSetupController::class, 'create'])
        ->name('organization.setup');

    Route::post('/setup/organization', [OrganizationSetupController::class, 'store'])
        ->middleware('throttle:6,1')
        ->name('organization.setup.store');

    Route::post('/organizations/{organization}/switch', SwitchOrganizationController::class)
        ->name('organizations.switch');

    Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])
        ->name('logout');

    Route::middleware(ResolveOrganizationContext::class)->group(function () {
        Route::get('/dashboard', DashboardController::class)
            ->name('dashboard');

        Route::get('/branches', [BranchController::class, 'index'])
            ->name('branches.index');

        Route::post('/branches', [BranchController::class, 'store'])
            ->middleware('throttle:30,1')
            ->name('branches.store');

        Route::post('/branches/{branch}/switch', [BranchController::class, 'switch'])
            ->name('branches.switch');

        Route::get('/products', [ProductController::class, 'index'])
            ->name('products.index');

        Route::post('/products', [ProductController::class, 'store'])
            ->middleware('throttle:60,1')
            ->name('products.store');

        Route::post('/categories', [CategoryController::class, 'store'])
            ->middleware('throttle:30,1')
            ->name('categories.store');

        Route::post('/inventory/adjustments', [InventoryAdjustmentController::class, 'store'])
            ->middleware('throttle:60,1')
            ->name('inventory.adjustments.store');
    });
});
