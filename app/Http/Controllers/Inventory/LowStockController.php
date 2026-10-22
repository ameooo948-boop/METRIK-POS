<?php

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Http\Requests\Inventory\UpdateInventoryReorderThresholdRequest;
use App\Models\InventoryBalance;
use App\Models\Organization;
use App\Models\Product;
use App\Support\DecimalQuantity;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class LowStockController extends Controller
{
    public function index(Request $request): Response|RedirectResponse
    {
        /** @var Organization $organization */
        $organization = $request->attributes->get('currentOrganization');
        $this->authorize('manageInventory', $organization);

        $branch = $request->attributes->get('currentBranch');
        if ($branch === null) {
            return redirect()->route('branches.index')->with('error', 'لا يوجد فرع متاح لهذا الحساب.');
        }

        $organizationId = (int) $organization->getKey();
        $branchId = (int) $branch->getKey();
        $search = trim((string) $request->query('search', ''));
        $search = mb_substr($search, 0, 100);
        $onlyLowStock = $request->boolean('low_stock');

        $productsQuery = Product::query()
            ->leftJoin('inventory_balances as branch_stock', function (JoinClause $join) use ($organizationId, $branchId): void {
                $join->on('branch_stock.product_id', '=', 'products.id')
                    ->where('branch_stock.organization_id', '=', $organizationId)
                    ->where('branch_stock.branch_id', '=', $branchId);
            })
            ->select('products.*')
            ->where('products.organization_id', $organizationId)
            ->where('products.is_active', true)
            ->with([
                'baseUnit:id,code,name_ar,name_en',
                'inventoryBalances' => fn ($query) => $query
                    ->where('organization_id', $organizationId)
                    ->where('branch_id', $branchId)
                    ->select(['id', 'organization_id', 'branch_id', 'product_id', 'quantity', 'reorder_threshold']),
            ])
            ->when($search !== '', fn ($query) => $query->search($search));

        if ($onlyLowStock) {
            $productsQuery
                ->whereRaw('COALESCE(branch_stock.quantity, 0) <= COALESCE(branch_stock.reorder_threshold, products.low_stock_threshold)')
                ->whereRaw('COALESCE(branch_stock.reorder_threshold, products.low_stock_threshold) > 0');
        }

        $products = $productsQuery
            ->orderBy('products.name')
            ->paginate(20)
            ->withQueryString();

        $products->through(static function (Product $product): array {
            $balance = $product->inventoryBalances->first();
            $quantity = (string) ($balance?->quantity ?? '0.000');
            $defaultThreshold = (string) $product->low_stock_threshold;
            $branchThreshold = $balance?->reorder_threshold;
            $effectiveThreshold = $branchThreshold ?? $defaultThreshold;
            $quantityMilli = DecimalQuantity::toMilli($quantity);
            $thresholdMilli = DecimalQuantity::toMilli((string) $effectiveThreshold);
            $isLowStock = $thresholdMilli > 0 && $quantityMilli <= $thresholdMilli;

            return [
                'id' => (int) $product->getKey(),
                'sku' => (string) $product->sku,
                'name' => (string) $product->name,
                'unitName' => $product->baseUnit?->name_ar ?? $product->baseUnit?->name_en ?? 'وحدة أساسية',
                'quantity' => $quantity,
                'defaultThreshold' => $defaultThreshold,
                'branchThreshold' => $branchThreshold === null ? null : (string) $branchThreshold,
                'effectiveThreshold' => (string) $effectiveThreshold,
                'isLowStock' => $isLowStock,
                'reorderQuantity' => DecimalQuantity::fromMilli(max(0, ($thresholdMilli * 2) - $quantityMilli)),
            ];
        });

        $lowStockCount = $this->lowStockCount($organizationId, $branchId);
        $activeProductCount = Product::query()
            ->where('organization_id', $organizationId)
            ->where('is_active', true)
            ->count();

        return Inertia::render('Inventory/LowStock', [
            'organization' => $organization->only(['id', 'name', 'currency']),
            'currentBranch' => $branch->only(['id', 'name', 'code']),
            'products' => $products,
            'filters' => ['search' => $search, 'lowStock' => $onlyLowStock],
            'lowStockCount' => $lowStockCount,
            'activeProductCount' => $activeProductCount,
            'flashMessage' => $request->session()->get('success'),
            'errorMessage' => $request->session()->get('error'),
        ]);
    }

    public function updateThreshold(UpdateInventoryReorderThresholdRequest $request, int $productId): RedirectResponse
    {
        /** @var Organization $organization */
        $organization = $request->attributes->get('currentOrganization');
        $this->authorize('manageInventory', $organization);

        $branch = $request->attributes->get('currentBranch');
        if ($branch === null) {
            return redirect()->route('branches.index')->with('error', 'لا يوجد فرع متاح لهذا الحساب.');
        }

        $organizationId = (int) $organization->getKey();
        $branchId = (int) $branch->getKey();
        $product = Product::query()
            ->where('organization_id', $organizationId)
            ->where('is_active', true)
            ->whereKey($productId)
            ->firstOrFail();
        $threshold = $request->validated('reorder_threshold');

        DB::transaction(function () use ($organizationId, $branchId, $product, $threshold): void {
            $balance = InventoryBalance::query()
                ->where('organization_id', $organizationId)
                ->where('branch_id', $branchId)
                ->where('product_id', $product->getKey())
                ->lockForUpdate()
                ->first();

            if ($balance === null) {
                $balance = new InventoryBalance([
                    'organization_id' => $organizationId,
                    'branch_id' => $branchId,
                    'product_id' => (int) $product->getKey(),
                    'quantity' => '0.000',
                ]);
            }

            $balance->reorder_threshold = $threshold === null ? null : (string) $threshold;
            $balance->save();
        });

        $message = $threshold === null
            ? 'تم إرجاع حد التنبيه إلى الإعداد الافتراضي للمنتج.'
            : (DecimalQuantity::toMilli((string) $threshold) === 0
                ? 'تم تعطيل تنبيه إعادة الطلب لهذا المنتج في الفرع الحالي.'
                : 'تم حفظ حد إعادة الطلب للفرع الحالي.');

        return redirect()
            ->route('inventory.low-stock.index', $request->only(['search', 'low_stock']))
            ->with('success', $message);
    }

    private function lowStockCount(int $organizationId, int $branchId): int
    {
        return Product::query()
            ->leftJoin('inventory_balances as branch_stock', function (JoinClause $join) use ($organizationId, $branchId): void {
                $join->on('branch_stock.product_id', '=', 'products.id')
                    ->where('branch_stock.organization_id', '=', $organizationId)
                    ->where('branch_stock.branch_id', '=', $branchId);
            })
            ->where('products.organization_id', $organizationId)
            ->where('products.is_active', true)
            ->whereRaw('COALESCE(branch_stock.quantity, 0) <= COALESCE(branch_stock.reorder_threshold, products.low_stock_threshold)')
            ->whereRaw('COALESCE(branch_stock.reorder_threshold, products.low_stock_threshold) > 0')
            ->count('products.id');
    }
}
