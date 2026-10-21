<?php

namespace App\Services\POS;

use App\Data\POS\OpenCashShiftData;
use App\Models\Branch;
use App\Models\CashShift;
use App\Models\User;
use App\Support\DecimalMoney;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OpenCashShift
{
    public function handle(OpenCashShiftData $data): CashShift
    {
        return DB::transaction(function () use ($data): CashShift {
            // Serializes shift opening for the same cashier even when no open row exists yet.
            User::query()->whereKey($data->cashierId)->lockForUpdate()->firstOrFail();

            $branch = Branch::query()
                ->where('organization_id', $data->organizationId)
                ->whereKey($data->branchId)
                ->where('status', 'active')
                ->firstOrFail();

            $alreadyOpen = CashShift::query()
                ->where('organization_id', $data->organizationId)
                ->where('branch_id', $branch->getKey())
                ->where('cashier_id', $data->cashierId)
                ->where('status', 'open')
                ->lockForUpdate()
                ->exists();

            if ($alreadyOpen) {
                throw ValidationException::withMessages([
                    'opening_amount' => 'لديك وردية مفتوحة بالفعل في هذا الفرع. أغلقها قبل فتح وردية جديدة.',
                ]);
            }

            return CashShift::query()->create([
                'organization_id' => $data->organizationId,
                'branch_id' => $branch->getKey(),
                'cashier_id' => $data->cashierId,
                'opened_by' => $data->cashierId,
                'status' => 'open',
                'opening_amount' => DecimalMoney::fromCents(DecimalMoney::toCents($data->openingAmount)),
                'opened_at' => now(),
            ]);
        }, attempts: 3);
    }
}
