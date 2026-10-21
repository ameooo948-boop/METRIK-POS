<?php

namespace App\Services\Catalog;

use App\Contracts\Catalog\ProductRepository;
use App\Data\Products\ProductCatalogFilters;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/** Application use case: list products in the current organization/branch context. */
final class ListProducts
{
    public function __construct(private readonly ProductRepository $products) {}

    public function handle(ProductCatalogFilters $filters): LengthAwarePaginator
    {
        return $this->products->paginateForBranch($filters);
    }
}
