<?php

namespace App\Http\Controllers\Catalog;

use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\StoreCategoryRequest;
use App\Models\Organization;
use App\Services\Catalog\CreateCategory;
use Illuminate\Http\RedirectResponse;

class CategoryController extends Controller
{
    public function store(StoreCategoryRequest $request, CreateCategory $createCategory): RedirectResponse
    {
        /** @var Organization $organization */
        $organization = $request->attributes->get('currentOrganization');

        $createCategory->handle($organization, (string) $request->validated('name'));

        return redirect()->route('products.index')->with('success', 'تم إنشاء التصنيف بنجاح.');
    }
}
