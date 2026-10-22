<?php

namespace App\Services\Purchasing;

use App\Models\Branch;
use App\Models\Organization;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Supplier;
use App\Support\DecimalMoney;
use App\Support\DecimalQuantity;
use App\Support\ProductUnitOptions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CreatePurchaseOrder
{
    /** @param array<string, mixed> $data */
    public function handle(array $data, int $organizationId, int $branchId, int $userId): PurchaseOrder
    {
        return DB::transaction(function () use ($data, $organizationId, $branchId, $userId): PurchaseOrder {
            Branch::query()->where('organization_id', $organizationId)->whereKey($branchId)->where('status', 'active')->firstOrFail();
            $supplier = Supplier::query()->forOrganization($organizationId)->active()->whereKey((int) $data['supplier_id'])->lockForUpdate()->firstOrFail();

            $productIds = array_values(array_unique(array_map(static fn (array $item): int => (int) $item['product_id'], $data['items'])));
            sort($productIds, SORT_NUMERIC);
            $products = Product::query()->forOrganization($organizationId)->active()->whereIn('id', $productIds)
                ->orderBy('id')->with(['baseUnit:id,name_ar,name_en', 'unitConversions.unit:id,name_ar,name_en'])
                ->lockForUpdate()->get()->keyBy(static fn (Product $product): int => (int) $product->getKey());
            if ($products->count() !== count($productIds)) {
                throw ValidationException::withMessages(['items' => 'يوجد منتج غير نشط أو لا يتبع المؤسسة الحالية.']);
            }

            $prepared = [];
            $totalCents = 0;
            foreach ($data['items'] as $item) {
                $product = $products->get((int) $item['product_id']);
                $unit = ProductUnitOptions::resolve($product, (int) $item['unit_id']);
                if ($unit === null) {
                    throw ValidationException::withMessages(['items' => 'وحدة القياس المختارة غير مفعلة للمنتج «'.$product->name.'».']);
                }
                $quantityMilli = DecimalQuantity::toMilli((string) $item['quantity']);
                $unitCostCents = DecimalMoney::toCents((string) $item['unit_cost']);
                if ($quantityMilli <= 0 || $unitCostCents < 0 || $unitCostCents > 999_999_999) {
                    throw ValidationException::withMessages(['items' => 'تحقق من الكمية وسعر شراء الوحدة.']);
                }
                $lineCents = intdiv(($quantityMilli * $unitCostCents) + 500, 1000);
                $totalCents += $lineCents;
                $prepared[] = compact('product', 'unit', 'quantityMilli', 'unitCostCents', 'lineCents');
            }
            if ($totalCents > 99_999_999_999_999) {
                throw ValidationException::withMessages(['items' => 'إجمالي أمر الشراء أكبر من الحد المسموح.']);
            }

            $order = PurchaseOrder::query()->create([
                'organization_id' => $organizationId,
                'branch_id' => $branchId,
                'supplier_id' => $supplier->getKey(),
                'created_by' => $userId,
                'order_number' => 'PO-'.now()->format('Ymd').'-'.Str::upper((string) Str::ulid()),
                'status' => 'ordered',
                'expected_at' => $data['expected_at'] ?? null,
                'ordered_at' => now(),
                'subtotal' => DecimalMoney::fromCents($totalCents),
                'notes' => $data['notes'] ?? null,
            ]);

            foreach ($prepared as $item) {
                /** @var Product $product */
                $product = $item['product'];
                $unit = $item['unit'];
                $quantityMilli = $item['quantityMilli'];
                PurchaseOrderItem::query()->create([
                    'organization_id' => $organizationId,
                    'purchase_order_id' => $order->getKey(),
                    'product_id' => $product->getKey(),
                    'unit_id' => $unit['id'],
                    'product_sku_snapshot' => $product->sku,
                    'product_name_snapshot' => $product->name,
                    'unit_name_snapshot' => $unit['unitName'],
                    'unit_conversion_factor_snapshot' => $unit['factorToBase'],
                    'quantity_ordered' => DecimalQuantity::fromMilli($quantityMilli),
                    'quantity_received' => '0.000',
                    'unit_cost' => DecimalMoney::fromCents($item['unitCostCents']),
                    'line_total' => DecimalMoney::fromCents($item['lineCents']),
                ]);
            }

            return $order->load(['supplier:id,name', 'branch:id,name', 'createdBy:id,name', 'items']);
        }, attempts: 3);
    }
}
