<?php

namespace App\Data\Purchasing;

final readonly class CreatePurchaseReturnData
{
    /** @param list<array{purchase_invoice_item_id:int, quantity:string}> $items */
    public function __construct(
        public int $organizationId,
        public int $branchId,
        public int $createdBy,
        public int $purchaseInvoiceId,
        public string $clientOperationUuid,
        public string $reason,
        public array $items,
    ) {}

    /** @param array<string, mixed> $attributes */
    public static function fromValidated(array $attributes, int $organizationId, int $branchId, int $createdBy, int $purchaseInvoiceId): self
    {
        return new self(
            organizationId: $organizationId,
            branchId: $branchId,
            createdBy: $createdBy,
            purchaseInvoiceId: $purchaseInvoiceId,
            clientOperationUuid: strtolower((string) $attributes['client_operation_uuid']),
            reason: trim((string) $attributes['reason']),
            items: array_map(static fn (array $item): array => [
                'purchase_invoice_item_id' => (int) $item['purchase_invoice_item_id'],
                'quantity' => (string) $item['quantity'],
            ], $attributes['items']),
        );
    }
}
