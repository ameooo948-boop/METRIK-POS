<?php

namespace App\Data\Inventory;

final readonly class CreateInventoryStockCountData
{
    /**
     * @param list<array{product_id:int, expected_quantity:string, counted_quantity:string}> $items
     */
    public function __construct(
        public int $organizationId,
        public int $branchId,
        public int $createdBy,
        public string $clientOperationUuid,
        public array $items,
        public ?string $notes,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromValidated(
        array $data,
        int $organizationId,
        int $branchId,
        int $createdBy,
    ): self {
        $items = array_map(static fn (array $item): array => [
            'product_id' => (int) $item['product_id'],
            'expected_quantity' => (string) $item['expected_quantity'],
            'counted_quantity' => (string) $item['counted_quantity'],
        ], $data['items']);

        return new self(
            organizationId: $organizationId,
            branchId: $branchId,
            createdBy: $createdBy,
            clientOperationUuid: (string) $data['client_operation_uuid'],
            items: $items,
            notes: isset($data['notes']) && $data['notes'] !== '' ? (string) $data['notes'] : null,
        );
    }
}
