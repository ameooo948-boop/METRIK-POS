<?php

namespace Tests\Unit\Catalog;

use App\Data\Products\ProductCatalogFilters;
use PHPUnit\Framework\TestCase;

class ProductCatalogFiltersTest extends TestCase
{
    public function test_search_is_trimmed_and_limited_to_one_hundred_characters(): void
    {
        $filters = ProductCatalogFilters::fromQuery(
            ['search' => '  '.str_repeat('a', 120).'  '],
            12,
            34,
        );

        $this->assertSame(12, $filters->organizationId);
        $this->assertSame(34, $filters->branchId);
        $this->assertSame(100, mb_strlen($filters->search));
        $this->assertSame(str_repeat('a', 100), $filters->search);
        $this->assertSame(15, $filters->perPage);
    }

    public function test_non_string_search_is_treated_as_empty(): void
    {
        $filters = ProductCatalogFilters::fromQuery(['search' => ['invalid']], 1, 2);

        $this->assertSame('', $filters->search);
    }
}
