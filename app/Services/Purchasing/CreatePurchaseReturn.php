<?php

namespace App\Services\Purchasing;

use App\Data\Purchasing\CreatePurchaseReturnData;
use App\Models\InventoryBalance;
use App\Models\InventoryMovement;
use App\Models\PurchaseInvoice;
use App\Models\PurchaseInvoiceItem;
use App\Models\PurchaseReturn;
use App\Models\PurchaseReturnItem;
use App\Models\Supplier;
use App\Models\SupplierTransaction;
use App\Support\DecimalMoney;
use App\Support\DecimalQuantity;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CreatePurchaseReturn
{
    public function handle(CreatePurchaseReturnData $data): PurchaseReturn
    {
        return DB::transaction(function () use ($data): PurchaseReturn {
            $invoice = PurchaseInvoice::query()
                ->forOrganization($data->organizationId)
                ->where('branch_id', $data->branchId)
                ->whereKey($data->purchaseInvoiceId)
                ->where('status', 'received')
                ->lockForUpdate()
                ->firstOrFail();

            $existing = PurchaseReturn::query()
                ->forOrganization($data->organizationId)
                ->where('client_operation_uuid', $data->clientOperationUuid)
                ->with('items')
                ->lockForUpdate()
                ->first();

            if ($existing) {
                $this->assertSameOperation($existing, $data);

                return $existing->load(['items', 'supplier', 'purchaseInvoice']);
            }

            $supplier = Supplier::query()
                ->forOrganization($data->organizationId)
                ->whereKey($invoice->supplier_id)
                ->lockForUpdate()
                ->firstOrFail();

            $requestedIds = array_values(array_unique(array_map(
                static fn (array $item): int => (int) $item['purchase_invoice_item_id'],
                $data->items,
            )));
            sort($requestedIds, SORT_NUMERIC);

            $invoiceItems = PurchaseInvoiceItem::query()
                ->where('organization_id', $data->organizationId)
                ->where('purchase_invoice_id', $invoice->getKey())
                ->whereIn('id', $requestedIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy(static fn (PurchaseInvoiceItem $item): int => (int) $item->getKey());

            if ($invoiceItems->count() !== count($requestedIds)) {
                throw ValidationException::withMessages([
                    'items' => 'بعض أصناف المرتجع لا تنتمي إلى فاتورة الشراء المحددة.',
                ]);
            }

            $previousReturns = PurchaseReturnItem::query()
                ->join('purchase_returns', 'purchase_returns.id', '=', 'purchase_return_items.purchase_return_id')
                ->where('purchase_return_items.organization_id', $data->organizationId)
                ->where('purchase_returns.organization_id', $data->organizationId)
                ->whereIn('purchase_return_items.purchase_invoice_item_id', $requestedIds)
                ->where('purchase_returns.status', 'completed')
                ->selectRaw(
                    'purchase_return_items.purchase_invoice_item_id, '
                    .'COALESCE(SUM(purchase_return_items.quantity), 0) AS returned_quantity, '
                    .'COALESCE(SUM(purchase_return_items.line_total), 0) AS returned_total'
                )
                ->groupBy('purchase_return_items.purchase_invoice_item_id')
                ->get()
                ->keyBy(static fn ($row): int => (int) $row->purchase_invoice_item_id);

            $preparedItems = [];
            $totalCents = 0;
            foreach ($data->items as $requested) {
                $itemId = (int) $requested['purchase_invoice_item_id'];
                /** @var PurchaseInvoiceItem $item */
                $item = $invoiceItems->get($itemId);
                $purchasedMilli = DecimalQuantity::toMilli((string) $item->quantity);
                $previous = $previousReturns->get($itemId);
                $returnedMilli = DecimalQuantity::toMilli((string) ($previous?->returned_quantity ?? '0.000'));
                $requestedMilli = DecimalQuantity::toMilli($requested['quantity']);
                $remainingMilli = $purchasedMilli - $returnedMilli;

                if ($requestedMilli <= 0 || $requestedMilli > $remainingMilli) {
                    throw ValidationException::withMessages([
                        'items' => 'كمية المرتجع أكبر من الكمية المتبقية من فاتورة الشراء للصنف: '.$item->product_name_snapshot.'.',
                    ]);
                }

                $balance = InventoryBalance::query()
                    ->where('organization_id', $data->organizationId)
                    ->where('branch_id', $data->branchId)
                    ->where('product_id', $item->product_id)
                    ->lockForUpdate()
                    ->first();

                $availableMilli = $balance ? DecimalQuantity::toMilli((string) $balance->quantity) : 0;
                if ($requestedMilli > $availableMilli) {
                    throw ValidationException::withMessages([
                        'items' => 'الكمية الحالية بالمخزون غير كافية لإرجاع الصنف للمورد: '.$item->product_name_snapshot.'.',
                    ]);
                }

                $originalLineCents = DecimalMoney::toCents((string) $item->line_total);
                $alreadyReturnedCents = DecimalMoney::toCents((string) ($previous?->returned_total ?? '0.00'));
                $remainingLineCents = max(0, $originalLineCents - $alreadyReturnedCents);
                $unitCostCents = DecimalMoney::toCents((string) $item->unit_cost);
                $lineCents = $requestedMilli === $remainingMilli
                    ? $remainingLineCents
                    : min($remainingLineCents, intdiv(($unitCostCents * $requestedMilli) + 500, 1000));

                $preparedItems[] = [
                    'item' => $item,
                    'quantity_milli' => $requestedMilli,
                    'line_cents' => $lineCents,
                    'current_balance_milli' => $availableMilli,
                ];
                $totalCents += $lineCents;
            }

            if ($totalCents > 99_999_999_999_999) {
                throw ValidationException::withMessages(['items' => 'قيمة المرتجع تتجاوز الحد المسموح.']);
            }

            $return = PurchaseReturn::query()->create([
                'organization_id' => $data->organizationId,
                'branch_id' => $data->branchId,
                'purchase_invoice_id' => $invoice->getKey(),
                'supplier_id' => $supplier->getKey(),
                'created_by' => $data->createdBy,
                'return_number' => 'PR-'.now()->format('Ymd').'-'.Str::upper((string) Str::ulid()),
                'client_operation_uuid' => $data->clientOperationUuid,
                'reason' => $data->reason,
                'status' => 'completed',
                'total' => DecimalMoney::fromCents($totalCents),
                'returned_at' => now(),
            ]);

            foreach ($preparedItems as $prepared) {
                /** @var PurchaseInvoiceItem $item */
                $item = $prepared['item'];
                $quantityMilli = $prepared['quantity_milli'];
                $quantity = DecimalQuantity::fromMilli($quantityMilli);
                $newBalanceMilli = $prepared['current_balance_milli'] - $quantityMilli;

                PurchaseReturnItem::query()->create([
                    'organization_id' => $data->organizationId,
                    'purchase_return_id' => $return->getKey(),
                    'purchase_invoice_item_id' => $item->getKey(),
                    'product_id' => $item->product_id,
                    'product_sku_snapshot' => $item->product_sku_snapshot,
                    'product_name_snapshot' => $item->product_name_snapshot,
                    'unit_name_snapshot' => $item->unit_name_snapshot,
                    'quantity' => $quantity,
                    'unit_cost' => $item->unit_cost,
                    'line_total' => DecimalMoney::fromCents($prepared['line_cents']),
                ]);

                InventoryBalance::query()
                    ->where('organization_id', $data->organizationId)
                    ->where('branch_id', $data->branchId)
                    ->where('product_id', $item->product_id)
                    ->lockForUpdate()
                    ->firstOrFail()
                    ->forceFill(['quantity' => DecimalQuantity::fromMilli($newBalanceMilli)])
                    ->save();

                InventoryMovement::query()->create([
                    'organization_id' => $data->organizationId,
                    'branch_id' => $data->branchId,
                    'product_id' => $item->product_id,
                    'created_by' => $data->createdBy,
                    'movement_type' => 'purchase_return',
                    'quantity_delta' => DecimalQuantity::fromMilli(-$quantityMilli),
                    'balance_after' => DecimalQuantity::fromMilli($newBalanceMilli),
                    'source' => 'purchase_return',
                    'reason' => 'إرجاع للمورد '.$return->return_number,
                    'reference_type' => PurchaseReturn::class,
                    'reference_id' => $return->getKey(),
                ]);
            }

            $totalCents = DecimalMoney::toCents((string) $return->total);
            $returnedCents = DecimalMoney::toCents((string) ($invoice->returned_amount ?? '0.00')) + $totalCents;
            $grossCents = DecimalMoney::toCents((string) $invoice->total);
            $paidCents = DecimalMoney::toCents((string) $invoice->paid_amount);
            $invoice->forceFill([
                'returned_amount' => DecimalMoney::fromCents($returnedCents),
                'due_amount' => DecimalMoney::fromCents(max(0, $grossCents - $paidCents - $returnedCents)),
            ])->save();

            $runningBalance = DecimalMoney::toCents((string) $supplier->transactions()->sum('credit'))
                - DecimalMoney::toCents((string) $supplier->transactions()->sum('debit'));
            $runningBalance -= $totalCents;

            SupplierTransaction::query()->create([
                'organization_id' => $data->organizationId,
                'supplier_id' => $supplier->getKey(),
                'branch_id' => $data->branchId,
                'created_by' => $data->createdBy,
                'transaction_type' => 'purchase_return',
                'debit' => DecimalMoney::fromCents($totalCents),
                'credit' => '0.00',
                'balance_after' => DecimalMoney::fromCents($runningBalance),
                'notes' => 'مرتجع شراء '.$return->return_number.' عن الفاتورة '.$invoice->invoice_number,
                'reference_type' => PurchaseReturn::class,
                'reference_id' => $return->getKey(),
            ]);

            return $return->load(['items', 'supplier', 'purchaseInvoice']);
        }, attempts: 3);
    }

    private function assertSameOperation(PurchaseReturn $existing, CreatePurchaseReturnData $data): void
    {
        $existingItems = $existing->items
            ->mapWithKeys(static fn (PurchaseReturnItem $item): array => [
                (int) $item->purchase_invoice_item_id => DecimalQuantity::toMilli((string) $item->quantity),
            ])->sortKeys()->all();
        $requestedItems = collect($data->items)->mapWithKeys(static fn (array $item): array => [
            (int) $item['purchase_invoice_item_id'] => DecimalQuantity::toMilli((string) $item['quantity']),
        ])->sortKeys()->all();

        if ((int) $existing->purchase_invoice_id !== $data->purchaseInvoiceId
            || $existing->reason !== $data->reason
            || $existingItems !== $requestedItems) {
            throw ValidationException::withMessages([
                'client_operation_uuid' => 'معرّف العملية استُخدم من قبل مع بيانات مختلفة.',
            ]);
        }
    }
}
