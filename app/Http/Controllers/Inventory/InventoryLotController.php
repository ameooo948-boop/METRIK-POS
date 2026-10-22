<?php

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Models\InventoryBalance;
use App\Models\InventoryLot;
use App\Models\InventoryLotMovement;
use App\Models\Organization;
use App\Models\Product;
use App\Support\DecimalQuantity;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class InventoryLotController extends Controller
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
        $search = mb_substr(trim((string) $request->query('search', '')), 0, 100);
        $status = (string) $request->query('status', 'all');
        if (! in_array($status, ['all', 'expired', 'soon', 'healthy', 'depleted'], true)) {
            $status = 'all';
        }
        $timezone = (string) ($organization->timezone ?: config('app.timezone', 'UTC'));
        $todayMoment = now($timezone)->startOfDay();
        $today = $todayMoment->toDateString();
        $soonDate = $todayMoment->copy()->addDays(30)->toDateString();

        $lotsQuery = InventoryLot::query()
            ->with(['product:id,organization_id,sku,name'])
            ->where('organization_id', $organizationId)
            ->where('branch_id', $branchId)
            ->when($search !== '', fn ($query) => $query->where(function ($nested) use ($search): void {
                $nested->where('batch_code', 'like', '%'.$search.'%')
                    ->orWhereHas('product', fn ($product) => $product->where('name', 'like', '%'.$search.'%')->orWhere('sku', 'like', '%'.$search.'%'));
            }))
            ->when($status === 'expired', fn ($query) => $query->whereDate('expires_at', '<', $today)->where('quantity_remaining', '>', 0))
            ->when($status === 'soon', fn ($query) => $query->whereDate('expires_at', '>=', $today)->whereDate('expires_at', '<=', $soonDate)->where('quantity_remaining', '>', 0))
            ->when($status === 'healthy', fn ($query) => $query->whereDate('expires_at', '>', $soonDate)->where('quantity_remaining', '>', 0))
            ->when($status === 'depleted', fn ($query) => $query->where('quantity_remaining', '<=', 0))
            ->orderBy('expires_at')->orderBy('id');

        $lots = $lotsQuery->paginate(20)->withQueryString();
        $lots->through(static function (InventoryLot $lot) use ($today, $soonDate, $timezone, $todayMoment): array {
            $expires = $lot->expires_at->toDateString();
            $remaining = DecimalQuantity::toMilli((string) $lot->quantity_remaining);
            $lotStatus = $remaining <= 0 ? 'depleted' : ($expires < $today ? 'expired' : ($expires <= $soonDate ? 'soon' : 'healthy'));

            return [
                'id' => (int) $lot->getKey(), 'productId' => (int) $lot->product_id,
                'productName' => (string) ($lot->product?->name ?? '—'), 'sku' => (string) ($lot->product?->sku ?? '—'),
                'batchCode' => (string) $lot->batch_code, 'manufacturedAt' => $lot->manufactured_at?->toDateString(),
                'expiresAt' => $expires, 'receivedAt' => $lot->received_at->toDateString(),
                'quantityReceived' => (string) $lot->quantity_received, 'quantityRemaining' => (string) $lot->quantity_remaining,
                'status' => $lotStatus, 'daysUntilExpiry' => (int) $todayMoment->diffInDays(\Illuminate\Support\Carbon::parse($expires, $timezone)->startOfDay(), false),
            ];
        });

        $summaryQuery = InventoryLot::query()->where('organization_id', $organizationId)->where('branch_id', $branchId)->where('quantity_remaining', '>', 0);
        return Inertia::render('Inventory/Lots', [
            'organization' => $organization->only(['id', 'name', 'currency']),
            'currentBranch' => $branch->only(['id', 'name', 'code']),
            'lots' => $lots,
            'products' => Product::query()->where('organization_id', $organizationId)->where('is_active', true)->orderBy('name')->get(['id', 'sku', 'name'])->map(fn (Product $product): array => [
                'id' => (int) $product->getKey(), 'sku' => (string) $product->sku, 'name' => (string) $product->name,
            ]),
            'filters' => ['search' => $search, 'status' => $status],
            'summary' => [
                'expiredLots' => (clone $summaryQuery)->whereDate('expires_at', '<', $today)->count(),
                'expiringSoonLots' => (clone $summaryQuery)->whereDate('expires_at', '>=', $today)->whereDate('expires_at', '<=', $soonDate)->count(),
                'trackedUnits' => number_format((float) (clone $summaryQuery)->sum('quantity_remaining'), 3, '.', ''),
            ],
            'flashMessage' => $request->session()->get('success'), 'errorMessage' => $request->session()->get('error'),
        ]);
    }

    public function movements(Request $request, int $lotId): Response|RedirectResponse
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
        $timezone = (string) ($organization->timezone ?: config('app.timezone', 'UTC'));
        $lot = InventoryLot::query()
            ->with('product:id,organization_id,sku,name')
            ->where('organization_id', $organizationId)
            ->where('branch_id', $branchId)
            ->whereKey($lotId)
            ->firstOrFail();

        $movements = InventoryLotMovement::query()
            ->with('createdBy:id,name')
            ->where('organization_id', $organizationId)
            ->where('branch_id', $branchId)
            ->where('inventory_lot_id', $lot->getKey())
            ->orderByDesc('id')
            ->paginate(30)
            ->withQueryString();
        $movements->through(static fn (InventoryLotMovement $movement): array => [
            'id' => (int) $movement->getKey(),
            'type' => (string) $movement->movement_type,
            'typeLabel' => match ($movement->movement_type) {
                'opening_balance' => 'رصيد افتتاحي عند الترحيل', 'lot_registration' => 'تسجيل تشغيلة',
                'sale' => 'بيع', 'sale_return' => 'مرتجع بيع', 'transfer_out' => 'تحويل صادر',
                'transfer_in' => 'تحويل وارد', 'stock_out' => 'تسوية صرف', 'count_adjustment' => 'تسوية جرد', 'purchase_return' => 'مرتجع للمورد',
                default => (string) $movement->movement_type,
            },
            'quantityDelta' => (string) $movement->quantity_delta,
            'balanceAfter' => (string) $movement->balance_after,
            'reason' => (string) ($movement->reason ?? ''),
            'referenceType' => $movement->reference_type ? class_basename($movement->reference_type) : null,
            'referenceId' => $movement->reference_id ? (int) $movement->reference_id : null,
            'createdBy' => (string) ($movement->createdBy?->name ?? 'النظام'),
            'occurredAt' => $movement->created_at?->timezone($timezone)->format('Y-m-d H:i:s'),
        ]);

        $today = now($timezone)->toDateString();
        $soonDate = now($timezone)->addDays(30)->toDateString();
        $expiresAt = $lot->expires_at->toDateString();
        $remainingMilli = DecimalQuantity::toMilli((string) $lot->quantity_remaining);
        $status = $remainingMilli <= 0 ? 'depleted' : ($expiresAt < $today ? 'expired' : ($expiresAt <= $soonDate ? 'soon' : 'healthy'));

        return Inertia::render('Inventory/LotMovements', [
            'organization' => $organization->only(['id', 'name', 'currency']),
            'currentBranch' => $branch->only(['id', 'name', 'code']),
            'lot' => [
                'id' => (int) $lot->getKey(), 'productName' => (string) ($lot->product?->name ?? '—'),
                'sku' => (string) ($lot->product?->sku ?? '—'), 'batchCode' => (string) $lot->batch_code,
                'manufacturedAt' => $lot->manufactured_at?->toDateString(), 'expiresAt' => $expiresAt,
                'receivedAt' => $lot->received_at->toDateString(), 'quantityReceived' => (string) $lot->quantity_received,
                'quantityRemaining' => (string) $lot->quantity_remaining, 'status' => $status,
            ],
            'movements' => $movements,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        /** @var Organization $organization */
        $organization = $request->attributes->get('currentOrganization');
        $this->authorize('manageInventory', $organization);
        $branch = $request->attributes->get('currentBranch');
        if ($branch === null) {
            return redirect()->route('branches.index')->with('error', 'لا يوجد فرع متاح لهذا الحساب.');
        }

        $timezone = (string) ($organization->timezone ?: config('app.timezone', 'UTC'));
        $today = now($timezone)->toDateString();
        $validated = $request->validate([
            'product_id' => ['required', 'integer', 'min:1'], 'batch_code' => ['required', 'string', 'max:80'],
            'manufactured_at' => ['nullable', 'date', 'before_or_equal:'.$today, 'before_or_equal:expires_at'], 'expires_at' => ['required', 'date'],
            'received_at' => ['required', 'date', 'before_or_equal:'.$today], 'quantity' => ['required', 'numeric', 'gt:0', 'decimal:0,3', 'max:999999999'],
        ]);

        $organizationId = (int) $organization->getKey();
        $branchId = (int) $branch->getKey();
        $product = Product::query()->where('organization_id', $organizationId)->where('is_active', true)->whereKey($validated['product_id'])->firstOrFail();

        DB::transaction(function () use ($validated, $organizationId, $branchId, $product, $request): void {
            $balance = InventoryBalance::query()->where('organization_id', $organizationId)->where('branch_id', $branchId)
                ->where('product_id', $product->getKey())->lockForUpdate()->first();
            if (! $balance) {
                throw ValidationException::withMessages(['quantity' => 'لا يوجد رصيد لهذا المنتج في الفرع. سجّل الرصيد أولًا ثم أضف التشغيلة.']);
            }

            $trackedMilli = DecimalQuantity::toMilli((string) InventoryLot::query()->where('organization_id', $organizationId)
                ->where('branch_id', $branchId)->where('product_id', $product->getKey())->lockForUpdate()->sum('quantity_remaining'));
            $balanceMilli = DecimalQuantity::toMilli((string) $balance->quantity);
            $requestedMilli = DecimalQuantity::toMilli((string) $validated['quantity']);
            $untrackedMilli = max(0, $balanceMilli - $trackedMilli);
            if ($requestedMilli > $untrackedMilli) {
                throw ValidationException::withMessages(['quantity' => 'الكمية أكبر من الرصيد غير الموزع على تشغيلات. المتاح للتسجيل: '.DecimalQuantity::fromMilli($untrackedMilli)]);
            }

            $lot = InventoryLot::query()->create([
                'organization_id' => $organizationId, 'branch_id' => $branchId, 'product_id' => $product->getKey(),
                'created_by' => (int) $request->user()->getKey(), 'batch_code' => trim($validated['batch_code']),
                'manufactured_at' => $validated['manufactured_at'] ?? null, 'expires_at' => $validated['expires_at'],
                'received_at' => $validated['received_at'], 'quantity_received' => $validated['quantity'],
                'quantity_remaining' => $validated['quantity'],
            ]);
            InventoryLotMovement::query()->create([
                'organization_id' => $organizationId, 'branch_id' => $branchId, 'product_id' => $product->getKey(),
                'inventory_lot_id' => $lot->getKey(), 'created_by' => (int) $request->user()->getKey(),
                'movement_type' => 'lot_registration', 'quantity_delta' => $validated['quantity'],
                'balance_after' => $validated['quantity'],
                'reason' => 'توزيع كمية من الرصيد غير المتتبع على التشغيلة دون تغيير إجمالي مخزون الفرع',
            ]);
        });

        return redirect()->route('inventory.lots.index')->with('success', 'تم تسجيل التشغيلة دون تغيير إجمالي رصيد المخزون.');
    }
}
