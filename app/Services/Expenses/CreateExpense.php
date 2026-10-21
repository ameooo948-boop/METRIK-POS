<?php

namespace App\Services\Expenses;

use App\Data\Expenses\CreateExpenseData;
use App\Events\OperationalReportDataChanged;
use App\Models\Branch;
use App\Models\CashMovement;
use App\Models\CashShift;
use App\Models\Expense;
use App\Support\DecimalMoney;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CreateExpense
{
    public function handle(CreateExpenseData $data): Expense
    {
        $expense = DB::transaction(function () use ($data): Expense {
            $branch = Branch::query()
                ->where('organization_id', $data->organizationId)
                ->whereKey($data->branchId)
                ->where('status', 'active')
                ->firstOrFail();

            $amountCents = DecimalMoney::toCents($data->amount);
            if ($amountCents <= 0) {
                throw ValidationException::withMessages(['amount' => 'يجب أن يكون مبلغ المصروف أكبر من صفر.']);
            }

            $shift = null;
            if ($data->paymentMethod === 'cash') {
                $shift = CashShift::query()
                    ->where('organization_id', $data->organizationId)
                    ->where('branch_id', $branch->getKey())
                    ->where('cashier_id', $data->createdBy)
                    ->where('status', 'open')
                    ->lockForUpdate()
                    ->first();

                if (! $shift) {
                    throw ValidationException::withMessages([
                        'payment_method' => 'لتسجيل مصروف نقدي يجب أن تكون لديك وردية مفتوحة في الفرع الحالي. افتح وردية أو اختر وسيلة دفع غير نقدية.',
                    ]);
                }
            }

            $expense = Expense::query()->create([
                'organization_id' => $data->organizationId,
                'branch_id' => $branch->getKey(),
                'created_by' => $data->createdBy,
                'cash_shift_id' => $shift?->getKey(),
                'category' => $data->category,
                'description' => $data->description,
                'amount' => DecimalMoney::fromCents($amountCents),
                'payment_method' => $data->paymentMethod,
                'expense_date' => $data->expenseDate,
                'notes' => $data->notes,
            ]);

            if ($shift) {
                CashMovement::query()->create([
                    'organization_id' => $data->organizationId,
                    'branch_id' => $branch->getKey(),
                    'cash_shift_id' => $shift->getKey(),
                    'sale_id' => null,
                    'created_by' => $data->createdBy,
                    'movement_type' => 'cash_out',
                    'amount' => DecimalMoney::fromCents($amountCents),
                    'notes' => 'مصروف '.$data->category.': '.$data->description,
                ]);
            }

            return $expense;
        });

        event(new OperationalReportDataChanged($data->organizationId));

        return $expense;
    }
}
