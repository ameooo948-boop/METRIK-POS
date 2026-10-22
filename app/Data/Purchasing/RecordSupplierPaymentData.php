<?php

namespace App\Data\Purchasing;

final readonly class RecordSupplierPaymentData
{
    public function __construct(
        public int $organizationId,
        public int $branchId,
        public int $createdBy,
        public int $purchaseInvoiceId,
        public string $clientOperationUuid,
        public string $amount,
        public string $paymentMethod,
        public ?int $cashShiftId,
        public ?string $referenceNumber,
        public ?string $notes,
    ) {}

    /** @param array<string, mixed> $attributes */
    public static function fromValidated(array $attributes, int $organizationId, int $branchId, int $createdBy): self
    {
        return new self(
            organizationId: $organizationId,
            branchId: $branchId,
            createdBy: $createdBy,
            purchaseInvoiceId: (int) $attributes['purchase_invoice_id'],
            clientOperationUuid: strtolower((string) $attributes['client_operation_uuid']),
            amount: (string) $attributes['amount'],
            paymentMethod: (string) $attributes['payment_method'],
            cashShiftId: isset($attributes['cash_shift_id']) ? (int) $attributes['cash_shift_id'] : null,
            referenceNumber: isset($attributes['reference_number']) ? trim((string) $attributes['reference_number']) : null,
            notes: isset($attributes['notes']) ? trim((string) $attributes['notes']) : null,
        );
    }
}
