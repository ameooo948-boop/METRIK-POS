<?php

namespace App\Http\Controllers\Sales;

use App\Enums\OrganizationRole;
use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\Sale;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SaleController extends Controller
{
    public function index(Request $request): Response
    {
        /** @var Organization $organization */
        $organization = $request->attributes->get('currentOrganization');
        $this->authorize('viewSales', $organization);

        $branch = $request->attributes->get('currentBranch');
        abort_if($branch === null, 404);

        $role = OrganizationRole::tryFrom((string) $request->attributes->get('currentOrganizationRole'));
        $sales = Sale::query()
            ->forOrganization((int) $organization->getKey())
            ->where('branch_id', $branch->getKey())
            ->when($role === OrganizationRole::CASHIER, fn ($query) => $query->where('cashier_id', $request->user()->getKey()))
            ->select([
                'id', 'organization_id', 'branch_id', 'cashier_id',
                'invoice_number', 'total', 'status', 'sold_at',
            ])
            ->with('cashier:id,name')
            ->latest('id')
            ->paginate(20)
            ->withQueryString()
            ->through(static fn (Sale $sale): array => [
                'id' => $sale->getKey(),
                'invoiceNumber' => $sale->invoice_number,
                'cashierName' => $sale->cashier?->name,
                'total' => $sale->total,
                'status' => $sale->status,
                'soldAt' => $sale->sold_at?->format('Y-m-d H:i'),
            ]);

        return Inertia::render('Sales/Index', [
            'organization' => $organization->only(['id', 'name', 'currency']),
            'currentBranch' => $branch->only(['id', 'name', 'code']),
            'sales' => $sales,
        ]);
    }

    public function show(Request $request, int $saleId): Response
    {
        /** @var Organization $organization */
        $organization = $request->attributes->get('currentOrganization');
        /** @var Sale $sale */
        $sale = Sale::query()
            ->forOrganization((int) $organization->getKey())
            ->with([
                'branch:id,name,code',
                'cashier:id,name',
                'items:id,organization_id,sale_id,product_id,product_sku_snapshot,product_name_snapshot,unit_name_snapshot,quantity,unit_price,unit_cost_snapshot,line_total',
                'payments:id,organization_id,sale_id,payment_method,amount,payment_reference',
            ])
            ->findOrFail($saleId);

        $this->authorize('view', $sale);

        return Inertia::render('Sales/Show', [
            'organization' => $organization->only(['id', 'name', 'currency']),
            'sale' => [
                'id' => $sale->getKey(),
                'invoiceNumber' => $sale->invoice_number,
                'status' => $sale->status,
                'soldAt' => $sale->sold_at?->format('Y-m-d H:i:s'),
                'total' => $sale->total,
                'subtotal' => $sale->subtotal,
                'discountTotal' => $sale->discount_total,
                'taxTotal' => $sale->tax_total,
                'branch' => $sale->branch?->only(['id', 'name', 'code']),
                'cashier' => $sale->cashier?->only(['id', 'name']),
                'items' => $sale->items->map(static fn ($item): array => [
                    'id' => $item->getKey(),
                    'sku' => $item->product_sku_snapshot,
                    'name' => $item->product_name_snapshot,
                    'unitName' => $item->unit_name_snapshot,
                    'quantity' => $item->quantity,
                    'unitPrice' => $item->unit_price,
                    'lineTotal' => $item->line_total,
                ])->values(),
                'payments' => $sale->payments->map(static fn ($payment): array => [
                    'id' => $payment->getKey(),
                    'method' => $payment->payment_method,
                    'amount' => $payment->amount,
                    'reference' => $payment->payment_reference,
                ])->values(),
            ],
        ]);
    }
}
