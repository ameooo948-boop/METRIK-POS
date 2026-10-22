<?php

namespace App\Data\Inventory;

final readonly class CreateStockTransferData
{
    /**
     * @param list<array{product_id:int, quantity:string}> $items
     */
    public function __construct(
        public int $organizationId,
        public int $sourceBranchId,
        public int $destinationBranchId,
        public int $createdBy,
        public string $clientOperationUuid,
        public array $items,
        public ?string $notes,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromValidated(
        array $data,
        int $organizationId,
        int $sourceBranchId,
        int $createdBy,
    ): self {
        $items = array_map(static fn (array $item): array => [
            'product_id' => (int) $item['product_id'],
            'quantity' => (string) $item['quantity'],
        ], $data['items']);

        return new self(
            organizationId: $organizationId,
            sourceBranchId: $sourceBranchId,
            destinationBranchId: (int) $data['destination_branch_id'],
            createdBy: $createdBy,
            clientOperationUuid: (string) $data['client_operation_uuid'],
            items: $items,
            notes: isset($data['notes']) && $data['notes'] !== '' ? (string) $data['notes'] : null,
        );
    }
}
