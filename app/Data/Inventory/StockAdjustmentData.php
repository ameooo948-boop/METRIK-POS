<?php

namespace App\Data\Inventory;

final readonly class StockAdjustmentData
{
    public function __construct(
        public int $organizationId,
        public int $branchId,
        public int $productId,
        public int $createdBy,
        public string $type,
        public string $quantity,
        public ?string $reason,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromValidated(
        array $data,
        int $organizationId,
        int $branchId,
        int $createdBy,
    ): self {
        return new self(
            organizationId: $organizationId,
            branchId: $branchId,
            productId: (int) $data['product_id'],
            createdBy: $createdBy,
            type: (string) $data['type'],
            quantity: (string) $data['quantity'],
            reason: isset($data['reason']) ? (string) $data['reason'] : null,
        );
    }
}
