<?php

namespace App\Repositories\Eloquent;

use App\Contracts\Catalog\ProductRepository;
use App\Data\Products\ProductCatalogFilters;
use App\Models\Product;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

final class EloquentProductRepository implements ProductRepository
{
    public function paginateForBranch(ProductCatalogFilters $filters): LengthAwarePaginator
    {
        return Product::query()
            ->forOrganization($filters->organizationId)
            ->with([
                'category:id,name',
                'baseUnit:id,code,name_ar,name_en',
                'barcodes:id,product_id,barcode,unit_id',
                'inventoryBalances' => fn ($query) => $query
                    ->where('branch_id', $filters->branchId)
                    ->select(['id', 'organization_id', 'branch_id', 'product_id', 'quantity']),
            ])
            ->search($filters->search)
            ->orderBy('name')
            ->paginate($filters->perPage)
            ->withQueryString();
    }

    public function searchActiveForBranch(
        int $organizationId,
        int $branchId,
        string $term,
        int $limit,
    ): Collection {
        $term = trim($term);
        $limit = max(1, min($limit, 100));

        return Product::query()
            ->forOrganization($organizationId)
            ->active()
            ->search($term)
            ->select(['id', 'organization_id', 'base_unit_id', 'sku', 'name', 'selling_price'])
            ->with([
                'baseUnit:id,name_ar,name_en',
                'barcodes:id,product_id,barcode',
                'inventoryBalances' => fn ($query) => $query
                    ->where('branch_id', $branchId)
                    ->select(['id', 'organization_id', 'branch_id', 'product_id', 'quantity']),
            ])
            ->orderBy('name')
            ->limit($limit)
            ->get();
    }

    public function activeOfflineCatalogPage(
        int $organizationId,
        int $branchId,
        int $afterId,
        int $fetchLimit,
    ): Collection {
        $fetchLimit = max(1, min($fetchLimit, 501));

        return Product::query()
            ->forOrganization($organizationId)
            ->active()
            ->where('id', '>', max(0, $afterId))
            ->select(['id', 'organization_id', 'base_unit_id', 'sku', 'name', 'selling_price'])
            ->with([
                'baseUnit:id,name_ar,name_en',
                'barcodes:id,product_id,barcode',
                'inventoryBalances' => fn ($query) => $query
                    ->where('branch_id', $branchId)
                    ->select(['id', 'organization_id', 'branch_id', 'product_id', 'quantity']),
            ])
            ->orderBy('id')
            ->limit($fetchLimit)
            ->get();
    }
}
