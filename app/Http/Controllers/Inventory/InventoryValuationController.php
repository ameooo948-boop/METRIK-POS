<?php

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Organization;
use App\Models\Product;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class InventoryValuationController extends Controller
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

        $filters = $this->validatedFilters($request);
        $organizationId = (int) $organization->getKey();
        $branchId = (int) $branch->getKey();

        $products = Product::query()
            ->forOrganization($organizationId)
            ->with([
                'category' => fn ($query) => $query->where('organization_id', $organizationId)->select(['id', 'organization_id', 'name']),
                'baseUnit:id,code,name_ar,name_en',
                'barcodes' => fn ($query) => $query->where('organization_id', $organizationId)->select(['id', 'organization_id', 'product_id', 'barcode', 'unit_id']),
            ])
            ->withSum(['inventoryBalances as branch_quantity' => fn ($query) => $query
                ->where('organization_id', $organizationId)
                ->where('branch_id', $branchId)], 'quantity')
            ->when($filters['search'] !== '', fn ($query) => $query->search($filters['search']))
            ->when($filters['categoryId'] !== null, fn ($query) => $query->where('category_id', $filters['categoryId']))
            ->orderBy('name')
            ->paginate(20)
            ->withQueryString();

        $products->through(function (Product $product): array {
            $quantity = number_format((float) ($product->branch_quantity ?? 0), 3, '.', '');
            $purchasePrice = (string) $product->purchase_price;
            $sellingPrice = (string) $product->selling_price;
            $costValue = $this->valueAtPrice($quantity, $purchasePrice);
            $retailValue = $this->valueAtPrice($quantity, $sellingPrice);

            return [
                'id' => (int) $product->getKey(),
                'sku' => (string) $product->sku,
                'name' => (string) $product->name,
                'categoryName' => $product->category?->name ?? '—',
                'unitName' => $product->baseUnit?->name_ar ?? $product->baseUnit?->name_en ?? 'وحدة',
                'barcode' => $product->barcodes->first()?->barcode,
                'quantity' => $quantity,
                'purchasePrice' => $purchasePrice,
                'sellingPrice' => $sellingPrice,
                'stockCostValue' => $costValue,
                'stockRetailValue' => $retailValue,
                'isActive' => (bool) $product->is_active,
            ];
        });

        $summary = $this->summaryQuery($organizationId, $branchId, $filters)
            ->selectRaw('COUNT(*) AS product_count')
            ->selectRaw('COALESCE(SUM(CASE WHEN COALESCE(branch_stock.quantity, 0) <= 0 THEN 1 ELSE 0 END), 0) AS out_of_stock_count')
            ->selectRaw('COALESCE(SUM(CASE WHEN COALESCE(branch_stock.quantity, 0) > 0 THEN ROUND(branch_stock.quantity * products.purchase_price, 2) ELSE 0 END), 0) AS stock_cost_value')
            ->selectRaw('COALESCE(SUM(CASE WHEN COALESCE(branch_stock.quantity, 0) > 0 THEN ROUND(branch_stock.quantity * products.selling_price, 2) ELSE 0 END), 0) AS stock_retail_value')
            ->first();

        $costValue = (float) ($summary->stock_cost_value ?? 0);
        $retailValue = (float) ($summary->stock_retail_value ?? 0);

        return Inertia::render('Inventory/Valuation', [
            'organization' => $organization->only(['id', 'name', 'currency']),
            'currentBranch' => $branch->only(['id', 'name', 'code']),
            'products' => $products,
            'categories' => Category::query()
                ->where('organization_id', $organizationId)
                ->orderBy('name')
                ->get(['id', 'name']),
            'filters' => $filters,
            'summary' => [
                'productCount' => (int) ($summary->product_count ?? 0),
                'outOfStockCount' => (int) ($summary->out_of_stock_count ?? 0),
                'stockCostValue' => number_format($costValue, 2, '.', ''),
                'stockRetailValue' => number_format($retailValue, 2, '.', ''),
                'potentialGrossProfit' => number_format($retailValue - $costValue, 2, '.', ''),
            ],
        ]);
    }

    public function export(Request $request): StreamedResponse|RedirectResponse
    {
        /** @var Organization $organization */
        $organization = $request->attributes->get('currentOrganization');
        $this->authorize('manageInventory', $organization);

        $branch = $request->attributes->get('currentBranch');
        if ($branch === null) {
            return redirect()->route('branches.index')->with('error', 'لا يوجد فرع متاح لهذا الحساب.');
        }

        $filters = $this->validatedFilters($request);
        $organizationId = (int) $organization->getKey();
        $branchId = (int) $branch->getKey();

        return response()->streamDownload(function () use ($organizationId, $branchId, $filters): void {
            $output = fopen('php://output', 'wb');
            if ($output === false) {
                return;
            }

            fwrite($output, "\xEF\xBB\xBF");
            fputcsv($output, [
                'sku', 'name', 'category', 'base_unit', 'barcode', 'branch_quantity',
                'purchase_price', 'selling_price', 'stock_cost_value', 'stock_retail_value', 'is_active',
            ]);

            $query = Product::query()
                ->forOrganization($organizationId)
                ->with([
                    'category' => fn ($builder) => $builder->where('organization_id', $organizationId)->select(['id', 'organization_id', 'name']),
                    'baseUnit:id,code,name_ar,name_en',
                    'barcodes' => fn ($builder) => $builder->where('organization_id', $organizationId)->select(['id', 'organization_id', 'product_id', 'barcode', 'unit_id']),
                ])
                ->withSum(['inventoryBalances as branch_quantity' => fn ($builder) => $builder
                    ->where('organization_id', $organizationId)
                    ->where('branch_id', $branchId)], 'quantity')
                ->when($filters['search'] !== '', fn ($builder) => $builder->search($filters['search']))
                ->when($filters['categoryId'] !== null, fn ($builder) => $builder->where('category_id', $filters['categoryId']))
                ->reorder('id');

            $query->chunk(500, function ($products) use ($output): void {
                foreach ($products as $product) {
                    $quantity = number_format((float) ($product->branch_quantity ?? 0), 3, '.', '');
                    $row = [
                        $product->sku,
                        $product->name,
                        $product->category?->name,
                        $product->baseUnit?->code,
                        $product->barcodes->first()?->barcode,
                        $quantity,
                        $product->purchase_price,
                        $product->selling_price,
                        $this->valueAtPrice($quantity, (string) $product->purchase_price),
                        $this->valueAtPrice($quantity, (string) $product->selling_price),
                        $product->is_active ? '1' : '0',
                    ];

                    fputcsv($output, array_map(fn ($value): string => $this->safeCsvCell($value), $row));
                }
            });

            fclose($output);
        }, 'metrik-inventory-valuation-'.now()->format('Ymd-His').'.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'private, no-store, max-age=0, must-revalidate',
        ]);
    }

    /** @return array{search: string, categoryId: int|null} */
    private function validatedFilters(Request $request): array
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'category_id' => ['nullable', 'integer', 'min:1'],
        ]);

        $search = trim((string) ($validated['search'] ?? ''));
        $categoryId = isset($validated['category_id']) ? (int) $validated['category_id'] : null;

        return ['search' => mb_substr($search, 0, 100), 'categoryId' => $categoryId];
    }

    private function summaryQuery(int $organizationId, int $branchId, array $filters): QueryBuilder
    {
        return DB::table('products')
            ->leftJoin('inventory_balances as branch_stock', function ($join) use ($organizationId, $branchId): void {
                $join->on('branch_stock.product_id', '=', 'products.id')
                    ->where('branch_stock.organization_id', '=', $organizationId)
                    ->where('branch_stock.branch_id', '=', $branchId);
            })
            ->where('products.organization_id', $organizationId)
            ->whereNull('products.deleted_at')
            ->when($filters['categoryId'] !== null, fn ($query) => $query->where('products.category_id', $filters['categoryId']))
            ->when($filters['search'] !== '', function ($query) use ($filters): void {
                $term = $filters['search'];
                $query->where(function ($nested) use ($term): void {
                    $nested->where('products.name', 'like', '%'.$term.'%')
                        ->orWhere('products.sku', 'like', '%'.$term.'%')
                        ->orWhereExists(function ($barcodeQuery) use ($term): void {
                            $barcodeQuery->selectRaw('1')
                                ->from('product_barcodes as exact_barcodes')
                                ->whereColumn('exact_barcodes.product_id', 'products.id')
                                ->whereColumn('exact_barcodes.organization_id', 'products.organization_id')
                                ->where('exact_barcodes.barcode', 'like', '%'.$term.'%');
                        });
                });
            });
    }

    private function valueAtPrice(string $quantity, string $unitPrice): string
    {
        return number_format(round(max(0, (float) $quantity) * max(0, (float) $unitPrice), 2, PHP_ROUND_HALF_UP), 2, '.', '');
    }

    private function safeCsvCell(mixed $value): string
    {
        $value = (string) ($value ?? '');

        if (preg_match('/^[\x09\x0D\x20]*[=+@\-]/u', $value) === 1) {
            return "'".$value;
        }

        return $value;
    }
}
