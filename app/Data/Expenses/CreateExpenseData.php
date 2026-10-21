<?php

namespace App\Data\Expenses;

final readonly class CreateExpenseData
{
    public function __construct(
        public int $organizationId,
        public int $branchId,
        public int $createdBy,
        public string $category,
        public string $description,
        public string $amount,
        public string $paymentMethod,
        public string $expenseDate,
        public ?string $notes,
    ) {}

    /** @param array<string, mixed> $attributes */
    public static function fromValidated(array $attributes, int $organizationId, int $branchId, int $createdBy): self
    {
        return new self(
            organizationId: $organizationId,
            branchId: $branchId,
            createdBy: $createdBy,
            category: (string) $attributes['category'],
            description: (string) $attributes['description'],
            amount: (string) $attributes['amount'],
            paymentMethod: (string) $attributes['payment_method'],
            expenseDate: (string) $attributes['expense_date'],
            notes: isset($attributes['notes']) ? (string) $attributes['notes'] : null,
        );
    }
}
