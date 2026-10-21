<?php

namespace App\Data\POS;

final readonly class CloseCashShiftData
{
    public function __construct(
        public int $organizationId,
        public int $branchId,
        public int $cashierId,
        public int $shiftId,
        public string $closingAmount,
    ) {}
}
