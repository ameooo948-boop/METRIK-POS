<?php

namespace App\Data\POS;

final readonly class CreateSaleData
{
    /** @param list<array{product_id:int,quantity:string,unit_id?:?int,price_snapshot?:string}> $items */
    public function __construct(
        public int $organizationId,
        public int $branchId,
        public int $cashierId,
        public string $clientOperationUuid,
        public string $paymentMethod,
        public ?string $notes,
        public array $items,
        public ?int $expectedCashShiftId = null,
        public ?string $offlineCreatedAt = null,
        public ?int $customerId = null,
        public ?string $promotionCode = null,
        public string $manualDiscountPercent = '0.00',
        public ?string $expectedTotal = null,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromValidated(
        array $data,
        int $organizationId,
        int $branchId,
        int $cashierId,
        ?int $expectedCashShiftId = null,
        ?string $offlineCreatedAt = null,
    ): self {
        return new self(
            organizationId: $organizationId,
            branchId: $branchId,
            cashierId: $cashierId,
            clientOperationUuid: strtolower((string) $data['client_operation_uuid']),
            paymentMethod: (string) $data['payment_method'],
            notes: isset($data['notes']) && trim((string) $data['notes']) !== '' ? trim((string) $data['notes']) : null,
            items: array_map(static function (array $item): array {
                $normalised = [
                    'product_id' => (int) $item['product_id'],
                    'quantity' => (string) $item['quantity'],
                    'unit_id' => isset($item['unit_id']) && $item['unit_id'] !== '' ? (int) $item['unit_id'] : null,
                ];

                if (array_key_exists('price_snapshot', $item)) {
                    $normalised['price_snapshot'] = (string) $item['price_snapshot'];
                }

                return $normalised;
            }, $data['items']),
            expectedCashShiftId: $expectedCashShiftId,
            offlineCreatedAt: $offlineCreatedAt,
            customerId: isset($data['customer_id']) && $data['customer_id'] !== '' ? (int) $data['customer_id'] : null,
            promotionCode: $offlineCreatedAt === null && isset($data['promotion_code']) && trim((string) $data['promotion_code']) !== '' ? strtoupper(trim((string) $data['promotion_code'])) : null,
            manualDiscountPercent: $offlineCreatedAt === null && isset($data['manual_discount_percent']) && trim((string) $data['manual_discount_percent']) !== '' ? (string) $data['manual_discount_percent'] : '0.00',
            expectedTotal: $offlineCreatedAt === null && isset($data['expected_total']) && trim((string) $data['expected_total']) !== '' ? (string) $data['expected_total'] : null,
        );
    }
}
