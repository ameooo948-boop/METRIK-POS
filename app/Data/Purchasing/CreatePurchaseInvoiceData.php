<?php

namespace App\Data\Purchasing;

final readonly class CreatePurchaseInvoiceData
{
    /** @param list<array{product_id:int, quantity:string, unit_cost:string, unit_id?:?int}> $items */
    public function __construct(
        public int $organizationId,
        public int $branchId,
        public int $createdBy,
        public int $supplierId,
        public ?string $supplierInvoiceNumber,
        public string $paidAmount,
        public ?string $notes,
        public array $items,
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
            'quantity' => (string) $item['quantity'],
            'unit_cost' => (string) $item['unit_cost'],
            'unit_id' => isset($item['unit_id']) && $item['unit_id'] !== '' ? (int) $item['unit_id'] : null,
        ], $data['items']);

        return new self(
            organizationId: $organizationId,
            branchId: $branchId,
            createdBy: $createdBy,
            supplierId: (int) $data['supplier_id'],
            supplierInvoiceNumber: isset($data['supplier_invoice_number']) && $data['supplier_invoice_number'] !== ''
                ? trim((string) $data['supplier_invoice_number'])
                : null,
            paidAmount: (string) ($data['paid_amount'] ?? '0.00'),
            notes: isset($data['notes']) && $data['notes'] !== '' ? trim((string) $data['notes']) : null,
            items: $items,
        );
    }
}
