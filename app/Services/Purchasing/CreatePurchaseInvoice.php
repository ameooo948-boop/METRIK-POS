<?php

namespace App\Services\Purchasing;

use App\Data\Purchasing\CreatePurchaseInvoiceData;
use App\Models\Branch;
use App\Models\InventoryBalance;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\PurchaseInvoice;
use App\Models\PurchaseInvoiceItem;
use App\Models\ProductUnitConversion;
use App\Models\Supplier;
use App\Models\SupplierTransaction;
use App\Support\DecimalMoney;
use App\Support\DecimalQuantity;
use App\Support\ProductUnitOptions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CreatePurchaseInvoice
{
    public function handle(CreatePurchaseInvoiceData $data): PurchaseInvoice
    {
        return DB::transaction(function () use ($data): PurchaseInvoice {
            $branch = Branch::query()
                ->where('organization_id', $data->organizationId)
                ->whereKey($data->branchId)
                ->where('status', 'active')
                ->firstOrFail();

            $supplier = Supplier::query()
                ->forOrganization($data->organizationId)
                ->active()
                ->whereKey($data->supplierId)
                ->lockForUpdate()
                ->firstOrFail();

            $preparedItems = [];
            $totalCents = 0;

            // Lock products in ascending ID order to reduce deadlock risk and eager-load base units.
            $productIds = array_values(array_unique(array_map(
                static fn (array $item): int => (int) $item['product_id'],
                $data->items,
            )));
            sort($productIds, SORT_NUMERIC);

            $products = Product::query()
                ->forOrganization($data->organizationId)
                ->active()
                ->whereIn('id', $productIds)
                ->orderBy('id')
                ->with(['baseUnit:id,name_ar,name_en', 'unitConversions.unit:id,name_ar,name_en'])
                ->lockForUpdate()
                ->get()
                ->keyBy(static fn (Product $product): int => (int) $product->getKey());

            if ($products->count() !== count($productIds)) {
                throw ValidationException::withMessages([
                    'items' => 'يوجد منتج غير نشط أو لا يتبع المؤسسة الحالية. حدّث الفاتورة وحاول مرة أخرى.',
                ]);
            }

            foreach ($data->items as $item) {
                /** @var Product $product */
                $product = $products->get((int) $item['product_id']);

                $quantityMilli = DecimalQuantity::toMilli($item['quantity']);
                $requestedUnitId = isset($item['unit_id']) && $item['unit_id'] !== '' ? (int) $item['unit_id'] : null;
                $unit = ProductUnitOptions::resolve($product, $requestedUnitId);
                if ($unit === null) {
                    throw ValidationException::withMessages([
                        'items' => 'وحدة القياس المختارة غير مفعلة للمنتج «'.$product->name.'».',
                    ]);
                }
                $baseQuantityMilli = intdiv(($quantityMilli * $unit['factorMilli']) + 500, 1000);
                if ($baseQuantityMilli <= 0) {
                    throw ValidationException::withMessages(['items' => 'الكمية بعد تحويل وحدة القياس صغيرة جدًا أو غير صالحة.']);
                }
                $unitCostCents = DecimalMoney::toCents($item['unit_cost']);
                if ($unitCostCents < 0 || $unitCostCents > 999_999_999) {
                    throw ValidationException::withMessages(['items' => 'تكلفة الوحدة خارج النطاق المسموح به.']);
                }
                $lineTotalCents = intdiv(($quantityMilli * $unitCostCents) + 500, 1000);
                $totalCents += $lineTotalCents;

                $preparedItems[] = [
                    'product' => $product,
                    'unit' => $unit,
                    'quantity_milli' => $quantityMilli,
                    'base_quantity_milli' => $baseQuantityMilli,
                    'quantity' => DecimalQuantity::fromMilli($quantityMilli),
                    'unit_cost_cents' => $unitCostCents,
                    'unit_cost' => DecimalMoney::fromCents($unitCostCents),
                    'line_total_cents' => $lineTotalCents,
                    'line_total' => DecimalMoney::fromCents($lineTotalCents),
                ];
            }

            // DECIMAL(14,2) maximum, checked before persistence to avoid database overflow.
            if ($totalCents > 99_999_999_999_999) {
                throw ValidationException::withMessages([
                    'items' => 'إجمالي الفاتورة أكبر من الحد المسموح به. قسّم الفاتورة إلى أكثر من فاتورة.',
                ]);
            }

            $paidCents = DecimalMoney::toCents($data->paidAmount);
            if ($paidCents > $totalCents) {
                throw ValidationException::withMessages([
                    'paid_amount' => 'المبلغ المدفوع لا يمكن أن يتجاوز إجمالي الفاتورة.',
                ]);
            }

            $invoiceNumber = 'PI-'.now()->format('Ymd').'-'.Str::upper((string) Str::ulid());
            $invoice = PurchaseInvoice::query()->create([
                'organization_id' => $data->organizationId,
                'branch_id' => $branch->getKey(),
                'supplier_id' => $supplier->getKey(),
                'created_by' => $data->createdBy,
                'invoice_number' => $invoiceNumber,
                'supplier_invoice_number' => $data->supplierInvoiceNumber,
                'status' => 'received',
                'subtotal' => DecimalMoney::fromCents($totalCents),
                'total' => DecimalMoney::fromCents($totalCents),
                'paid_amount' => DecimalMoney::fromCents($paidCents),
                'due_amount' => DecimalMoney::fromCents($totalCents - $paidCents),
                'notes' => $data->notes,
                'received_at' => now(),
            ]);

            foreach ($preparedItems as $item) {
                /** @var Product $product */
                $product = $item['product'];

                PurchaseInvoiceItem::query()->create([
                    'organization_id' => $data->organizationId,
                    'purchase_invoice_id' => $invoice->getKey(),
                    'product_id' => $product->getKey(),
                    'product_sku_snapshot' => $product->sku,
                    'product_name_snapshot' => $product->name,
                    'unit_id' => $item['unit']['persistedId'],
                    'unit_name_snapshot' => $item['unit']['unitName'],
                    'unit_conversion_factor_snapshot' => $item['unit']['factorToBase'],
                    'quantity' => $item['quantity'],
                    'quantity_in_base' => DecimalQuantity::fromMilli($item['base_quantity_milli']),
                    'unit_cost' => $item['unit_cost'],
                    'line_total' => $item['line_total'],
                ]);

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

                $newMilli = DecimalQuantity::toMilli((string) $balance->quantity) + $item['base_quantity_milli'];
                $balance->quantity = DecimalQuantity::fromMilli($newMilli);
                $balance->save();

                InventoryMovement::query()->create([
                    'organization_id' => $data->organizationId,
                    'branch_id' => $branch->getKey(),
                    'product_id' => $product->getKey(),
                    'created_by' => $data->createdBy,
                    'movement_type' => 'purchase',
                    'quantity_delta' => DecimalQuantity::fromMilli($item['base_quantity_milli']),
                    'balance_after' => DecimalQuantity::fromMilli($newMilli),
                    'source' => 'purchase_invoice',
                    'reason' => 'استلام من فاتورة شراء '.$invoice->invoice_number,
                    'reference_type' => PurchaseInvoice::class,
                    'reference_id' => $invoice->getKey(),
                ]);

                // Product purchase_price stays in the base unit. Keep an explicit cost for
                // this package unit when one was purchased; otherwise derive the base-unit cost.
                $baseCostCents = intdiv(($item['unit_cost_cents'] * 1000) + intdiv($item['unit']['factorMilli'], 2), $item['unit']['factorMilli']);
                $product->purchase_price = DecimalMoney::fromCents($baseCostCents);
                $product->save();
                if ($item['unit']['persistedId'] !== null) {
                    ProductUnitConversion::query()
                        ->where('product_id', $product->getKey())
                        ->where('unit_id', $item['unit']['persistedId'])
                        ->update(['purchase_price' => $item['unit_cost'], 'updated_at' => now()]);
                }
            }

            $previousCredit = DecimalMoney::toCents((string) $supplier->transactions()->sum('credit'));
            $previousDebit = DecimalMoney::toCents((string) $supplier->transactions()->sum('debit'));
            $runningBalance = $previousCredit - $previousDebit;

            $runningBalance += $totalCents;
            SupplierTransaction::query()->create([
                'organization_id' => $data->organizationId,
                'supplier_id' => $supplier->getKey(),
                'branch_id' => $branch->getKey(),
                'created_by' => $data->createdBy,
                'transaction_type' => 'purchase_invoice',
                'debit' => '0.00',
                'credit' => DecimalMoney::fromCents($totalCents),
                'balance_after' => DecimalMoney::fromCents($runningBalance),
                'notes' => 'فاتورة شراء '.$invoice->invoice_number,
                'reference_type' => PurchaseInvoice::class,
                'reference_id' => $invoice->getKey(),
            ]);

            if ($paidCents > 0) {
                $runningBalance -= $paidCents;
                SupplierTransaction::query()->create([
                    'organization_id' => $data->organizationId,
                    'supplier_id' => $supplier->getKey(),
                    'branch_id' => $branch->getKey(),
                    'created_by' => $data->createdBy,
                    'transaction_type' => 'payment',
                    'debit' => DecimalMoney::fromCents($paidCents),
                    'credit' => '0.00',
                    'balance_after' => DecimalMoney::fromCents($runningBalance),
                    'notes' => 'دفعة أولية عند استلام الفاتورة '.$invoice->invoice_number,
                    'reference_type' => PurchaseInvoice::class,
                    'reference_id' => $invoice->getKey(),
                ]);
            }

            return $invoice->load(['supplier', 'branch', 'items']);
        }, attempts: 3);
    }
}
