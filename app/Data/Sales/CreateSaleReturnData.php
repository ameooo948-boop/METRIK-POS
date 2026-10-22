<?php

namespace App\Data\Sales;

final readonly class CreateSaleReturnData
{
    /** @param list<array{sale_item_id:int, quantity:string, restock:bool}> $items */
    public function __construct(
        public int $organizationId,
        public int $branchId,
        public int $saleId,
        public int $cashierId,
        public string $clientOperationUuid,
        public string $refundMethod,
        public ?string $refundReference,
        public string $reason,
        public array $items,
    ) {}

    /** @param array<string, mixed> $attributes */
    public static function fromValidated(
        array $attributes,
        int $organizationId,
        int $branchId,
        int $saleId,
        int $cashierId,
    ): self {
        return new self(
            organizationId: $organizationId,
            branchId: $branchId,
            saleId: $saleId,
            cashierId: $cashierId,
            clientOperationUuid: strtolower((string) $attributes['client_operation_uuid']),
            refundMethod: (string) $attributes['refund_method'],
            refundReference: isset($attributes['refund_reference']) && trim((string) $attributes['refund_reference']) !== ''
                ? trim((string) $attributes['refund_reference']) : null,
            reason: trim((string) $attributes['reason']),
            items: array_map(static fn (array $item): array => [
                'sale_item_id' => (int) $item['sale_item_id'],
                'quantity' => (string) $item['quantity'],
                'restock' => (bool) $item['restock'],
            ], $attributes['items']),
        );
    }
}
