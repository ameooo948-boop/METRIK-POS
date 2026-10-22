<?php

namespace App\Services\Inventory;

use App\Data\Inventory\CreateInventoryStockCountData;
use App\Models\Branch;
use App\Models\InventoryBalance;
use App\Models\InventoryMovement;
use App\Models\InventoryStockCount;
use App\Models\InventoryStockCountItem;
use App\Models\Product;
use App\Support\DecimalQuantity;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CreateInventoryStockCount
{
    public function handle(CreateInventoryStockCountData $data): InventoryStockCount
    {
        $normalizedItems = $this->normalizeItems($data);
        $payloadHash = $this->payloadHash($data, $normalizedItems);

        try {
            return DB::transaction(function () use ($data, $normalizedItems, $payloadHash): InventoryStockCount {
                $existing = InventoryStockCount::query()
                    ->forOrganization($data->organizationId)
                    ->where('client_operation_uuid', $data->clientOperationUuid)
                    ->lockForUpdate()
                    ->first();

                if ($existing) {
                    return $this->returnOrRejectReplay($existing, $payloadHash);
                }

                $branch = Branch::query()
                    ->where('organization_id', $data->organizationId)
                    ->whereKey($data->branchId)
                    ->where('status', 'active')
                    ->lockForUpdate()
                    ->firstOrFail();

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
                        'items' => 'بعض الأصناف غير موجودة أو غير متاحة داخل هذه المؤسسة.',
                    ]);
                }

                $prepared = [];
                foreach ($normalizedItems as $item) {
                    /** @var Product $product */
                    $product = $products->get($item['product_id']);
                    InventoryBalance::query()->firstOrCreate(
                        [
                            'organization_id' => $data->organizationId,
                            'branch_id' => $branch->getKey(),
                            'product_id' => $product->getKey(),
                        ],
                        ['quantity' => '0.000'],
                    );

                    $balance = InventoryBalance::query()
                        ->where('organization_id', $data->organizationId)
                        ->where('branch_id', $branch->getKey())
                        ->where('product_id', $product->getKey())
                        ->lockForUpdate()
                        ->first();

                    if (! $balance) {
                        throw ValidationException::withMessages([
                            'items' => 'تعذر قراءة رصيد أحد الأصناف. حدّث الصفحة ثم حاول مرة أخرى.',
                        ]);
                    }

                    $currentMilli = DecimalQuantity::toMilli((string) $balance->quantity);
                    if ($currentMilli !== $item['expected_quantity_milli']) {
                        throw ValidationException::withMessages([
                            'items' => 'تغيّر رصيد «'.$product->name.'» منذ فتح شاشة الجرد. حدّث الصفحة وأعد إدخال الكمية الفعلية قبل الحفظ.',
                        ]);
                    }

                    $varianceMilli = $item['counted_quantity_milli'] - $currentMilli;
                    $prepared[] = [
                        'product' => $product,
                        'balance' => $balance,
                        'expected_quantity_milli' => $currentMilli,
                        'counted_quantity_milli' => $item['counted_quantity_milli'],
                        'variance_quantity_milli' => $varianceMilli,
                    ];
                }

                $varianceLines = count(array_filter(
                    $prepared,
                    static fn (array $line): bool => $line['variance_quantity_milli'] !== 0,
                ));

                $stockCount = InventoryStockCount::query()->create([
                    'organization_id' => $data->organizationId,
                    'branch_id' => $branch->getKey(),
                    'created_by' => $data->createdBy,
                    'count_number' => 'CNT-'.now()->format('Ymd').'-'.Str::upper((string) Str::ulid()),
                    'client_operation_uuid' => $data->clientOperationUuid,
                    'payload_hash' => $payloadHash,
                    'status' => 'completed',
                    'total_lines' => count($prepared),
                    'total_variance_lines' => $varianceLines,
                    'notes' => $data->notes,
                    'completed_at' => now(),
                ]);

                foreach ($prepared as $line) {
                    /** @var Product $product */
                    $product = $line['product'];
                    /** @var InventoryBalance $balance */
                    $balance = $line['balance'];
                    $expected = DecimalQuantity::fromMilli($line['expected_quantity_milli']);
                    $counted = DecimalQuantity::fromMilli($line['counted_quantity_milli']);
                    $variance = DecimalQuantity::fromMilli($line['variance_quantity_milli']);

                    InventoryStockCountItem::query()->create([
                        'organization_id' => $data->organizationId,
                        'inventory_stock_count_id' => $stockCount->getKey(),
                        'product_id' => $product->getKey(),
                        'product_sku_snapshot' => (string) $product->sku,
                        'product_name_snapshot' => (string) $product->name,
                        'expected_quantity' => $expected,
                        'counted_quantity' => $counted,
                        'variance_quantity' => $variance,
                    ]);

                    if ($line['variance_quantity_milli'] === 0) {
                        continue;
                    }

                    $balance->quantity = $counted;
                    $balance->save();

                    InventoryMovement::query()->create([
                        'organization_id' => $data->organizationId,
                        'branch_id' => $branch->getKey(),
                        'product_id' => $product->getKey(),
                        'created_by' => $data->createdBy,
                        'movement_type' => 'count_adjustment',
                        'quantity_delta' => $variance,
                        'balance_after' => $counted,
                        'source' => 'stock_count',
                        'reason' => 'تسوية جرد رقم '.$stockCount->count_number,
                        'reference_type' => InventoryStockCount::class,
                        'reference_id' => $stockCount->getKey(),
                    ]);
                }

                return $stockCount->load(['branch', 'createdBy', 'items']);
            });
        } catch (QueryException $exception) {
            $existing = InventoryStockCount::query()
                ->forOrganization($data->organizationId)
                ->where('client_operation_uuid', $data->clientOperationUuid)
                ->first();

            if (! $existing) {
                throw $exception;
            }

            return $this->returnOrRejectReplay($existing, $payloadHash);
        }
    }

    /** @return list<array{product_id:int, expected_quantity_milli:int, counted_quantity_milli:int}> */
    private function normalizeItems(CreateInventoryStockCountData $data): array
    {
        $normalized = [];
        $seen = [];

        foreach ($data->items as $item) {
            $productId = (int) $item['product_id'];
            $expectedMilli = DecimalQuantity::toMilli((string) $item['expected_quantity']);
            $countedMilli = DecimalQuantity::toMilli((string) $item['counted_quantity']);

            if (isset($seen[$productId])) {
                throw ValidationException::withMessages(['items' => 'لا يمكن إضافة الصنف نفسه مرتين في نفس عملية الجرد.']);
            }

            $seen[$productId] = true;
            $normalized[] = [
                'product_id' => $productId,
                'expected_quantity_milli' => $expectedMilli,
                'counted_quantity_milli' => $countedMilli,
            ];
        }

        usort($normalized, static fn (array $left, array $right): int => $left['product_id'] <=> $right['product_id']);

        return $normalized;
    }

    /** @param list<array{product_id:int, expected_quantity_milli:int, counted_quantity_milli:int}> $items */
    private function payloadHash(CreateInventoryStockCountData $data, array $items): string
    {
        return hash('sha256', json_encode([
            'organization_id' => $data->organizationId,
            'branch_id' => $data->branchId,
            'items' => $items,
            'notes' => $data->notes,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private function returnOrRejectReplay(InventoryStockCount $existing, string $payloadHash): InventoryStockCount
    {
        if (! hash_equals((string) $existing->payload_hash, $payloadHash)) {
            throw ValidationException::withMessages([
                'client_operation_uuid' => 'معرّف العملية مستخدم لجرد مختلف. حدّث الصفحة قبل المحاولة مرة أخرى.',
            ]);
        }

        return $existing->load(['branch', 'createdBy', 'items']);
    }
}
