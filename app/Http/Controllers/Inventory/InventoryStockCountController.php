<?php

namespace App\Http\Controllers\Inventory;

use App\Data\Inventory\CreateInventoryStockCountData;
use App\Http\Controllers\Controller;
use App\Http\Requests\Inventory\StoreInventoryStockCountRequest;
use App\Models\InventoryStockCount;
use App\Models\Organization;
use App\Models\Product;
use App\Services\Inventory\CreateInventoryStockCount;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class InventoryStockCountController extends Controller
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

        $products = Product::query()
            ->forOrganization($organizationId)
            ->active()
            ->with([
                'baseUnit',
                'inventoryBalances' => fn ($query) => $query
                    ->where('organization_id', $organizationId)
                    ->where('branch_id', $branchId),
            ])
            ->orderBy('name')
            ->get()
            ->map(static function (Product $product): array {
                $balance = $product->inventoryBalances->first();

                return [
                    'id' => (int) $product->getKey(),
                    'sku' => (string) $product->sku,
                    'name' => (string) $product->name,
                    'unitName' => $product->baseUnit?->name_ar ?? $product->baseUnit?->name_en ?? 'وحدة أساسية',
                    'quantity' => (string) ($balance?->quantity ?? '0.000'),
                ];
            })->values();

        $counts = InventoryStockCount::query()
            ->forOrganization($organizationId)
            ->where('branch_id', $branchId)
            ->with([
                'createdBy:id,name',
                'items:id,inventory_stock_count_id,product_sku_snapshot,product_name_snapshot,expected_quantity,counted_quantity,variance_quantity',
            ])
            ->orderByDesc('id')
            ->paginate(10)
            ->through(static fn (InventoryStockCount $count): array => [
                'id' => (int) $count->getKey(),
                'countNumber' => $count->count_number,
                'status' => $count->status,
                'totalLines' => (int) $count->total_lines,
                'totalVarianceLines' => (int) $count->total_variance_lines,
                'notes' => $count->notes,
                'createdBy' => $count->createdBy?->name ?? '—',
                'createdAt' => $count->created_at?->format('Y-m-d H:i'),
                'items' => $count->items->map(static fn ($item): array => [
                    'id' => (int) $item->getKey(),
                    'sku' => $item->product_sku_snapshot,
                    'name' => $item->product_name_snapshot,
                    'expectedQuantity' => (string) $item->expected_quantity,
                    'countedQuantity' => (string) $item->counted_quantity,
                    'varianceQuantity' => (string) $item->variance_quantity,
                ])->values(),
            ]);

        return Inertia::render('Inventory/StockCounts', [
            'organization' => $organization->only(['id', 'name', 'currency']),
            'currentBranch' => $branch->only(['id', 'name', 'code']),
            'products' => $products,
            'counts' => $counts,
            'flashMessage' => $request->session()->get('success'),
            'errorMessage' => $request->session()->get('error'),
        ]);
    }

    public function store(
        StoreInventoryStockCountRequest $request,
        CreateInventoryStockCount $createInventoryStockCount,
    ): RedirectResponse {
        /** @var Organization $organization */
        $organization = $request->attributes->get('currentOrganization');
        $branch = $request->attributes->get('currentBranch');

        if ($branch === null) {
            return redirect()->route('branches.index')->with('error', 'لا يوجد فرع متاح لهذا الحساب.');
        }

        $data = CreateInventoryStockCountData::fromValidated(
            $request->validated(),
            (int) $organization->getKey(),
            (int) $branch->getKey(),
            (int) $request->user()->getKey(),
        );
        $count = $createInventoryStockCount->handle($data);

        $message = $count->wasRecentlyCreated
            ? 'تم حفظ الجرد وتسوية فروق المخزون داخل معاملة واحدة.'
            : 'سبق تسجيل عملية الجرد نفسها؛ لم يتم تطبيق التسوية مرة ثانية.';

        return redirect()->route('inventory.stock-counts.index')->with('success', $message);
    }
}
