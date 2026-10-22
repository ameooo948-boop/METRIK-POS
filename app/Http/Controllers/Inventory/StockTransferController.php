<?php

namespace App\Http\Controllers\Inventory;

use App\Data\Inventory\CreateStockTransferData;
use App\Http\Controllers\Controller;
use App\Http\Requests\Inventory\StoreStockTransferRequest;
use App\Models\InventoryBalance;
use App\Models\Organization;
use App\Models\StockTransfer;
use App\Services\Inventory\CreateStockTransfer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class StockTransferController extends Controller
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

        $products = InventoryBalance::query()
            ->where('organization_id', $organizationId)
            ->where('branch_id', $branchId)
            ->where('quantity', '>', 0)
            ->whereHas('product', fn ($query) => $query->where('is_active', true))
            ->with(['product.baseUnit'])
            ->orderBy('product_id')
            ->get()
            ->map(static function (InventoryBalance $balance): array {
                $product = $balance->product;

                return [
                    'id' => (int) $product->getKey(),
                    'sku' => $product->sku,
                    'name' => $product->name,
                    'unitName' => $product->baseUnit?->name_ar ?? $product->baseUnit?->name_en ?? 'وحدة أساسية',
                    'quantity' => (string) $balance->quantity,
                ];
            })->values();

        $transfers = StockTransfer::query()
            ->forOrganization($organizationId)
            ->where(fn ($query) => $query
                ->where('source_branch_id', $branchId)
                ->orWhere('destination_branch_id', $branchId))
            ->with([
                'sourceBranch:id,name,code',
                'destinationBranch:id,name,code',
                'createdBy:id,name',
                'items:id,stock_transfer_id,product_id,product_sku_snapshot,product_name_snapshot,quantity',
            ])
            ->withCount('items')
            ->orderByDesc('id')
            ->paginate(15)
            ->through(static fn (StockTransfer $transfer): array => [
                'id' => (int) $transfer->getKey(),
                'transferNumber' => $transfer->transfer_number,
                'sourceBranch' => $transfer->sourceBranch?->name ?? '—',
                'destinationBranch' => $transfer->destinationBranch?->name ?? '—',
                'createdBy' => $transfer->createdBy?->name ?? '—',
                'status' => $transfer->status,
                'notes' => $transfer->notes,
                'itemsCount' => (int) $transfer->items_count,
                'items' => $transfer->items->map(static fn ($item): array => [
                    'id' => (int) $item->getKey(),
                    'sku' => $item->product_sku_snapshot,
                    'name' => $item->product_name_snapshot,
                    'quantity' => (string) $item->quantity,
                ])->values(),
                'createdAt' => $transfer->created_at?->format('Y-m-d H:i'),
            ]);

        return Inertia::render('Inventory/Transfers', [
            'organization' => $organization->only(['id', 'name', 'currency']),
            'currentBranch' => $branch->only(['id', 'name', 'code']),
            'branches' => $organization->branches()
                ->where('status', 'active')
                ->where('id', '!=', $branchId)
                ->orderBy('name')
                ->get(['id', 'name', 'code'])
                ->map(static fn ($otherBranch): array => [
                    'id' => (int) $otherBranch->getKey(),
                    'name' => $otherBranch->name,
                    'code' => $otherBranch->code,
                ])->values(),
            'products' => $products,
            'transfers' => $transfers,
            'flashMessage' => $request->session()->get('success'),
            'errorMessage' => $request->session()->get('error'),
        ]);
    }

    public function store(StoreStockTransferRequest $request, CreateStockTransfer $createStockTransfer): RedirectResponse
    {
        /** @var Organization $organization */
        $organization = $request->attributes->get('currentOrganization');
        $branch = $request->attributes->get('currentBranch');

        if ($branch === null) {
            return redirect()->route('branches.index')->with('error', 'لا يوجد فرع متاح لهذا الحساب.');
        }

        $data = CreateStockTransferData::fromValidated(
            $request->validated(),
            (int) $organization->getKey(),
            (int) $branch->getKey(),
            (int) $request->user()->getKey(),
        );
        $transfer = $createStockTransfer->handle($data);

        $message = $transfer->wasRecentlyCreated
            ? 'تم تحويل المخزون وتسجيل الحركتين في الفرعين بنجاح.'
            : 'سبق تسجيل عملية التحويل نفسها؛ لم يتم خصم المخزون مرة ثانية.';

        return redirect()->route('inventory.transfers.index')->with('success', $message);
    }
}
