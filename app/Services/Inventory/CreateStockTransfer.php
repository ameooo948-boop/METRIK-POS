<?php

namespace App\Services\Inventory;

use App\Data\Inventory\CreateStockTransferData;
use App\Models\Branch;
use App\Models\InventoryBalance;
use App\Models\InventoryMovement;
use App\Models\InventoryLot;
use App\Models\InventoryLotMovement;
use App\Models\Product;
use App\Models\StockTransfer;
use App\Models\StockTransferItem;
use App\Support\DecimalQuantity;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CreateStockTransfer
{
    public function handle(CreateStockTransferData $data): StockTransfer
    {
        $normalizedItems = $this->normalizeItems($data);
        $payloadHash = $this->payloadHash($data, $normalizedItems);

        try {
            return DB::transaction(function () use ($data, $normalizedItems, $payloadHash): StockTransfer {
                $existing = StockTransfer::query()
                    ->forOrganization($data->organizationId)
                    ->where('client_operation_uuid', $data->clientOperationUuid)
                    ->lockForUpdate()
                    ->first();

                if ($existing) {
                    return $this->returnOrRejectReplay($existing, $payloadHash);
                }

                $source = Branch::query()
                    ->where('organization_id', $data->organizationId)
                    ->whereKey($data->sourceBranchId)
                    ->where('status', 'active')
                    ->lockForUpdate()
                    ->firstOrFail();
                $destination = Branch::query()
                    ->where('organization_id', $data->organizationId)
                    ->whereKey($data->destinationBranchId)
                    ->where('status', 'active')
                    ->lockForUpdate()
                    ->firstOrFail();

                if ((int) $source->getKey() === (int) $destination->getKey()) {
                    throw ValidationException::withMessages([
                        'destination_branch_id' => 'يجب اختيار فرع مختلف عن الفرع الحالي.',
                    ]);
                }

                $productIds = array_column($normalizedItems, 'product_id');
                $products = Product::query()
                    ->forOrganization($data->organizationId)
                    ->where('is_active', true)
                    ->whereIn('id', $productIds)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get()
                    ->keyBy(static fn (Product $product): int => (int) $product->getKey());

                if ($products->count() !== count($productIds)) {
                    throw ValidationException::withMessages([
                        'items' => 'بعض الأصناف غير متاحة داخل هذه المؤسسة.',
                    ]);
                }

                $prepared = [];
                $orderedBranchIds = [$data->sourceBranchId, $data->destinationBranchId];
                sort($orderedBranchIds, SORT_NUMERIC);

                foreach ($normalizedItems as $item) {
                    /** @var Product $product */
                    $product = $products->get($item['product_id']);
                    $quantityMilli = $item['quantity_milli'];

                    // Ensure both rows exist before taking locks in a consistent branch-id order.
                    foreach ($orderedBranchIds as $branchId) {
                        InventoryBalance::query()->firstOrCreate(
                            [
                                'organization_id' => $data->organizationId,
                                'branch_id' => $branchId,
                                'product_id' => $product->getKey(),
                            ],
                            ['quantity' => '0.000'],
                        );
                    }

                    $balances = InventoryBalance::query()
                        ->where('organization_id', $data->organizationId)
                        ->where('product_id', $product->getKey())
                        ->whereIn('branch_id', $orderedBranchIds)
                        ->orderBy('branch_id')
                        ->lockForUpdate()
                        ->get()
                        ->keyBy(static fn (InventoryBalance $balance): int => (int) $balance->branch_id);

                    $sourceBalance = $balances->get($data->sourceBranchId);
                    $destinationBalance = $balances->get($data->destinationBranchId);
                    if (! $sourceBalance || ! $destinationBalance) {
                        throw ValidationException::withMessages([
                            'items' => 'تعذر قفل أرصدة المخزون لهذا التحويل. حاول مرة أخرى.',
                        ]);
                    }

                    $sourceBefore = DecimalQuantity::toMilli((string) $sourceBalance->quantity);
                    $destinationBefore = DecimalQuantity::toMilli((string) $destinationBalance->quantity);
                    $destinationTrackedMilli = DecimalQuantity::toMilli((string) InventoryLot::query()
                        ->where('organization_id', $data->organizationId)
                        ->where('branch_id', $data->destinationBranchId)
                        ->where('product_id', $product->getKey())
                        ->where('quantity_remaining', '>', 0)
                        ->lockForUpdate()
                        ->sum('quantity_remaining'));
                    if ($destinationTrackedMilli > $destinationBefore) {
                        throw ValidationException::withMessages([
                            'items' => 'رصيد التشغيلات المسجل في الفرع المستلم أكبر من رصيد الدفتر للصنف «'.$product->name.'». راجع التشغيلات قبل التحويل.',
                        ]);
                    }
                    if ($quantityMilli > $sourceBefore) {
                        throw ValidationException::withMessages([
                            'items' => 'الرصيد غير كافٍ في الفرع الحالي للصنف «'.$product->name.'». المتاح: '
                                .DecimalQuantity::fromMilli($sourceBefore).'.',
                        ]);
                    }

                    $sourceLots = InventoryLot::query()
                        ->where('organization_id', $data->organizationId)
                        ->where('branch_id', $data->sourceBranchId)
                        ->where('product_id', $product->getKey())
                        ->where('quantity_remaining', '>', 0)
                        ->orderBy('expires_at')->orderBy('id')->lockForUpdate()->get();
                    $trackedMilli = $sourceLots->sum(fn (InventoryLot $lot): int => DecimalQuantity::toMilli((string) $lot->quantity_remaining));
                    if ($trackedMilli > $sourceBefore) {
                        throw ValidationException::withMessages([
                            'items' => 'رصيد التشغيلات المسجل للصنف «'.$product->name.'» أكبر من رصيد الفرع. راجع التشغيلات قبل التحويل.',
                        ]);
                    }
                    $remainingTrackedToTransfer = min($quantityMilli, $trackedMilli);
                    $lotTransferLines = [];
                    foreach ($sourceLots as $sourceLot) {
                        if ($remainingTrackedToTransfer <= 0) break;
                        $lotMilli = DecimalQuantity::toMilli((string) $sourceLot->quantity_remaining);
                        $takeMilli = min($remainingTrackedToTransfer, $lotMilli);
                        if ($takeMilli > 0) {
                            $lotTransferLines[] = ['lot' => $sourceLot, 'quantity_milli' => $takeMilli];
                            $remainingTrackedToTransfer -= $takeMilli;
                        }
                    }

                    $prepared[] = [
                        'product' => $product,
                        'quantity_milli' => $quantityMilli,
                        'source_balance' => $sourceBalance,
                        'destination_balance' => $destinationBalance,
                        'source_after_milli' => $sourceBefore - $quantityMilli,
                        'destination_after_milli' => $destinationBefore + $quantityMilli,
                        'lot_transfer_lines' => $lotTransferLines,
                    ];
                }

                $transfer = StockTransfer::query()->create([
                    'organization_id' => $data->organizationId,
                    'source_branch_id' => $source->getKey(),
                    'destination_branch_id' => $destination->getKey(),
                    'created_by' => $data->createdBy,
                    'transfer_number' => 'TRF-'.now()->format('Ymd').'-'.Str::upper((string) Str::ulid()),
                    'client_operation_uuid' => $data->clientOperationUuid,
                    'payload_hash' => $payloadHash,
                    'status' => 'completed',
                    'total_lines' => count($prepared),
                    'notes' => $data->notes,
                    'completed_at' => now(),
                ]);

                foreach ($prepared as $line) {
                    /** @var Product $product */
                    $product = $line['product'];
                    /** @var InventoryBalance $sourceBalance */
                    $sourceBalance = $line['source_balance'];
                    /** @var InventoryBalance $destinationBalance */
                    $destinationBalance = $line['destination_balance'];
                    $quantity = DecimalQuantity::fromMilli($line['quantity_milli']);
                    $sourceAfter = DecimalQuantity::fromMilli($line['source_after_milli']);
                    $destinationAfter = DecimalQuantity::fromMilli($line['destination_after_milli']);

                    $sourceBalance->quantity = $sourceAfter;
                    $sourceBalance->save();
                    $destinationBalance->quantity = $destinationAfter;
                    $destinationBalance->save();

                    StockTransferItem::query()->create([
                        'organization_id' => $data->organizationId,
                        'stock_transfer_id' => $transfer->getKey(),
                        'product_id' => $product->getKey(),
                        'product_sku_snapshot' => $product->sku,
                        'product_name_snapshot' => $product->name,
                        'quantity' => $quantity,
                        'source_balance_after' => $sourceAfter,
                        'destination_balance_after' => $destinationAfter,
                    ]);

                    InventoryMovement::query()->create([
                        'organization_id' => $data->organizationId,
                        'branch_id' => $source->getKey(),
                        'product_id' => $product->getKey(),
                        'created_by' => $data->createdBy,
                        'movement_type' => 'transfer_out',
                        'quantity_delta' => DecimalQuantity::fromMilli(-$line['quantity_milli']),
                        'balance_after' => $sourceAfter,
                        'source' => 'stock_transfer',
                        'reason' => 'تحويل إلى '.$destination->name.' رقم '.$transfer->transfer_number,
                        'reference_type' => StockTransfer::class,
                        'reference_id' => $transfer->getKey(),
                    ]);

                    InventoryMovement::query()->create([
                        'organization_id' => $data->organizationId,
                        'branch_id' => $destination->getKey(),
                        'product_id' => $product->getKey(),
                        'created_by' => $data->createdBy,
                        'movement_type' => 'transfer_in',
                        'quantity_delta' => $quantity,
                        'balance_after' => $destinationAfter,
                        'source' => 'stock_transfer',
                        'reason' => 'تحويل من '.$source->name.' رقم '.$transfer->transfer_number,
                        'reference_type' => StockTransfer::class,
                        'reference_id' => $transfer->getKey(),
                    ]);

                    foreach ($line['lot_transfer_lines'] as $lotLine) {
                        /** @var InventoryLot $sourceLot */
                        $sourceLot = $lotLine['lot'];
                        $takeMilli = $lotLine['quantity_milli'];
                        $sourceLotAfterMilli = DecimalQuantity::toMilli((string) $sourceLot->quantity_remaining) - $takeMilli;
                        $sourceLot->quantity_remaining = DecimalQuantity::fromMilli($sourceLotAfterMilli);
                        $sourceLot->save();
                        InventoryLotMovement::query()->create([
                            'organization_id' => $data->organizationId, 'branch_id' => $source->getKey(),
                            'product_id' => $product->getKey(), 'inventory_lot_id' => $sourceLot->getKey(),
                            'created_by' => $data->createdBy, 'movement_type' => 'transfer_out',
                            'quantity_delta' => DecimalQuantity::fromMilli(-$takeMilli),
                            'balance_after' => DecimalQuantity::fromMilli($sourceLotAfterMilli),
                            'reason' => 'تحويل تشغيلة إلى '.$destination->name.' رقم '.$transfer->transfer_number,
                            'reference_type' => StockTransfer::class, 'reference_id' => $transfer->getKey(),
                        ]);

                        $destinationLot = InventoryLot::query()
                            ->where('organization_id', $data->organizationId)
                            ->where('branch_id', $destination->getKey())
                            ->where('product_id', $product->getKey())
                            ->where('batch_code', $sourceLot->batch_code)
                            ->lockForUpdate()->first();
                        if ($destinationLot && (
                            $destinationLot->expires_at->toDateString() !== $sourceLot->expires_at->toDateString()
                            || $destinationLot->manufactured_at?->toDateString() !== $sourceLot->manufactured_at?->toDateString()
                        )) {
                            throw ValidationException::withMessages([
                                'items' => 'رقم التشغيلة «'.$sourceLot->batch_code.'» موجود في الفرع المستلم بتواريخ مختلفة. راجع بيانات التشغيلة قبل التحويل.',
                            ]);
                        }
                        $destinationLotBeforeMilli = $destinationLot ? DecimalQuantity::toMilli((string) $destinationLot->quantity_remaining) : 0;
                        $destinationLotReceivedMilli = $destinationLot ? DecimalQuantity::toMilli((string) $destinationLot->quantity_received) : 0;
                        if (! $destinationLot) {
                            $destinationLot = InventoryLot::query()->create([
                                'organization_id' => $data->organizationId, 'branch_id' => $destination->getKey(),
                                'product_id' => $product->getKey(), 'created_by' => $data->createdBy,
                                'batch_code' => $sourceLot->batch_code, 'manufactured_at' => $sourceLot->manufactured_at?->toDateString(),
                                'expires_at' => $sourceLot->expires_at->toDateString(), 'received_at' => now($source->organization?->timezone ?: config('app.timezone'))->toDateString(),
                                'quantity_received' => DecimalQuantity::fromMilli($takeMilli),
                                'quantity_remaining' => DecimalQuantity::fromMilli($takeMilli),
                            ]);
                        } else {
                            $destinationLotAfterMilli = $destinationLotBeforeMilli + $takeMilli;
                            $destinationLot->quantity_received = DecimalQuantity::fromMilli($destinationLotReceivedMilli + $takeMilli);
                            $destinationLot->quantity_remaining = DecimalQuantity::fromMilli($destinationLotAfterMilli);
                            $destinationLot->save();
                        }
                        $destinationLotAfterMilli = DecimalQuantity::toMilli((string) $destinationLot->quantity_remaining);
                        InventoryLotMovement::query()->create([
                            'organization_id' => $data->organizationId, 'branch_id' => $destination->getKey(),
                            'product_id' => $product->getKey(), 'inventory_lot_id' => $destinationLot->getKey(),
                            'created_by' => $data->createdBy, 'movement_type' => 'transfer_in',
                            'quantity_delta' => DecimalQuantity::fromMilli($takeMilli),
                            'balance_after' => DecimalQuantity::fromMilli($destinationLotAfterMilli),
                            'reason' => 'استلام تشغيلة من '.$source->name.' رقم '.$transfer->transfer_number,
                            'reference_type' => StockTransfer::class, 'reference_id' => $transfer->getKey(),
                        ]);
                    }
                }

                return $transfer->load(['sourceBranch', 'destinationBranch', 'items']);
            });
        } catch (QueryException $exception) {
            // A concurrent retry can race the unique operation key. Only suppress the
            // database error if the winning transfer exists and matches this exact payload.
            $existing = StockTransfer::query()
                ->forOrganization($data->organizationId)
                ->where('client_operation_uuid', $data->clientOperationUuid)
                ->first();

            if (! $existing) {
                throw $exception;
            }

            return $this->returnOrRejectReplay($existing, $payloadHash);
        }
    }

    /** @return list<array{product_id:int, quantity_milli:int}> */
    private function normalizeItems(CreateStockTransferData $data): array
    {
        $normalized = [];
        $seen = [];

        foreach ($data->items as $item) {
            $productId = (int) $item['product_id'];
            $quantityMilli = DecimalQuantity::toMilli((string) $item['quantity']);

            if (isset($seen[$productId])) {
                throw ValidationException::withMessages(['items' => 'لا يمكن تكرار الصنف نفسه في نفس التحويل.']);
            }
            if ($quantityMilli <= 0) {
                throw ValidationException::withMessages(['items' => 'يجب أن تكون كمية كل صنف أكبر من صفر.']);
            }

            $seen[$productId] = true;
            $normalized[] = ['product_id' => $productId, 'quantity_milli' => $quantityMilli];
        }

        usort($normalized, static fn (array $left, array $right): int => $left['product_id'] <=> $right['product_id']);

        return $normalized;
    }

    /** @param list<array{product_id:int, quantity_milli:int}> $items */
    private function payloadHash(CreateStockTransferData $data, array $items): string
    {
        return hash('sha256', json_encode([
            'organization_id' => $data->organizationId,
            'source_branch_id' => $data->sourceBranchId,
            'destination_branch_id' => $data->destinationBranchId,
            'items' => $items,
            'notes' => $data->notes,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private function returnOrRejectReplay(StockTransfer $existing, string $payloadHash): StockTransfer
    {
        if (! hash_equals((string) $existing->payload_hash, $payloadHash)) {
            throw ValidationException::withMessages([
                'client_operation_uuid' => 'معرّف العملية مستخدم لتحويل مختلف. حدّث الصفحة قبل المحاولة مرة أخرى.',
            ]);
        }

        return $existing->load(['sourceBranch', 'destinationBranch', 'items']);
    }
}
