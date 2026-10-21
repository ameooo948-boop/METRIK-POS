<?php

namespace App\Data\Products;

/**
 * Immutable query input for the product catalogue use case.
 * Keeps HTTP request parsing out of persistence/query code.
 */
final readonly class ProductCatalogFilters
{
    public const PER_PAGE = 15;

    public function __construct(
        public int $organizationId,
        public int $branchId,
        public string $search,
        public int $perPage = self::PER_PAGE,
    ) {}

    /** @param array<string, mixed> $query */
    public static function fromQuery(array $query, int $organizationId, int $branchId): self
    {
        $rawSearch = $query['search'] ?? '';
        $search = is_string($rawSearch) ? trim($rawSearch) : '';

        if (mb_strlen($search) > 100) {
            $search = mb_substr($search, 0, 100);
        }

        return new self(
            organizationId: $organizationId,
            branchId: $branchId,
            search: $search,
        );
    }
}
