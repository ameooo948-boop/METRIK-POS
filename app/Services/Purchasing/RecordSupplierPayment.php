<?php

namespace App\Services\Purchasing;

use App\Data\Purchasing\RecordSupplierPaymentData;
use App\Models\CashMovement;
use App\Models\CashShift;
use App\Models\PurchaseInvoice;
use App\Models\Supplier;
use App\Models\SupplierPayment;
use App\Models\SupplierTransaction;
use App\Support\DecimalMoney;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class RecordSupplierPayment
{
    public function handle(RecordSupplierPaymentData $data): SupplierPayment
    {
        return DB::transaction(function () use ($data): SupplierPayment {
            $existing = SupplierPayment::query()
                ->forOrganization($data->organizationId)
                ->where('client_operation_uuid', $data->clientOperationUuid)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                $this->assertSameOperation($existing, $data);

                return $existing->load(['supplier', 'purchaseInvoice', 'cashShift']);
            }

            $invoice = PurchaseInvoice::query()
                ->forOrganization($data->organizationId)
                ->where('branch_id', $data->branchId)
                ->whereKey($data->purchaseInvoiceId)
                ->where('status', 'received')
                ->lockForUpdate()
                ->firstOrFail();

            $supplier = Supplier::query()
                ->forOrganization($data->organizationId)
                ->whereKey($invoice->supplier_id)
                ->lockForUpdate()
                ->firstOrFail();

            $amountCents = DecimalMoney::toCents($data->amount);
            $grossCents = DecimalMoney::toCents((string) $invoice->total);
            $paidCents = DecimalMoney::toCents((string) $invoice->paid_amount);
            $returnedCents = DecimalMoney::toCents((string) ($invoice->returned_amount ?? '0.00'));
            $dueCents = max(0, $grossCents - $paidCents - $returnedCents);

            if ($amountCents <= 0 || $amountCents > $dueCents) {
                throw ValidationException::withMessages([
                    'amount' => 'قيمة الدفعة يجب أن تكون موجبة وألا تتجاوز المستحق الحالي على الفاتورة.',
                ]);
            }

            $cashShift = null;
            if ($data->paymentMethod === 'cash') {
                $cashShift = CashShift::query()
                    ->where('organization_id', $data->organizationId)
                    ->where('branch_id', $data->branchId)
                    ->where('status', 'open')
                    ->whereKey($data->cashShiftId)
                    ->lockForUpdate()
                    ->first();

                if (! $cashShift) {
                    throw ValidationException::withMessages([
                        'cash_shift_id' => 'الوردية النقدية المحددة غير مفتوحة أو لا تتبع الفرع الحالي.',
                    ]);
                }
            }

            $paymentNumber = 'SP-'.now()->format('Ymd').'-'.Str::upper((string) Str::ulid());
            $payment = SupplierPayment::query()->create([
                'organization_id' => $data->organizationId,
                'branch_id' => $data->branchId,
                'supplier_id' => $supplier->getKey(),
                'purchase_invoice_id' => $invoice->getKey(),
                'cash_shift_id' => $cashShift?->getKey(),
                'created_by' => $data->createdBy,
                'payment_number' => $paymentNumber,
                'client_operation_uuid' => $data->clientOperationUuid,
                'payment_method' => $data->paymentMethod,
                'reference_number' => $data->referenceNumber,
                'amount' => DecimalMoney::fromCents($amountCents),
                'notes' => $data->notes,
                'paid_at' => now(),
            ]);

            $newPaidCents = $paidCents + $amountCents;
            $invoice->forceFill([
                'paid_amount' => DecimalMoney::fromCents($newPaidCents),
                'due_amount' => DecimalMoney::fromCents(max(0, $grossCents - $newPaidCents - $returnedCents)),
            ])->save();

            $runningBalance = DecimalMoney::toCents((string) $supplier->transactions()->sum('credit'))
                - DecimalMoney::toCents((string) $supplier->transactions()->sum('debit'));
            $runningBalance -= $amountCents;

            SupplierTransaction::query()->create([
                'organization_id' => $data->organizationId,
                'supplier_id' => $supplier->getKey(),
                'branch_id' => $data->branchId,
                'created_by' => $data->createdBy,
                'transaction_type' => 'payment',
                'debit' => DecimalMoney::fromCents($amountCents),
                'credit' => '0.00',
                'balance_after' => DecimalMoney::fromCents($runningBalance),
                'notes' => 'دفعة للمورد '.$payment->payment_number.' عن الفاتورة '.$invoice->invoice_number,
                'reference_type' => SupplierPayment::class,
                'reference_id' => $payment->getKey(),
            ]);

            if ($cashShift && $amountCents > 0) {
                CashMovement::query()->create([
                    'organization_id' => $data->organizationId,
                    'branch_id' => $data->branchId,
                    'cash_shift_id' => $cashShift->getKey(),
                    'sale_id' => null,
                    'sale_return_id' => null,
                    'supplier_payment_id' => $payment->getKey(),
                    'created_by' => $data->createdBy,
                    'movement_type' => 'cash_out',
                    'amount' => DecimalMoney::fromCents($amountCents),
                    'notes' => 'صرف نقدي للمورد '.$payment->payment_number,
                ]);
            }

            return $payment->load(['supplier', 'purchaseInvoice', 'cashShift']);
        }, attempts: 3);
    }

    private function assertSameOperation(SupplierPayment $existing, RecordSupplierPaymentData $data): void
    {
        $same = (int) $existing->purchase_invoice_id === $data->purchaseInvoiceId
            && DecimalMoney::toCents((string) $existing->amount) === DecimalMoney::toCents($data->amount)
            && $existing->payment_method === $data->paymentMethod
            && (int) ($existing->cash_shift_id ?? 0) === (int) ($data->cashShiftId ?? 0)
            && (string) ($existing->reference_number ?? '') === (string) ($data->referenceNumber ?? '');

        if (! $same) {
            throw ValidationException::withMessages([
                'client_operation_uuid' => 'معرّف العملية استُخدم من قبل مع بيانات مختلفة.',
            ]);
        }
    }
}
