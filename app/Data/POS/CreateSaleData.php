<?php

namespace App\Data\POS;

final readonly class CreateSaleData
{
    /** @param list<array{product_id:int,quantity:string}> $items */
    public function __construct(
        public int $organizationId,
        public int $branchId,
        public int $cashierId,
        public string $clientOperationUuid,
        public string $paymentMethod,
        public ?string $notes,
        public array $items,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromValidated(array $data, int $organizationId, int $branchId, int $cashierId): self
    {
        return new self(
            organizationId: $organizationId,
            branchId: $branchId,
            cashierId: $cashierId,
            clientOperationUuid: strtolower((string) $data['client_operation_uuid']),
            paymentMethod: (string) $data['payment_method'],
            notes: isset($data['notes']) && trim((string) $data['notes']) !== '' ? trim((string) $data['notes']) : null,
            items: array_map(static fn (array $item): array => [
                'product_id' => (int) $item['product_id'],
                'quantity' => (string) $item['quantity'],
            ], $data['items']),
        );
    }
}
