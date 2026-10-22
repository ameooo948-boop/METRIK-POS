<?php

namespace App\Services\Customers;

use App\Events\OperationalReportDataChanged;
use App\Models\CashMovement;
use App\Models\CashShift;
use App\Models\Customer;
use App\Models\CustomerAccountTransaction;
use App\Models\Organization;
use App\Models\Sale;
use App\Models\SalePayment;
use App\Models\User;
use App\Support\DecimalMoney;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CollectCustomerAccountPayment
{
    /**
     * Collects against a selected credit invoice when saleId is provided. When it is null,
     * the payment is applied only to the customer's non-invoice/general balance (for example,
     * an opening balance), never silently allocated to open invoices.
     */
    public function handle(
        int $organizationId,
        ?int $branchId,
        int $userId,
        int $customerId,
        ?int $saleId,
        string $operationUuid,
        string $amount,
        string $paymentMethod,
        ?string $reference = null,
    ): Customer {
        $amountCents = DecimalMoney::toCents($amount);
        if ($amountCents <= 0) {
            throw ValidationException::withMessages(['amount' => 'قيمة التحصيل يجب أن تكون أكبر من صفر.']);
        }

        $customer = DB::transaction(function () use (
            $organizationId, $branchId, $userId, $customerId, $saleId, $operationUuid,
            $amountCents, $paymentMethod, $reference,
        ): Customer {
            $prior = CustomerAccountTransaction::query()
                ->where('organization_id', $organizationId)
                ->where('operation_uuid', $operationUuid)
                ->first();
            if ($prior) {
                $this->assertSameOperation($prior, $customerId, $saleId, $amountCents, $paymentMethod, $reference);
                return Customer::query()->forOrganization($organizationId)->findOrFail($customerId);
            }

            $customer = Customer::query()
                ->forOrganization($organizationId)
                ->whereKey($customerId)
                ->lockForUpdate()
                ->firstOrFail();

            // Re-check after the row lock so concurrent retries remain idempotent.
            $prior = CustomerAccountTransaction::query()
                ->where('organization_id', $organizationId)
                ->where('operation_uuid', $operationUuid)
                ->first();
            if ($prior) {
                $this->assertSameOperation($prior, $customerId, $saleId, $amountCents, $paymentMethod, $reference);
                return $customer;
            }

            $sale = null;
            if ($saleId !== null) {
                $sale = Sale::query()
                    ->forOrganization($organizationId)
                    ->where('customer_id', $customerId)
                    ->whereKey($saleId)
                    ->lockForUpdate()
                    ->firstOrFail();

                if (! in_array($sale->status, ['due', 'partially_paid'], true)) {
                    throw ValidationException::withMessages(['sale_id' => 'الفاتورة المحددة ليست فاتورة آجل مفتوحة.']);
                }

                $alreadyPaidCents = DecimalMoney::toCents((string) SalePayment::query()
                    ->where('organization_id', $organizationId)->where('sale_id', $sale->getKey())
                    ->sum('amount'));
                $dueCents = max(0, DecimalMoney::toCents((string) $sale->total) - $alreadyPaidCents);
                if ($amountCents > $dueCents) {
                    throw ValidationException::withMessages(['amount' => 'قيمة التحصيل أكبر من المتبقي على الفاتورة.']);
                }
            } else {
                // General collections may settle an opening balance, but not unpaid invoices.
                $openSales = Sale::query()->forOrganization($organizationId)
                    ->where('customer_id', $customerId)
                    ->whereIn('status', ['due', 'partially_paid'])
                    ->select(['id', 'total'])
                    ->withSum('payments as paid_total', 'amount')
                    ->get();
                $invoiceDueCents = 0;
                foreach ($openSales as $openSale) {
                    $invoiceDueCents += max(0,
                        DecimalMoney::toCents((string) $openSale->total)
                        - DecimalMoney::toCents((string) ($openSale->paid_total ?? '0.00'))
                    );
                }
                $generalBalanceCents = max(0, DecimalMoney::toCents((string) $customer->balance) - $invoiceDueCents);
                if ($amountCents > $generalBalanceCents) {
                    throw ValidationException::withMessages([
                        'sale_id' => 'اختر فاتورة آجل مفتوحة لتحصيلها، أو راجع الرصيد العام المتاح للتحصيل.',
                    ]);
                }
            }

            $currentBalanceCents = DecimalMoney::toCents((string) $customer->balance);
            if ($amountCents > $currentBalanceCents) {
                throw ValidationException::withMessages(['amount' => 'قيمة التحصيل أكبر من رصيد العميل المستحق.']);
            }

            $cashShift = null;
            if ($paymentMethod === 'cash') {
                if ($branchId === null) {
                    throw ValidationException::withMessages(['payment_method' => 'لا يوجد فرع حالي لاستلام النقدية.']);
                }
                $cashShift = CashShift::query()
                    ->where('organization_id', $organizationId)
                    ->where('branch_id', $branchId)
                    ->where('cashier_id', $userId)
                    ->where('status', 'open')
                    ->lockForUpdate()
                    ->first();
                if (! $cashShift) {
                    throw ValidationException::withMessages(['payment_method' => 'التحصيل النقدي يتطلب وردية كاشير مفتوحة باسم المستخدم الحالي.']);
                }
            }

            if ($sale) {
                SalePayment::query()->create([
                    'organization_id' => $organizationId,
                    'sale_id' => $sale->getKey(),
                    'payment_method' => $paymentMethod,
                    'amount' => DecimalMoney::fromCents($amountCents),
                    'payment_reference' => $reference,
                ]);
                $newPaidCents = DecimalMoney::toCents((string) SalePayment::query()
                    ->where('organization_id', $organizationId)->where('sale_id', $sale->getKey())
                    ->sum('amount'));
                $sale->forceFill([
                    'status' => $newPaidCents >= DecimalMoney::toCents((string) $sale->total)
                        ? 'paid' : 'partially_paid',
                ])->save();
            }

            $newBalanceCents = $currentBalanceCents - $amountCents;
            CustomerAccountTransaction::query()->create([
                'organization_id' => $organizationId,
                'branch_id' => $branchId,
                'customer_id' => $customerId,
                'sale_id' => $sale?->getKey(),
                'created_by' => $userId,
                'operation_uuid' => $operationUuid,
                'transaction_type' => 'payment',
                'debit_amount' => '0.00',
                'credit_amount' => DecimalMoney::fromCents($amountCents),
                'balance_after' => DecimalMoney::fromCents($newBalanceCents),
                'payment_method' => $paymentMethod,
                'reference' => $reference,
                'description' => $sale
                    ? 'تحصيل على الفاتورة '.$sale->invoice_number
                    : 'تحصيل من الرصيد العام للعميل',
            ]);
            $customer->forceFill(['balance' => DecimalMoney::fromCents($newBalanceCents)])->save();

            if ($paymentMethod === 'cash' && $cashShift) {
                CashMovement::query()->create([
                    'organization_id' => $organizationId,
                    'branch_id' => $branchId,
                    'cash_shift_id' => $cashShift->getKey(),
                    'sale_id' => $sale?->getKey(),
                    'created_by' => $userId,
                    'movement_type' => 'cash_in',
                    'amount' => DecimalMoney::fromCents($amountCents),
                    'notes' => 'تحصيل حساب العميل '.$customer->name.($sale ? ' — '.$sale->invoice_number : ''),
                ]);
            }

            return $customer->refresh();
        }, attempts: 3);

        event(new OperationalReportDataChanged($organizationId));

        return $customer;
    }

    private function assertSameOperation(
        CustomerAccountTransaction $transaction,
        int $customerId,
        ?int $saleId,
        int $amountCents,
        string $paymentMethod,
        ?string $reference,
    ): void {
        if ($transaction->transaction_type !== 'payment'
            || (int) $transaction->customer_id !== $customerId
            || (int) ($transaction->sale_id ?? 0) !== (int) ($saleId ?? 0)
            || DecimalMoney::toCents((string) $transaction->credit_amount) !== $amountCents
            || $transaction->payment_method !== $paymentMethod
            || ($transaction->reference ?? null) !== ($reference ?? null)) {
            throw ValidationException::withMessages([
                'operation_uuid' => 'معرّف التحصيل مستخدم لعملية أخرى. أعد المحاولة بمعرّف جديد.',
            ]);
        }
    }
}
