<?php

namespace App\Http\Controllers\Purchasing;

use App\Data\Purchasing\CreatePurchaseReturnData;
use App\Http\Controllers\Controller;
use App\Http\Requests\Purchasing\StorePurchaseReturnRequest;
use App\Models\InventoryBalance;
use App\Models\Organization;
use App\Models\PurchaseInvoice;
use App\Models\PurchaseReturn;
use App\Models\PurchaseReturnItem;
use App\Services\Purchasing\CreatePurchaseReturn;
use App\Support\DecimalQuantity;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class PurchaseReturnController extends Controller
{
    public function index(Request $request): Response|RedirectResponse
    {
        /** @var Organization $organization */
        $organization = $request->attributes->get('currentOrganization');
        $this->authorize('viewPurchases', $organization);
        $branch = $request->attributes->get('currentBranch');
        if (! $branch) {
            return redirect()->route('branches.index')->with('error', 'لا يوجد فرع متاح لهذا الحساب.');
        }

        $organizationId = (int) $organization->getKey();
        $branchId = (int) $branch->getKey();
        $invoices = PurchaseInvoice::query()
            ->forOrganization($organizationId)
            ->where('branch_id', $branchId)
            ->where('status', 'received')
            ->with(['supplier:id,name', 'items:id,organization_id,purchase_invoice_id,product_id,product_sku_snapshot,product_name_snapshot,unit_name_snapshot,quantity,unit_cost,line_total'])
            ->latest('id')
            ->limit(100)
            ->get();

        $itemIds = $invoices->flatMap(static fn (PurchaseInvoice $invoice) => $invoice->items->pluck('id'))->map(static fn ($id): int => (int) $id)->unique()->values();
        $productIds = $invoices->flatMap(static fn (PurchaseInvoice $invoice) => $invoice->items->pluck('product_id'))->map(static fn ($id): int => (int) $id)->unique()->values();

        $returnedByItem = $itemIds->isEmpty() ? collect() : PurchaseReturnItem::query()
            ->join('purchase_returns', 'purchase_returns.id', '=', 'purchase_return_items.purchase_return_id')
            ->where('purchase_return_items.organization_id', $organizationId)
            ->where('purchase_returns.organization_id', $organizationId)
            ->whereIn('purchase_return_items.purchase_invoice_item_id', $itemIds)
            ->where('purchase_returns.status', 'completed')
            ->selectRaw('purchase_return_items.purchase_invoice_item_id, COALESCE(SUM(purchase_return_items.quantity), 0) AS returned_quantity')
            ->groupBy('purchase_return_items.purchase_invoice_item_id')
            ->pluck('returned_quantity', 'purchase_invoice_item_id');

        $stockByProduct = $productIds->isEmpty() ? collect() : InventoryBalance::query()
            ->where('organization_id', $organizationId)
            ->where('branch_id', $branchId)
            ->whereIn('product_id', $productIds)
            ->pluck('quantity', 'product_id');

        $invoiceOptions = $invoices->map(static function (PurchaseInvoice $invoice) use ($returnedByItem, $stockByProduct): array {
            $items = $invoice->items->map(static function ($item) use ($returnedByItem, $stockByProduct): array {
                $purchasedMilli = DecimalQuantity::toMilli((string) $item->quantity);
                $returnedMilli = DecimalQuantity::toMilli((string) ($returnedByItem->get($item->getKey()) ?? '0.000'));
                $stockMilli = DecimalQuantity::toMilli((string) ($stockByProduct->get($item->product_id) ?? '0.000'));
                $remainingMilli = max(0, $purchasedMilli - $returnedMilli);

                return [
                    'id' => $item->getKey(),
                    'productId' => $item->product_id,
                    'name' => $item->product_name_snapshot,
                    'sku' => $item->product_sku_snapshot,
                    'unitName' => $item->unit_name_snapshot,
                    'quantity' => $item->quantity,
                    'unitCost' => $item->unit_cost,
                    'lineTotal' => $item->line_total,
                    'remainingPurchaseQuantity' => DecimalQuantity::fromMilli($remainingMilli),
                    'availableStock' => DecimalQuantity::fromMilli($stockMilli),
                    'maxReturnQuantity' => DecimalQuantity::fromMilli(min($remainingMilli, $stockMilli)),
                ];
            })->values();

            return [
                'id' => $invoice->getKey(),
                'invoiceNumber' => $invoice->invoice_number,
                'supplier' => $invoice->supplier?->only(['id', 'name']),
                'total' => $invoice->total,
                'paidAmount' => $invoice->paid_amount,
                'returnedAmount' => $invoice->returned_amount ?? '0.00',
                'dueAmount' => $invoice->due_amount,
                'items' => $items,
            ];
        })->filter(static fn (array $invoice): bool => collect($invoice['items'])->contains(static fn (array $item): bool => (float) $item['maxReturnQuantity'] > 0))->values();

        $returns = PurchaseReturn::query()
            ->forOrganization($organizationId)
            ->where('branch_id', $branchId)
            ->with(['supplier:id,name', 'purchaseInvoice:id,invoice_number', 'createdBy:id,name'])
            ->withCount('items')
            ->latest('id')
            ->paginate(12)
            ->withQueryString()
            ->through(static fn (PurchaseReturn $return): array => [
                'id' => $return->getKey(),
                'returnNumber' => $return->return_number,
                'invoiceNumber' => $return->purchaseInvoice?->invoice_number,
                'supplier' => $return->supplier?->only(['id', 'name']),
                'createdBy' => $return->createdBy?->only(['id', 'name']),
                'reason' => $return->reason,
                'itemsCount' => $return->items_count,
                'total' => $return->total,
                'returnedAt' => $return->returned_at?->format('Y-m-d H:i'),
            ]);

        return Inertia::render('Purchasing/Returns', [
            'organization' => $organization->only(['id', 'name', 'currency']),
            'currentBranch' => $branch->only(['id', 'name', 'code']),
            'invoices' => $invoiceOptions,
            'returns' => $returns,
            'canManagePurchases' => $request->user()->can('managePurchases', $organization),
            'flashMessage' => $request->session()->get('success'),
            'errorMessage' => $request->session()->get('error'),
        ]);
    }

    public function store(StorePurchaseReturnRequest $request, CreatePurchaseReturn $createPurchaseReturn, int $purchaseInvoice): RedirectResponse
    {
        /** @var Organization $organization */
        $organization = $request->attributes->get('currentOrganization');
        $branch = $request->attributes->get('currentBranch');
        abort_if(! $branch, 404);

        $return = $createPurchaseReturn->handle(CreatePurchaseReturnData::fromValidated(
            $request->validated(),
            (int) $organization->getKey(),
            (int) $branch->getKey(),
            (int) $request->user()->getKey(),
            $purchaseInvoice,
        ));

        return redirect()->route('purchase-returns.index')
            ->with('success', 'تم تسجيل مرتجع المورد '.$return->return_number.' بقيمة '.$return->total.' ج.م.');
    }
}
