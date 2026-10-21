<?php

namespace App\Data\POS;

final readonly class OpenCashShiftData
{
    public function __construct(
        public int $organizationId,
        public int $branchId,
        public int $cashierId,
        public string $openingAmount,
    ) {}
}
