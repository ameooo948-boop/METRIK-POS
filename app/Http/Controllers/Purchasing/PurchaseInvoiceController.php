<?php

namespace App\Http\Controllers\Purchasing;

use App\Data\Purchasing\CreatePurchaseInvoiceData;
use App\Http\Controllers\Controller;
use App\Http\Requests\Purchasing\StorePurchaseInvoiceRequest;
use App\Models\Organization;
use App\Models\Product;
use App\Models\PurchaseInvoice;
use App\Models\Supplier;
use App\Services\Purchasing\CreatePurchaseInvoice;
use App\Support\ProductUnitOptions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PurchaseInvoiceController extends Controller
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

        $invoices = PurchaseInvoice::query()
            ->forOrganization((int) $organization->getKey())
            ->where('branch_id', $branch->getKey())
            ->with([
                'supplier:id,name',
                'branch:id,name',
                'createdBy:id,name',
                'items:id,organization_id,purchase_invoice_id,product_id,unit_id,product_sku_snapshot,product_name_snapshot,unit_name_snapshot,unit_conversion_factor_snapshot,quantity,quantity_in_base,unit_cost,line_total',
            ])
            ->latest('id')
            ->paginate(15)
            ->withQueryString()
            ->through(static fn (PurchaseInvoice $invoice): array => [
                'id' => $invoice->getKey(),
                'invoiceNumber' => $invoice->invoice_number,
                'supplierInvoiceNumber' => $invoice->supplier_invoice_number,
                'supplier' => $invoice->supplier?->only(['id', 'name']),
                'branch' => $invoice->branch?->only(['id', 'name']),
                'createdBy' => $invoice->createdBy?->only(['id', 'name']),
                'status' => $invoice->status,
                'total' => $invoice->total,
                'paidAmount' => $invoice->paid_amount,
                'dueAmount' => $invoice->due_amount,
                'receivedAt' => $invoice->received_at?->format('Y-m-d H:i'),
                'items' => $invoice->items->map(static fn ($item): array => [
                    'id' => $item->getKey(),
                    'name' => $item->product_name_snapshot,
                    'sku' => $item->product_sku_snapshot,
                    'unitName' => $item->unit_name_snapshot,
                    'quantity' => $item->quantity,
                    'unitCost' => $item->unit_cost,
                    'lineTotal' => $item->line_total,
                ])->values(),
            ]);

        $suppliers = Supplier::query()
            ->forOrganization((int) $organization->getKey())
            ->active()
            ->orderBy('name')
            ->get(['id', 'name', 'code']);

        $products = Product::query()
            ->forOrganization((int) $organization->getKey())
            ->active()
            ->with(['baseUnit:id,code,name_ar,name_en', 'unitConversions.unit:id,code,name_ar,name_en'])
            ->orderBy('name')
            ->get(['id', 'organization_id', 'base_unit_id', 'sku', 'name', 'purchase_price', 'selling_price'])
            ->map(static fn (Product $product): array => [
                'id' => $product->getKey(),
                'sku' => $product->sku,
                'name' => $product->name,
                'purchasePrice' => (string) $product->purchase_price,
                'baseUnit' => $product->baseUnit?->only(['id', 'name_ar', 'name_en']),
                'units' => ProductUnitOptions::options($product),
            ])->values();

        return Inertia::render('Purchasing/Index', [
            'organization' => $organization->only(['id', 'name', 'currency']),
            'currentBranch' => $branch->only(['id', 'name', 'code']),
            'invoices' => $invoices,
            'suppliers' => $suppliers,
            'products' => $products,
            'canManagePurchases' => $request->user()->can('managePurchases', $organization),
            'flashMessage' => $request->session()->get('success'),
            'errorMessage' => $request->session()->get('error'),
        ]);
    }

    public function store(StorePurchaseInvoiceRequest $request, CreatePurchaseInvoice $createPurchaseInvoice): RedirectResponse
    {
        /** @var Organization $organization */
        $organization = $request->attributes->get('currentOrganization');
        $branch = $request->attributes->get('currentBranch');

        if ($branch === null) {
            return redirect()->route('branches.index')->with('error', 'لا يوجد فرع متاح لهذا الحساب.');
        }

        $invoice = $createPurchaseInvoice->handle(CreatePurchaseInvoiceData::fromValidated(
            $request->validated(),
            (int) $organization->getKey(),
            (int) $branch->getKey(),
            (int) $request->user()->getKey(),
        ));

        return redirect()->route('purchase-invoices.index')->with('success', 'تم استلام الفاتورة '.$invoice->invoice_number.' وتحديث المخزون ودفتر المورد بنجاح.');
    }
}
