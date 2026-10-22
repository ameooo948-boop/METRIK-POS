<?php

namespace App\Data\POS;

final readonly class CloseCashShiftData
{
    /** @param list<array{denomination:string,count:int}>|null $cashCount */
    public function __construct(
        public int $organizationId,
        public int $branchId,
        public int $cashierId,
        public int $shiftId,
        public string $closingAmount,
        public ?array $cashCount = null,
        public ?string $closingNotes = null,
    ) {}
}
