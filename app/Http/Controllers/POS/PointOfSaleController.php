<?php

namespace App\Http\Controllers\POS;

use App\Enums\OrganizationRole;
use App\Http\Controllers\Controller;
use App\Models\CashShift;
use App\Models\Organization;
use App\Models\Product;
use App\Models\Sale;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PointOfSaleController extends Controller
{
    public function index(Request $request): Response|RedirectResponse
    {
        /** @var Organization $organization */
        $organization = $request->attributes->get('currentOrganization');
        $this->authorize('usePos', $organization);

        $branch = $request->attributes->get('currentBranch');
        if ($branch === null) {
            return redirect()->route('branches.index')->with('error', 'لا يوجد فرع متاح لهذا الحساب.');
        }

        $userId = (int) $request->user()->getKey();
        $shift = CashShift::query()
            ->forOrganization((int) $organization->getKey())
            ->where('branch_id', $branch->getKey())
            ->where('cashier_id', $userId)
            ->where('status', 'open')
            ->first();

        $role = OrganizationRole::tryFrom((string) $request->attributes->get('currentOrganizationRole'));
        $sales = Sale::query()
            ->forOrganization((int) $organization->getKey())
            ->where('branch_id', $branch->getKey())
            ->when($role === OrganizationRole::CASHIER, fn ($query) => $query->where('cashier_id', $userId))
            ->with('cashier:id,name')
            ->latest('id')
            ->limit(8)
            ->get()
            ->map(static fn (Sale $sale): array => [
                'id' => $sale->getKey(),
                'invoiceNumber' => $sale->invoice_number,
                'total' => $sale->total,
                'soldAt' => $sale->sold_at?->format('Y-m-d H:i'),
                'cashierName' => $sale->cashier?->name,
            ])->values();

        return Inertia::render('POS/Index', [
            'organization' => $organization->only(['id', 'name', 'currency']),
            'currentBranch' => $branch->only(['id', 'name', 'code']),
            'shift' => $shift ? [
                'id' => $shift->getKey(),
                'openingAmount' => $shift->opening_amount,
                'expectedCash' => $shift->expectedCashAmount(),
                'openedAt' => $shift->opened_at?->format('Y-m-d H:i'),
            ] : null,
            'initialProducts' => $this->searchProductsData((int) $organization->getKey(), (int) $branch->getKey(), '', 24),
            'recentSales' => $sales,
            'canViewSales' => $request->user()->can('viewSales', $organization),
            'flashMessage' => $request->session()->get('success'),
            'errorMessage' => $request->session()->get('error'),
        ]);
    }

    public function searchProducts(Request $request): JsonResponse
    {
        /** @var Organization $organization */
        $organization = $request->attributes->get('currentOrganization');
        $this->authorize('usePos', $organization);

        $branch = $request->attributes->get('currentBranch');
        abort_if($branch === null, 404);

        $validated = $request->validate(['q' => ['nullable', 'string', 'max:120']]);
        $products = $this->searchProductsData(
            (int) $organization->getKey(),
            (int) $branch->getKey(),
            (string) ($validated['q'] ?? ''),
            40,
        );

        return response()->json(['data' => $products]);
    }

    /** @return list<array<string, mixed>> */
    private function searchProductsData(int $organizationId, int $branchId, string $term, int $limit): array
    {
        return Product::query()
            ->forOrganization($organizationId)
            ->active()
            ->search($term)
            ->with([
                'baseUnit:id,name_ar,name_en',
                'barcodes:id,product_id,barcode',
                'inventoryBalances' => fn ($query) => $query->where('branch_id', $branchId),
            ])
            ->orderBy('name')
            ->limit($limit)
            ->get()
            ->map(static function (Product $product): array {
                $balance = $product->inventoryBalances->first();

                return [
                    'id' => $product->getKey(),
                    'sku' => $product->sku,
                    'name' => $product->name,
                    'sellingPrice' => $product->selling_price,
                    'stock' => $balance?->quantity ?? '0.000',
                    'unitName' => $product->baseUnit?->name_ar ?? $product->baseUnit?->name_en ?? 'وحدة',
                    'barcodes' => $product->barcodes->pluck('barcode')->values(),
                ];
            })->values()->all();
    }
}
