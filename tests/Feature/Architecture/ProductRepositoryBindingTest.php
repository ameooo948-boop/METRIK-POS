<?php

namespace Tests\Feature\Architecture;

use App\Contracts\Catalog\ProductRepository;
use App\Repositories\Eloquent\EloquentProductRepository;
use App\Services\Catalog\ListProducts;
use Tests\TestCase;

class ProductRepositoryBindingTest extends TestCase
{
    public function test_container_resolves_product_repository_contract_and_use_case(): void
    {
        $repository = app(ProductRepository::class);
        $useCase = app(ListProducts::class);

        $this->assertInstanceOf(EloquentProductRepository::class, $repository);
        $this->assertInstanceOf(ListProducts::class, $useCase);
    }
}
