<?php

namespace App\Contracts\Catalog;

use App\Data\Products\ProductCatalogFilters;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Persistence abstraction for read operations used by the product catalogue and POS.
 */
interface ProductRepository
{
    public function paginateForBranch(ProductCatalogFilters $filters): LengthAwarePaginator;

    /** @return Collection<int, \App\Models\Product> */
    public function searchActiveForBranch(
        int $organizationId,
        int $branchId,
        string $term,
        int $limit,
    ): Collection;

    /**
     * Fetches a page plus one look-ahead row so the caller can calculate next_after_id.
     * @return Collection<int, \App\Models\Product>
     */
    public function activeOfflineCatalogPage(
        int $organizationId,
        int $branchId,
        int $afterId,
        int $fetchLimit,
    ): Collection;
}
