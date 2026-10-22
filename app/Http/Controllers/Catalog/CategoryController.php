<?php

namespace App\Http\Controllers\Catalog;

use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\StoreCategoryRequest;
use App\Http\Requests\Catalog\UpdateCategoryRequest;
use App\Models\Organization;
use App\Models\Category;
use Illuminate\Http\Request;
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

    public function update(UpdateCategoryRequest $request, int $categoryId): RedirectResponse
    {
        /** @var Organization $organization */
        $organization = $request->attributes->get('currentOrganization');
        $category = Category::query()->forOrganization((int) $organization->getKey())->whereKey($categoryId)->firstOrFail();
        $category->name = (string) $request->validated('name');
        $category->save();

        return redirect()->route('products.index')->with('success', 'تم تحديث التصنيف بنجاح.');
    }

    public function updateStatus(Request $request, int $categoryId): RedirectResponse
    {
        /** @var Organization $organization */
        $organization = $request->attributes->get('currentOrganization');
        $this->authorize('manageCatalog', $organization);
        $validated = $request->validate(['is_active' => ['required', 'boolean']]);
        $category = Category::query()->forOrganization((int) $organization->getKey())->whereKey($categoryId)->firstOrFail();
        $category->is_active = (bool) $validated['is_active'];
        $category->save();

        return redirect()->route('products.index')->with(
            'success',
            $category->is_active ? 'تمت إعادة تفعيل التصنيف.' : 'تمت أرشفة التصنيف، مع الاحتفاظ بربطه بالمنتجات القديمة.'
        );
    }
}
