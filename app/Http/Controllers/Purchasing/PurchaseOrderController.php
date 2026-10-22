<?php

namespace App\Http\Controllers\Purchasing;

use App\Http\Controllers\Controller;
use App\Http\Requests\Purchasing\ReceivePurchaseOrderRequest;
use App\Http\Requests\Purchasing\StorePurchaseOrderRequest;
use App\Models\Organization;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Supplier;
use App\Services\Purchasing\CreatePurchaseOrder;
use App\Services\Purchasing\CreatePurchaseInvoice;
use App\Services\Purchasing\ReceivePurchaseOrder;
use App\Support\ProductUnitOptions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class PurchaseOrderController extends Controller
{
    public function index(Request $request): Response|RedirectResponse
    {
        /** @var Organization $organization */
        $organization = $request->attributes->get('currentOrganization');
        $this->authorize('viewPurchases', $organization);
        $branch = $request->attributes->get('currentBranch');
        if ($branch === null) {
            return redirect()->route('branches.index')->with('error', 'لا يوجد فرع متاح لهذا الحساب.');
        }

        $orders = PurchaseOrder::query()->forOrganization((int) $organization->getKey())
            ->where('branch_id', $branch->getKey())
            ->with(['supplier:id,name', 'createdBy:id,name', 'items:id,organization_id,purchase_order_id,product_id,unit_id,product_sku_snapshot,product_name_snapshot,unit_name_snapshot,unit_conversion_factor_snapshot,quantity_ordered,quantity_received,unit_cost,line_total', 'invoices:id,purchase_order_id,invoice_number,total,received_at'])
            ->latest('id')->paginate(12)->withQueryString()->through(static function (PurchaseOrder $order): array {
                return [
                    'id' => $order->getKey(),
                    'orderNumber' => $order->order_number,
                    'status' => $order->status,
                    'expectedAt' => $order->expected_at?->format('Y-m-d'),
                    'orderedAt' => $order->ordered_at?->format('Y-m-d H:i'),
                    'receivedAt' => $order->received_at?->format('Y-m-d H:i'),
                    'subtotal' => $order->subtotal,
                    'notes' => $order->notes,
                    'supplier' => $order->supplier?->only(['id', 'name']),
                    'createdBy' => $order->createdBy?->only(['id', 'name']),
                    'items' => $order->items->map(static fn (PurchaseOrderItem $item): array => [
                        'id' => $item->getKey(),
                        'productId' => $item->product_id,
                        'name' => $item->product_name_snapshot,
                        'sku' => $item->product_sku_snapshot,
                        'unitId' => $item->unit_id,
                        'unitName' => $item->unit_name_snapshot,
                        'factorToBase' => $item->unit_conversion_factor_snapshot,
                        'quantityOrdered' => $item->quantity_ordered,
                        'quantityReceived' => $item->quantity_received,
                        'quantityRemaining' => number_format(max(0, (float) $item->quantity_ordered - (float) $item->quantity_received), 3, '.', ''),
                        'unitCost' => $item->unit_cost,
                        'lineTotal' => $item->line_total,
                    ])->values(),
                    'receipts' => $order->invoices->map(static fn ($invoice): array => [
                        'id' => $invoice->getKey(), 'invoiceNumber' => $invoice->invoice_number,
                        'total' => $invoice->total, 'receivedAt' => $invoice->received_at?->format('Y-m-d H:i'),
                    ])->values(),
                ];
            });

        $suppliers = Supplier::query()->forOrganization((int) $organization->getKey())->active()->orderBy('name')->get(['id', 'name', 'code']);
        $products = Product::query()->forOrganization((int) $organization->getKey())->active()
            ->with(['baseUnit:id,code,name_ar,name_en', 'unitConversions.unit:id,code,name_ar,name_en'])
            ->orderBy('name')->get(['id', 'organization_id', 'base_unit_id', 'sku', 'name', 'purchase_price', 'selling_price'])
            ->map(static fn (Product $product): array => [
                'id' => $product->getKey(), 'sku' => $product->sku, 'name' => $product->name,
                'purchasePrice' => (string) $product->purchase_price,
                'baseUnit' => $product->baseUnit?->only(['id', 'name_ar', 'name_en']),
                'units' => ProductUnitOptions::options($product),
            ])->values();

        return Inertia::render('Purchasing/Orders', [
            'organization' => $organization->only(['id', 'name', 'currency']),
            'currentBranch' => $branch->only(['id', 'name', 'code']),
            'orders' => $orders,
            'suppliers' => $suppliers,
            'products' => $products,
            'canManagePurchases' => $request->user()->can('managePurchases', $organization),
            'flashMessage' => $request->session()->get('success'),
            'errorMessage' => $request->session()->get('error'),
        ]);
    }

    public function store(StorePurchaseOrderRequest $request, CreatePurchaseOrder $create): RedirectResponse
    {
        /** @var Organization $organization */
        $organization = $request->attributes->get('currentOrganization');
        $branch = $request->attributes->get('currentBranch');
        if ($branch === null) {
            return redirect()->route('branches.index')->with('error', 'لا يوجد فرع متاح لهذا الحساب.');
        }
        $order = $create->handle($request->validated(), (int) $organization->getKey(), (int) $branch->getKey(), (int) $request->user()->getKey());
        return redirect()->route('purchase-orders.index')->with('success', 'تم إنشاء أمر الشراء '.$order->order_number.' دون تغيير المخزون.');
    }

    public function receive(
        ReceivePurchaseOrderRequest $request,
        int $purchaseOrder,
        ReceivePurchaseOrder $receive,
        CreatePurchaseInvoice $createPurchaseInvoice,
    ): RedirectResponse {
        /** @var Organization $organization */
        $organization = $request->attributes->get('currentOrganization');
        $branch = $request->attributes->get('currentBranch');
        if ($branch === null) {
            return redirect()->route('branches.index')->with('error', 'لا يوجد فرع متاح لهذا الحساب.');
        }
        $order = $receive->handle($purchaseOrder, $request->validated(), (int) $organization->getKey(), (int) $branch->getKey(), (int) $request->user()->getKey(), $createPurchaseInvoice);
        return redirect()->route('purchase-orders.index')->with('success', 'تم تسجيل الاستلام وتحديث المخزون وحساب المورد. حالة الأمر الحالية: '.$order->status.'.');
    }

    public function cancel(Request $request, int $purchaseOrder): RedirectResponse
    {
        /** @var Organization $organization */
        $organization = $request->attributes->get('currentOrganization');
        $this->authorize('managePurchases', $organization);
        $branch = $request->attributes->get('currentBranch');
        if ($branch === null) {
            return redirect()->route('branches.index')->with('error', 'لا يوجد فرع متاح لهذا الحساب.');
        }

        DB::transaction(function () use ($organization, $branch, $purchaseOrder): void {
            $order = PurchaseOrder::query()->forOrganization((int) $organization->getKey())->where('branch_id', $branch->getKey())
                ->whereKey($purchaseOrder)->lockForUpdate()->firstOrFail();
            if ($order->status !== 'ordered') {
                throw ValidationException::withMessages(['order' => 'يمكن إلغاء أمر الشراء قبل أول استلام فقط.']);
            }
            $hasReceived = $order->items()->where('quantity_received', '>', '0')->exists();
            if ($hasReceived) {
                throw ValidationException::withMessages(['order' => 'لا يمكن إلغاء أمر تم استلام جزء منه.']);
            }
            $order->status = 'cancelled';
            $order->save();
        });

        return redirect()->route('purchase-orders.index')->with('success', 'تم إلغاء أمر الشراء دون التأثير على المخزون.');
    }
}
