<?php

namespace App\Services\POS;

use App\Data\POS\CloseCashShiftData;
use App\Models\CashShift;
use App\Support\DecimalMoney;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CloseCashShift
{
    public function handle(CloseCashShiftData $data): CashShift
    {
        return DB::transaction(function () use ($data): CashShift {
            $shift = CashShift::query()
                ->where('organization_id', $data->organizationId)
                ->where('branch_id', $data->branchId)
                ->where('cashier_id', $data->cashierId)
                ->whereKey($data->shiftId)
                ->where('status', 'open')
                ->lockForUpdate()
                ->firstOrFail();

            $closingCents = DecimalMoney::toCents($data->closingAmount);
            $expectedCents = $shift->expectedCashCents();

            if ($closingCents > 99_999_999_999_999) {
                throw ValidationException::withMessages([
                    'closing_amount' => 'قيمة النقدية أكبر من الحد المسموح به.',
                ]);
            }

            $shift->forceFill([
                'status' => 'closed',
                'closed_by' => $data->cashierId,
                'expected_cash' => DecimalMoney::fromCents($expectedCents),
                'closing_amount' => DecimalMoney::fromCents($closingCents),
                'difference_amount' => DecimalMoney::fromCents($closingCents - $expectedCents),
                'closed_at' => now(),
            ])->save();

            return $shift->refresh();
        }, attempts: 3);
    }
}
