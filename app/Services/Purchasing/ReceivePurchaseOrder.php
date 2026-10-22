<?php

namespace App\Services\Purchasing;

use App\Data\Purchasing\CreatePurchaseInvoiceData;
use App\Models\Product;
use App\Models\PurchaseInvoice;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Support\DecimalMoney;
use App\Support\DecimalQuantity;
use App\Support\ProductUnitOptions;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ReceivePurchaseOrder
{
    /** @param array<string, mixed> $data */
    public function handle(
        int $orderId,
        array $data,
        int $organizationId,
        int $branchId,
        int $userId,
        CreatePurchaseInvoice $createPurchaseInvoice,
    ): PurchaseOrder {
        return DB::transaction(function () use ($orderId, $data, $organizationId, $branchId, $userId, $createPurchaseInvoice): PurchaseOrder {
            $order = PurchaseOrder::query()->forOrganization($organizationId)->where('branch_id', $branchId)
                ->whereKey($orderId)->lockForUpdate()->firstOrFail();
            if (! in_array($order->status, ['ordered', 'partially_received'], true)) {
                throw ValidationException::withMessages(['order' => 'أمر الشراء غير قابل للاستلام في حالته الحالية.']);
            }

            $orderItems = PurchaseOrderItem::query()->where('organization_id', $organizationId)
                ->where('purchase_order_id', $order->getKey())->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $productIds = collect($data['items'])->map(static fn (array $row): int => (int) ($orderItems->get((int) $row['order_item_id'])?->product_id ?? 0))
                ->filter(static fn (int $id): bool => $id > 0)->unique()->sort()->values()->all();
            if (count($productIds) === 0) {
                throw ValidationException::withMessages(['items' => 'حدد صنفًا واحدًا على الأقل للاستلام.']);
            }

            $products = Product::query()->forOrganization($organizationId)->whereIn('id', $productIds)->orderBy('id')
                ->with(['baseUnit:id,name_ar,name_en', 'unitConversions.unit:id,name_ar,name_en'])
                ->lockForUpdate()->get()->keyBy(static fn (Product $product): int => (int) $product->getKey());

            $invoiceItems = [];
            $preparedQuantities = [];
            foreach ($data['items'] as $row) {
                $orderItemId = (int) $row['order_item_id'];
                /** @var PurchaseOrderItem|null $orderItem */
                $orderItem = $orderItems->get($orderItemId);
                if ($orderItem === null || (int) $orderItem->purchase_order_id !== (int) $order->getKey()) {
                    throw ValidationException::withMessages(['items' => 'يوجد صنف لا يتبع أمر الشراء المحدد.']);
                }
                $requestedMilli = DecimalQuantity::toMilli((string) $row['quantity']);
                if ($requestedMilli === 0) {
                    continue; // A zero quantity means this line was not delivered in this receipt.
                }
                $orderedMilli = DecimalQuantity::toMilli((string) $orderItem->quantity_ordered);
                $alreadyReceivedMilli = DecimalQuantity::toMilli((string) $orderItem->quantity_received);
                $remainingMilli = $orderedMilli - $alreadyReceivedMilli;
                if ($requestedMilli <= 0 || $requestedMilli > $remainingMilli) {
                    throw ValidationException::withMessages([
                        'items' => 'كمية الاستلام للصنف «'.$orderItem->product_name_snapshot.'» أكبر من الكمية المتبقية أو غير صالحة.',
                    ]);
                }

                /** @var Product|null $product */
                $product = $products->get((int) $orderItem->product_id);
                if ($product === null || ! (bool) $product->is_active) {
                    throw ValidationException::withMessages(['items' => 'يوجد منتج في أمر الشراء لم يعد نشطًا.']);
                }
                $unit = ProductUnitOptions::resolve($product, (int) $orderItem->unit_id);
                if ($unit === null || DecimalQuantity::toMilli((string) $unit['factorToBase']) !== DecimalQuantity::toMilli((string) $orderItem->unit_conversion_factor_snapshot)) {
                    throw ValidationException::withMessages([
                        'items' => 'تغيرت وحدة القياس أو معامل التحويل للصنف «'.$orderItem->product_name_snapshot.'». راجع وحدة المنتج قبل الاستلام.',
                    ]);
                }

                $invoiceItems[] = [
                    'product_id' => (int) $orderItem->product_id,
                    'quantity' => DecimalQuantity::fromMilli($requestedMilli),
                    'unit_cost' => (string) $orderItem->unit_cost,
                    'unit_id' => (int) $orderItem->unit_id,
                ];
                $preparedQuantities[$orderItemId] = $alreadyReceivedMilli + $requestedMilli;
            }

            if ($invoiceItems === []) {
                throw ValidationException::withMessages(['items' => 'يجب تسجيل كمية مستلمة موجبة لصنف واحد على الأقل.']);
            }

            $receiptCents = 0;
            foreach ($invoiceItems as $item) {
                $receiptCents += intdiv((DecimalQuantity::toMilli($item['quantity']) * DecimalMoney::toCents($item['unit_cost'])) + 500, 1000);
            }
            $paidCents = DecimalMoney::toCents((string) ($data['paid_amount'] ?? '0.00'));
            if ($paidCents > $receiptCents) {
                throw ValidationException::withMessages(['paid_amount' => 'المبلغ المدفوع لا يمكن أن يتجاوز إجمالي الاستلام الحالي.']);
            }

            $invoiceData = new CreatePurchaseInvoiceData(
                organizationId: $organizationId,
                branchId: $branchId,
                createdBy: $userId,
                supplierId: (int) $order->supplier_id,
                supplierInvoiceNumber: isset($data['supplier_invoice_number']) && $data['supplier_invoice_number'] !== '' ? (string) $data['supplier_invoice_number'] : null,
                paidAmount: (string) ($data['paid_amount'] ?? '0.00'),
                notes: trim((string) ($data['notes'] ?? '')) !== '' ? trim((string) $data['notes']) : 'استلام لأمر الشراء '.$order->order_number,
                items: $invoiceItems,
            );
            /** @var PurchaseInvoice $invoice */
            $invoice = $createPurchaseInvoice->handle($invoiceData);
            $invoice->purchase_order_id = $order->getKey();
            $invoice->save();

            foreach ($preparedQuantities as $orderItemId => $receivedMilli) {
                $orderItems->get((int) $orderItemId)->forceFill(['quantity_received' => DecimalQuantity::fromMilli($receivedMilli)])->save();
            }

            $orderItems->refresh();
            $allReceived = $orderItems->every(static fn (PurchaseOrderItem $item): bool => DecimalQuantity::toMilli((string) $item->quantity_received) >= DecimalQuantity::toMilli((string) $item->quantity_ordered));
            $order->status = $allReceived ? 'received' : 'partially_received';
            $order->received_at = $allReceived ? now() : null;
            $order->save();

            return $order->load(['supplier:id,name', 'branch:id,name', 'createdBy:id,name', 'items', 'invoices']);
        }, attempts: 3);
    }
}
