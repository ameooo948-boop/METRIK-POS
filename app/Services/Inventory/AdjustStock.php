<?php

namespace App\Services\Inventory;

use App\Data\Inventory\StockAdjustmentData;
use App\Models\Branch;
use App\Models\InventoryBalance;
use App\Models\InventoryMovement;
use App\Models\InventoryLot;
use App\Models\InventoryLotMovement;
use App\Models\Product;
use App\Support\DecimalQuantity;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AdjustStock
{
    public function handle(StockAdjustmentData $data): InventoryMovement
    {
        return DB::transaction(function () use ($data): InventoryMovement {
            $branch = Branch::query()
                ->where('organization_id', $data->organizationId)
                ->whereKey($data->branchId)
                ->where('status', 'active')
                ->firstOrFail();

            $product = Product::query()
                ->forOrganization($data->organizationId)
                ->whereKey($data->productId)
                ->where('is_active', true)
                ->lockForUpdate()
                ->firstOrFail();

            $balance = InventoryBalance::query()
                ->where('organization_id', $data->organizationId)
                ->where('branch_id', $branch->getKey())
                ->where('product_id', $product->getKey())
                ->lockForUpdate()
                ->first();

            if (! $balance) {
                $balance = InventoryBalance::query()->create([
                    'organization_id' => $data->organizationId,
                    'branch_id' => $branch->getKey(),
                    'product_id' => $product->getKey(),
                    'quantity' => '0.000',
                ]);
            }

            $currentMilli = DecimalQuantity::toMilli((string) $balance->quantity);
            $requestedMilli = DecimalQuantity::toMilli($data->quantity);
            $deltaMilli = $data->type === 'stock_in' ? $requestedMilli : -$requestedMilli;
            $newMilli = $currentMilli + $deltaMilli;

            if ($newMilli < 0) {
                throw ValidationException::withMessages([
                    'quantity' => 'الكمية المطلوبة أكبر من الرصيد المتاح في الفرع الحالي.',
                ]);
            }

            $balance->quantity = DecimalQuantity::fromMilli($newMilli);
            $balance->save();

            $movement = InventoryMovement::query()->create([
                'organization_id' => $data->organizationId,
                'branch_id' => $branch->getKey(),
                'product_id' => $product->getKey(),
                'created_by' => $data->createdBy,
                'movement_type' => $data->type,
                'quantity_delta' => DecimalQuantity::fromMilli($deltaMilli),
                'balance_after' => DecimalQuantity::fromMilli($newMilli),
                'source' => 'manual',
                'reason' => $data->reason,
            ]);

            if ($data->type === 'stock_out' && $requestedMilli > 0) {
                $lots = InventoryLot::query()
                    ->where('organization_id', $data->organizationId)
                    ->where('branch_id', $branch->getKey())
                    ->where('product_id', $product->getKey())
                    ->where('quantity_remaining', '>', 0)
                    ->orderBy('expires_at')->orderBy('id')->lockForUpdate()->get();
                $trackedMilli = $lots->sum(fn (InventoryLot $lot): int => DecimalQuantity::toMilli((string) $lot->quantity_remaining));
                if ($trackedMilli > $currentMilli) {
                    throw ValidationException::withMessages(['quantity' => 'رصيد التشغيلات المسجل أكبر من رصيد المخزون. راجع التشغيلات قبل التسوية.']);
                }
                $remainingToRemove = min($requestedMilli, $trackedMilli);
                foreach ($lots as $lot) {
                    if ($remainingToRemove <= 0) break;
                    $lotMilli = DecimalQuantity::toMilli((string) $lot->quantity_remaining);
                    $takeMilli = min($remainingToRemove, $lotMilli);
                    if ($takeMilli <= 0) continue;
                    $lotAfterMilli = $lotMilli - $takeMilli;
                    $lot->quantity_remaining = DecimalQuantity::fromMilli($lotAfterMilli);
                    $lot->save();
                    InventoryLotMovement::query()->create([
                        'organization_id' => $data->organizationId,
                        'branch_id' => $branch->getKey(),
                        'product_id' => $product->getKey(),
                        'inventory_lot_id' => $lot->getKey(),
                        'created_by' => $data->createdBy,
                        'movement_type' => 'stock_out',
                        'quantity_delta' => DecimalQuantity::fromMilli(-$takeMilli),
                        'balance_after' => DecimalQuantity::fromMilli($lotAfterMilli),
                        'reason' => $data->reason,
                        'reference_type' => InventoryMovement::class,
                        'reference_id' => $movement->getKey(),
                    ]);
                    $remainingToRemove -= $takeMilli;
                }
            }

            return $movement;
        });
    }
}
