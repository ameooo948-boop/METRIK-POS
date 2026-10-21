<?php

namespace App\Data\Products;

final readonly class CreateProductData
{
    public function __construct(
        public int $organizationId,
        public int $branchId,
        public int $createdBy,
        public ?int $categoryId,
        public int $baseUnitId,
        public string $sku,
        public string $name,
        public ?string $description,
        public string $purchasePrice,
        public string $sellingPrice,
        public string $lowStockThreshold,
        public string $initialStock,
        public ?string $barcode,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromValidated(
        array $data,
        int $organizationId,
        int $branchId,
        int $createdBy,
    ): self {
        return new self(
            organizationId: $organizationId,
            branchId: $branchId,
            createdBy: $createdBy,
            categoryId: isset($data['category_id']) ? (int) $data['category_id'] : null,
            baseUnitId: (int) $data['base_unit_id'],
            sku: (string) $data['sku'],
            name: (string) $data['name'],
            description: isset($data['description']) ? (string) $data['description'] : null,
            purchasePrice: (string) $data['purchase_price'],
            sellingPrice: (string) $data['selling_price'],
            lowStockThreshold: (string) ($data['low_stock_threshold'] ?? '0'),
            initialStock: (string) ($data['initial_stock'] ?? '0'),
            barcode: isset($data['barcode']) ? (string) $data['barcode'] : null,
        );
    }
}
