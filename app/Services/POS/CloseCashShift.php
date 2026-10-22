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

            $cashCountSnapshot = null;
            if ($data->cashCount !== null && $data->cashCount !== []) {
                $cashCountSnapshot = [];
                $countedCents = 0;
                $allowedDenominations = ['200.00', '100.00', '50.00', '20.00', '10.00', '5.00', '1.00', '0.50', '0.25'];
                foreach ($data->cashCount as $row) {
                    $denomination = (string) ($row['denomination'] ?? '');
                    $count = (int) ($row['count'] ?? 0);
                    if (! in_array($denomination, $allowedDenominations, true)) {
                        throw ValidationException::withMessages(['cash_count' => 'فئة النقدية غير مدعومة.']);
                    }
                    if ($count < 0 || $count > 10000) {
                        throw ValidationException::withMessages(['cash_count' => 'عدد الأوراق أو العملات غير صالح.']);
                    }
                    if ($count === 0) {
                        continue;
                    }
                    $lineCents = DecimalMoney::toCents($denomination) * $count;
                    $countedCents += $lineCents;
                    $cashCountSnapshot[] = [
                        'denomination' => DecimalMoney::fromCents(DecimalMoney::toCents($denomination)),
                        'count' => $count,
                        'line_total' => DecimalMoney::fromCents($lineCents),
                    ];
                }
                if ($cashCountSnapshot === []) {
                    throw ValidationException::withMessages(['cash_count' => 'أدخل عدد فئة نقدية واحدة على الأقل أو عطّل تفاصيل العد.']);
                }
                if ($countedCents !== $closingCents) {
                    throw ValidationException::withMessages(['closing_amount' => 'إجمالي الفئات النقدية لا يساوي النقدية الفعلية المدخلة.']);
                }
            }

            $shift->forceFill([
                'status' => 'closed',
                'closed_by' => $data->cashierId,
                'expected_cash' => DecimalMoney::fromCents($expectedCents),
                'closing_amount' => DecimalMoney::fromCents($closingCents),
                'difference_amount' => DecimalMoney::fromCents($closingCents - $expectedCents),
                'cash_count_details' => $cashCountSnapshot,
                'closing_notes' => $data->closingNotes !== null && trim($data->closingNotes) !== '' ? trim($data->closingNotes) : null,
                'closed_at' => now(),
            ])->save();

            return $shift->refresh();
        }, attempts: 3);
    }
}
