<?php

namespace App\Services\Catalog;

use App\Models\Category;
use App\Models\Organization;

class CreateCategory
{
    public function handle(Organization $organization, string $name): Category
    {
        return $organization->categories()->create(['name' => trim($name)]);
    }
}
