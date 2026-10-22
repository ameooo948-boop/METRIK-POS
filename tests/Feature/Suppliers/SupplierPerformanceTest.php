<?php

namespace Tests\Feature\Suppliers;

use Tests\TestCase;

class SupplierPerformanceTest extends TestCase
{
    public function test_supplier_performance_pages_require_authentication(): void
    {
        $this->get('/suppliers/performance')->assertRedirect('/login');
        $this->get('/suppliers/performance/export')->assertRedirect('/login');
    }
}
