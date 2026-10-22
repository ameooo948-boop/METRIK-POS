<?php

namespace App\Http\Controllers\Sales;

use App\Enums\OrganizationRole;
use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\Sale;
use App\Models\SaleReturnItem;
use App\Support\DecimalMoney;
use App\Support\DecimalQuantity;
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
                'id', 'organization_id', 'branch_id', 'cashier_id', 'customer_id',
                'invoice_number', 'total', 'status', 'return_status', 'sold_at',
            ])
            ->with(['cashier:id,name', 'customer:id,name,phone'])
            ->latest('id')
            ->paginate(20)
            ->withQueryString()
            ->through(static fn (Sale $sale): array => [
                'id' => $sale->getKey(),
                'invoiceNumber' => $sale->invoice_number,
                'cashierName' => $sale->cashier?->name,
                'customerName' => $sale->customer?->name,
                'total' => $sale->total,
                'status' => $sale->status,
                'returnStatus' => $sale->return_status ?? 'none',
                'soldAt' => $sale->sold_at?->format('Y-m-d H:i'),
            ]);

        return Inertia::render('Sales/Index', [
            'organization' => $organization->only(['id', 'name', 'currency']),
            'currentBranch' => $branch->only(['id', 'name', 'code']),
            'sales' => $sales,
        ]);
    }

    public function receipt(Request $request, int $saleId): Response
    {
        /** @var Organization $organization */
        $organization = $request->attributes->get('currentOrganization');
        /** @var Sale $sale */
        $sale = Sale::query()
            ->forOrganization((int) $organization->getKey())
            ->with([
                'branch:id,name,code',
                'cashier:id,name',
                'customer:id,name,phone',
                'items:id,organization_id,sale_id,product_id,promotion_id,promotion_name_snapshot,discount_amount,product_sku_snapshot,product_name_snapshot,unit_name_snapshot,quantity,unit_price,line_total',
                'payments:id,organization_id,sale_id,payment_method,amount,payment_reference',
            ])
            ->findOrFail($saleId);

        $this->authorize('view', $sale);

        $paidCents = $sale->payments->sum(static fn ($payment): int => DecimalMoney::toCents((string) $payment->amount));
        $remainingCents = max(0, DecimalMoney::toCents((string) $sale->total) - $paidCents);

        return Inertia::render('Sales/Receipt', [
            'organization' => $organization->only(['id', 'name', 'currency']),
            'sale' => [
                'id' => $sale->getKey(),
                'invoiceNumber' => $sale->invoice_number,
                'status' => $sale->status,
                'returnStatus' => $sale->return_status ?? 'none',
                'soldAt' => $sale->sold_at?->format('Y-m-d H:i:s'),
                'total' => $sale->total,
                'subtotal' => $sale->subtotal,
                'discountTotal' => $sale->discount_total,
                'promotionCode' => $sale->promotion_code_snapshot,
                'manualDiscountPercent' => $sale->manual_discount_percent ?? '0.00',
                'taxTotal' => $sale->tax_total,
                'branch' => $sale->branch?->only(['id', 'name', 'code']),
                'cashier' => $sale->cashier?->only(['id', 'name']),
                'customer' => $sale->customer?->only(['id', 'name', 'phone']),
                'items' => $sale->items->map(static fn ($item): array => [
                    'id' => $item->getKey(),
                    'sku' => $item->product_sku_snapshot,
                    'name' => $item->product_name_snapshot,
                    'unitName' => $item->unit_name_snapshot,
                    'quantity' => $item->quantity,
                    'unitPrice' => $item->unit_price,
                    'discountAmount' => $item->discount_amount,
                    'promotionName' => $item->promotion_name_snapshot,
                    'lineTotal' => $item->line_total,
                ])->values(),
                'payments' => $sale->payments->map(static fn ($payment): array => [
                    'id' => $payment->getKey(),
                    'method' => $payment->payment_method,
                    'amount' => $payment->amount,
                    'reference' => $payment->payment_reference,
                ])->values(),
                'paidTotal' => DecimalMoney::fromCents($paidCents),
                'remainingTotal' => DecimalMoney::fromCents($remainingCents),
            ],
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
                'customer:id,name,phone',
                'items:id,organization_id,sale_id,product_id,promotion_id,promotion_name_snapshot,discount_amount,product_sku_snapshot,product_name_snapshot,unit_name_snapshot,quantity,unit_price,unit_cost_snapshot,line_total',
                'payments:id,organization_id,sale_id,payment_method,amount,payment_reference',
            ])
            ->findOrFail($saleId);

        $this->authorize('view', $sale);
        $sale->load(['returns.items']);

        $returnedQuantities = SaleReturnItem::query()
            ->where('organization_id', $organization->getKey())
            ->whereIn('sale_item_id', $sale->items->pluck('id'))
            ->whereHas('saleReturn', fn ($query) => $query->where('status', 'completed'))
            ->selectRaw('sale_item_id, COALESCE(SUM(quantity), 0) AS returned_quantity')
            ->groupBy('sale_item_id')
            ->pluck('returned_quantity', 'sale_item_id');

        $currentBranch = $request->attributes->get('currentBranch');
        $canCreateReturn = $currentBranch !== null
            && (int) $currentBranch->getKey() === (int) $sale->branch_id
            && $request->user()->can('createReturn', $sale)
            && $sale->status === 'paid'
            && ($sale->return_status ?? 'none') !== 'returned';

        return Inertia::render('Sales/Show', [
            'organization' => $organization->only(['id', 'name', 'currency']),
            'sale' => [
                'id' => $sale->getKey(),
                'invoiceNumber' => $sale->invoice_number,
                'status' => $sale->status,
                'returnStatus' => $sale->return_status ?? 'none',
                'soldAt' => $sale->sold_at?->format('Y-m-d H:i:s'),
                'total' => $sale->total,
                'subtotal' => $sale->subtotal,
                'discountTotal' => $sale->discount_total,
                'promotionCode' => $sale->promotion_code_snapshot,
                'manualDiscountPercent' => $sale->manual_discount_percent ?? '0.00',
                'taxTotal' => $sale->tax_total,
                'branch' => $sale->branch?->only(['id', 'name', 'code']),
                'cashier' => $sale->cashier?->only(['id', 'name']),
                'customer' => $sale->customer?->only(['id', 'name', 'phone']),
                'items' => $sale->items->map(static fn ($item): array => [
                    'id' => $item->getKey(),
                    'sku' => $item->product_sku_snapshot,
                    'name' => $item->product_name_snapshot,
                    'unitName' => $item->unit_name_snapshot,
                    'quantity' => $item->quantity,
                    'availableQuantity' => DecimalQuantity::fromMilli(max(0, DecimalQuantity::toMilli((string) $item->quantity) - DecimalQuantity::toMilli((string) ($returnedQuantities->get($item->getKey()) ?? '0.000')))),
                    'unitPrice' => $item->unit_price,
                    'discountAmount' => $item->discount_amount,
                    'promotionName' => $item->promotion_name_snapshot,
                    'lineTotal' => $item->line_total,
                ])->values(),
                'payments' => $sale->payments->map(static fn ($payment): array => [
                    'id' => $payment->getKey(),
                    'method' => $payment->payment_method,
                    'amount' => $payment->amount,
                    'reference' => $payment->payment_reference,
                ])->values(),
                'returns' => $sale->returns->where('status', 'completed')->sortByDesc('id')->values()->map(static fn ($saleReturn): array => [
                    'id' => $saleReturn->getKey(),
                    'number' => $saleReturn->return_number,
                    'total' => $saleReturn->total,
                    'refundMethod' => $saleReturn->refund_method,
                    'refundReference' => $saleReturn->refund_reference,
                    'reason' => $saleReturn->reason,
                    'returnedAt' => $saleReturn->returned_at?->format('Y-m-d H:i:s'),
                    'items' => $saleReturn->items->map(static fn ($item): array => [
                        'name' => $item->product_name_snapshot,
                        'quantity' => $item->quantity,
                        'total' => $item->line_total,
                        'restocked' => (bool) $item->restock,
                    ])->values(),
                ]),
            ],
            'canCreateReturn' => $canCreateReturn,
        ]);
    }
}
