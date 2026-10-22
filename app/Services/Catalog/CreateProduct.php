<?php

namespace App\Services\Catalog;

use App\Data\Products\CreateProductData;
use App\Models\Branch;
use App\Models\Category;
use App\Models\InventoryBalance;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\ProductBarcode;
use App\Models\ProductUnitConversion;
use App\Models\Organization;
use App\Support\DecimalQuantity;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CreateProduct
{
    public function handle(CreateProductData $data): Product
    {
        return DB::transaction(function () use ($data): Product {
            $organization = Organization::query()->findOrFail($data->organizationId);
            $branch = Branch::query()
                ->where('organization_id', $data->organizationId)
                ->whereKey($data->branchId)
                ->where('status', 'active')
                ->firstOrFail();

            if ($data->categoryId !== null) {
                Category::query()
                    ->forOrganization($data->organizationId)
                    ->where('is_active', true)
                    ->whereKey($data->categoryId)
                    ->firstOrFail();
            }

            $product = Product::query()->create([
                'organization_id' => $data->organizationId,
                'category_id' => $data->categoryId,
                'base_unit_id' => $data->baseUnitId,
                'created_by' => $data->createdBy,
                'sku' => $data->sku,
                'name' => $data->name,
                'description' => $data->description,
                'purchase_price' => $data->purchasePrice,
                'selling_price' => $data->sellingPrice,
                'low_stock_threshold' => $data->lowStockThreshold,
                'is_active' => true,
            ]);

            ProductUnitConversion::query()->create([
                'product_id' => $product->getKey(),
                'unit_id' => $data->baseUnitId,
                'factor_to_base' => '1.000',
            ]);

            if ($data->barcode !== null && $data->barcode !== '') {
                ProductBarcode::query()->create([
                    'organization_id' => $data->organizationId,
                    'product_id' => $product->getKey(),
                    'unit_id' => $data->baseUnitId,
                    'barcode' => $data->barcode,
                ]);
            }

            foreach ($organization->branches()->where('status', 'active')->get(['id']) as $organizationBranch) {
                InventoryBalance::query()->firstOrCreate(
                    [
                        'organization_id' => $data->organizationId,
                        'branch_id' => $organizationBranch->getKey(),
                        'product_id' => $product->getKey(),
                    ],
                    ['quantity' => '0.000'],
                );
            }

            $initialMilli = DecimalQuantity::toMilli($data->initialStock);

            if ($initialMilli > 0) {
                $balance = InventoryBalance::query()
                    ->where('organization_id', $data->organizationId)
                    ->where('branch_id', $branch->getKey())
                    ->where('product_id', $product->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();

                $newMilli = DecimalQuantity::toMilli((string) $balance->quantity) + $initialMilli;
                $balance->quantity = DecimalQuantity::fromMilli($newMilli);
                $balance->save();

                InventoryMovement::query()->create([
                    'organization_id' => $data->organizationId,
                    'branch_id' => $branch->getKey(),
                    'product_id' => $product->getKey(),
                    'created_by' => $data->createdBy,
                    'movement_type' => 'opening',
                    'quantity_delta' => DecimalQuantity::fromMilli($initialMilli),
                    'balance_after' => DecimalQuantity::fromMilli($newMilli),
                    'source' => 'product_creation',
                    'reason' => 'رصيد افتتاحي عند إنشاء المنتج',
                ]);
            }

            return $product->load(['category', 'baseUnit', 'barcodes', 'unitConversions']);
        });
    }
}
