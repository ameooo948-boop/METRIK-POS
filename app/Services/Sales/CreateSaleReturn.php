<?php

namespace App\Services\Sales;

use App\Data\Sales\CreateSaleReturnData;
use App\Events\OperationalReportDataChanged;
use App\Models\CashMovement;
use App\Models\CashShift;
use App\Models\InventoryBalance;
use App\Models\InventoryMovement;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SaleReturn;
use App\Models\SaleReturnItem;
use App\Support\DecimalMoney;
use App\Support\DecimalQuantity;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CreateSaleReturn
{
    public function handle(CreateSaleReturnData $data): SaleReturn
    {
        try {
            $result = DB::transaction(function () use ($data): array {
                // Lock the sale row so two simultaneous returns cannot both consume the same remaining quantity.
                $sale = Sale::query()
                    ->forOrganization($data->organizationId)
                    ->where('branch_id', $data->branchId)
                    ->whereKey($data->saleId)
                    ->where('status', 'paid')
                    ->lockForUpdate()
                    ->firstOrFail();

                $existing = SaleReturn::query()
                    ->forOrganization($data->organizationId)
                    ->where('client_operation_uuid', $data->clientOperationUuid)
                    ->with('items')
                    ->lockForUpdate()
                    ->first();

                if ($existing) {
                    $this->assertSameOperation($existing, $data);

                    return [$existing, false];
                }

                $requestedIds = array_values(array_unique(array_map(
                    static fn (array $item): int => $item['sale_item_id'],
                    $data->items,
                )));
                sort($requestedIds, SORT_NUMERIC);

                $saleItems = SaleItem::query()
                    ->where('organization_id', $data->organizationId)
                    ->where('sale_id', $sale->getKey())
                    ->whereIn('id', $requestedIds)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get()
                    ->keyBy(static fn (SaleItem $item): int => (int) $item->getKey());

                if ($saleItems->count() !== count($requestedIds)) {
                    throw ValidationException::withMessages([
                        'items' => 'بعض بنود المرتجع لا تنتمي إلى فاتورة البيع المحددة.',
                    ]);
                }

                $previousReturns = SaleReturnItem::query()
                    ->join('sale_returns', 'sale_returns.id', '=', 'sale_return_items.sale_return_id')
                    ->where('sale_return_items.organization_id', $data->organizationId)
                    ->whereIn('sale_return_items.sale_item_id', $requestedIds)
                    ->where('sale_returns.status', 'completed')
                    ->selectRaw(
                        'sale_return_items.sale_item_id, COALESCE(SUM(sale_return_items.quantity), 0) AS returned_quantity, '
                        .'COALESCE(SUM(sale_return_items.line_total), 0) AS refunded_total, '
                        .'COALESCE(SUM(sale_return_items.tax_amount), 0) AS refunded_tax_total'
                    )
                    ->groupBy('sale_return_items.sale_item_id')
                    ->get()
                    ->keyBy(static fn ($row): int => (int) $row->sale_item_id);

                $cashShift = null;
                if ($data->refundMethod === 'cash') {
                    $cashShift = CashShift::query()
                        ->where('organization_id', $data->organizationId)
                        ->where('branch_id', $data->branchId)
                        ->where('cashier_id', $data->cashierId)
                        ->where('status', 'open')
                        ->lockForUpdate()
                        ->first();

                    if (! $cashShift) {
                        throw ValidationException::withMessages([
                            'refund_method' => 'لاسترداد المبلغ نقدًا، يجب فتح وردية كاشير في الفرع الحالي.',
                        ]);
                    }
                }

                $nextReturnNumber = 'RET-'.now()->format('Ymd').'-'.$sale->getKey().'-'.str_pad(
                    (string) ($sale->returns()->count() + 1), 3, '0', STR_PAD_LEFT,
                );

                $preparedItems = [];
                $totalCents = 0;
                $taxTotalCents = 0;

                foreach ($data->items as $requestedItem) {
                    $saleItemId = $requestedItem['sale_item_id'];
                    /** @var SaleItem $saleItem */
                    $saleItem = $saleItems->get($saleItemId);
                    $soldMilli = DecimalQuantity::toMilli((string) $saleItem->quantity);
                    $previous = $previousReturns->get($saleItemId);
                    $alreadyReturnedMilli = DecimalQuantity::toMilli((string) ($previous?->returned_quantity ?? '0.000'));
                    $requestedMilli = DecimalQuantity::toMilli($requestedItem['quantity']);
                    $availableMilli = $soldMilli - $alreadyReturnedMilli;

                    if ($requestedMilli > $availableMilli) {
                        throw ValidationException::withMessages([
                            'items' => 'الكمية المرتجعة أكبر من الكمية المتبقية للإرجاع للصنف: '.$saleItem->product_name_snapshot.'.',
                        ]);
                    }

                    $originalLineCents = DecimalMoney::toCents((string) $saleItem->line_total);
                    $alreadyRefundedCents = DecimalMoney::toCents((string) ($previous?->refunded_total ?? '0.00'));
                    $remainingRefundCents = max(0, $originalLineCents - $alreadyRefundedCents);

                    if ($requestedMilli === $availableMilli) {
                        // Give any rounding remainder to the last returned quantity, so total refunds never exceed the sale line total.
                        $lineRefundCents = $remainingRefundCents;
                    } else {
                        $lineRefundCents = intdiv(($originalLineCents * $requestedMilli) + intdiv($soldMilli, 2), $soldMilli);
                        $lineRefundCents = min($lineRefundCents, $remainingRefundCents);
                    }

                    // Tax is refunded proportionally from the original sale line, not
                    // recalculated at the organization's current rate. The final partial
                    // return receives any rounding remainder.
                    $originalTaxCents = DecimalMoney::toCents((string) $saleItem->tax_amount);
                    $alreadyRefundedTaxCents = DecimalMoney::toCents((string) ($previous?->refunded_tax_total ?? '0.00'));
                    $remainingTaxCents = max(0, $originalTaxCents - $alreadyRefundedTaxCents);
                    if ($requestedMilli === $availableMilli) {
                        $taxRefundCents = $remainingTaxCents;
                    } else {
                        $taxRefundCents = intdiv(($originalTaxCents * $requestedMilli) + intdiv($soldMilli, 2), $soldMilli);
                        $taxRefundCents = min($taxRefundCents, $remainingTaxCents);
                    }

                    $unitCostCents = DecimalMoney::toCents((string) $saleItem->unit_cost_snapshot);
                    $costCents = intdiv(($unitCostCents * $requestedMilli) + 500, 1000);
                    $restock = (bool) $requestedItem['restock'];
                    $factorMilli = DecimalQuantity::toMilli((string) ($saleItem->unit_conversion_factor_snapshot ?? '1.000'));
                    $baseQuantityMilli = intdiv(($requestedMilli * $factorMilli) + 500, 1000);

                    $preparedItems[] = [
                        'sale_item' => $saleItem,
                        'quantity_milli' => $requestedMilli,
                        'base_quantity_milli' => $baseQuantityMilli,
                        'line_refund_cents' => $lineRefundCents,
                        'tax_refund_cents' => $taxRefundCents,
                        'cost_cents' => $costCents,
                        'restock' => $restock,
                    ];
                    $totalCents += $lineRefundCents + $taxRefundCents;
                    $taxTotalCents += $taxRefundCents;
                }

                $return = SaleReturn::query()->create([
                    'organization_id' => $data->organizationId,
                    'branch_id' => $data->branchId,
                    'sale_id' => $sale->getKey(),
                    'cash_shift_id' => $cashShift?->getKey(),
                    'cashier_id' => $data->cashierId,
                    'return_number' => $nextReturnNumber,
                    'client_operation_uuid' => $data->clientOperationUuid,
                    'refund_method' => $data->refundMethod,
                    'refund_reference' => $data->refundReference,
                    'reason' => $data->reason,
                    'status' => 'completed',
                    'total' => DecimalMoney::fromCents($totalCents),
                    'tax_total' => DecimalMoney::fromCents($taxTotalCents),
                    'returned_at' => now(),
                ]);

                foreach ($preparedItems as $prepared) {
                    /** @var SaleItem $saleItem */
                    $saleItem = $prepared['sale_item'];
                    $quantity = DecimalQuantity::fromMilli($prepared['quantity_milli']);
                    $restock = $prepared['restock'];

                    SaleReturnItem::query()->create([
                        'organization_id' => $data->organizationId,
                        'sale_return_id' => $return->getKey(),
                        'sale_item_id' => $saleItem->getKey(),
                        'product_id' => $saleItem->product_id,
                        'product_sku_snapshot' => $saleItem->product_sku_snapshot,
                        'product_name_snapshot' => $saleItem->product_name_snapshot,
                        'quantity' => $quantity,
                        'unit_price' => $saleItem->unit_price,
                        'unit_cost_snapshot' => $saleItem->unit_cost_snapshot,
                        'line_total' => DecimalMoney::fromCents($prepared['line_refund_cents']),
                        'tax_amount' => DecimalMoney::fromCents($prepared['tax_refund_cents']),
                        'restock' => $restock,
                    ]);

                    if (! $restock) {
                        continue;
                    }

                    $balance = InventoryBalance::query()
                        ->where('organization_id', $data->organizationId)
                        ->where('branch_id', $data->branchId)
                        ->where('product_id', $saleItem->product_id)
                        ->lockForUpdate()
                        ->first();

                    if (! $balance) {
                        $balance = InventoryBalance::query()->create([
                            'organization_id' => $data->organizationId,
                            'branch_id' => $data->branchId,
                            'product_id' => $saleItem->product_id,
                            'quantity' => '0.000',
                        ]);
                    }

                    $baseQuantityMilli = $prepared['base_quantity_milli'];
                    $newBalanceMilli = DecimalQuantity::toMilli((string) $balance->quantity) + $baseQuantityMilli;
                    $balance->quantity = DecimalQuantity::fromMilli($newBalanceMilli);
                    $balance->save();

                    $return->inventoryMovements()->create([
                        'organization_id' => $data->organizationId,
                        'branch_id' => $data->branchId,
                        'product_id' => $saleItem->product_id,
                        'created_by' => $data->cashierId,
                        'movement_type' => 'sale_return',
                        'quantity_delta' => DecimalQuantity::fromMilli($baseQuantityMilli),
                        'balance_after' => DecimalQuantity::fromMilli($newBalanceMilli),
                        'source' => 'sale_return',
                        'reason' => 'إعادة مخزون من المرتجع '.$return->return_number,
                    ]);
                }

                if ($data->refundMethod === 'cash' && $totalCents > 0) {
                    CashMovement::query()->create([
                        'organization_id' => $data->organizationId,
                        'branch_id' => $data->branchId,
                        'cash_shift_id' => $cashShift->getKey(),
                        'sale_id' => null,
                        'sale_return_id' => $return->getKey(),
                        'created_by' => $data->cashierId,
                        'movement_type' => 'cash_return',
                        'amount' => DecimalMoney::fromCents($totalCents),
                        'notes' => 'رد نقدي للمرتجع '.$return->return_number,
                    ]);
                }

                $allItems = SaleItem::query()
                    ->where('organization_id', $data->organizationId)
                    ->where('sale_id', $sale->getKey())
                    ->get(['id', 'quantity']);

                $allReturned = SaleReturnItem::query()
                    ->join('sale_returns', 'sale_returns.id', '=', 'sale_return_items.sale_return_id')
                    ->where('sale_return_items.organization_id', $data->organizationId)
                    ->whereIn('sale_return_items.sale_item_id', $allItems->pluck('id'))
                    ->where('sale_returns.status', 'completed')
                    ->selectRaw('sale_item_id, COALESCE(SUM(quantity), 0) AS returned_quantity')
                    ->groupBy('sale_item_id')
                    ->pluck('returned_quantity', 'sale_item_id');

                $fullyReturned = $allItems->isNotEmpty() && $allItems->every(function (SaleItem $item) use ($allReturned): bool {
                    return DecimalQuantity::toMilli((string) ($allReturned->get($item->getKey()) ?? '0.000'))
                        >= DecimalQuantity::toMilli((string) $item->quantity);
                });

                $sale->forceFill(['return_status' => $fullyReturned ? 'returned' : 'partially_returned'])->save();

                return [$return->load('items'), true];
            }, attempts: 3);

            if ($result[1]) {
                event(new OperationalReportDataChanged($data->organizationId));
            }

            return $result[0];
        } catch (QueryException $exception) {
            // A concurrent request can race on the unique idempotency key; return it only if the payload matches.
            $existing = SaleReturn::query()
                ->forOrganization($data->organizationId)
                ->where('client_operation_uuid', $data->clientOperationUuid)
                ->with('items')
                ->first();

            if ($existing) {
                $this->assertSameOperation($existing, $data);
                return $existing;
            }

            throw $exception;
        }
    }

    private function assertSameOperation(SaleReturn $existing, CreateSaleReturnData $data): void
    {
        $saved = $existing->items->mapWithKeys(static fn (SaleReturnItem $item): array => [
            (int) $item->sale_item_id => [
                'quantity_milli' => DecimalQuantity::toMilli((string) $item->quantity),
                'restock' => (bool) $item->restock,
            ],
        ])->all();

        $requested = [];
        foreach ($data->items as $item) {
            $requested[$item['sale_item_id']] = [
                'quantity_milli' => DecimalQuantity::toMilli($item['quantity']),
                'restock' => (bool) $item['restock'],
            ];
        }
        ksort($saved, SORT_NUMERIC);
        ksort($requested, SORT_NUMERIC);

        if ((int) $existing->sale_id !== $data->saleId
            || $existing->refund_method !== $data->refundMethod
            || $existing->refund_reference !== $data->refundReference
            || $existing->reason !== $data->reason
            || $saved !== $requested) {
            throw ValidationException::withMessages([
                'client_operation_uuid' => 'تم استخدام معرّف المرتجع سابقًا مع بيانات مختلفة. أعد المحاولة بمعرّف جديد.',
            ]);
        }
    }
}
