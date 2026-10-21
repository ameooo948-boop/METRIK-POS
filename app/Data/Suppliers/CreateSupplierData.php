<?php

namespace App\Data\Suppliers;

final readonly class CreateSupplierData
{
    public function __construct(
        public int $organizationId,
        public string $name,
        public ?string $code,
        public ?string $phone,
        public ?string $email,
        public ?string $address,
        public ?string $notes,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromValidated(array $data, int $organizationId): self
    {
        return new self(
            organizationId: $organizationId,
            name: trim((string) $data['name']),
            code: isset($data['code']) && $data['code'] !== '' ? strtoupper(trim((string) $data['code'])) : null,
            phone: isset($data['phone']) && $data['phone'] !== '' ? trim((string) $data['phone']) : null,
            email: isset($data['email']) && $data['email'] !== '' ? trim((string) $data['email']) : null,
            address: isset($data['address']) && $data['address'] !== '' ? trim((string) $data['address']) : null,
            notes: isset($data['notes']) && $data['notes'] !== '' ? trim((string) $data['notes']) : null,
        );
    }
}
