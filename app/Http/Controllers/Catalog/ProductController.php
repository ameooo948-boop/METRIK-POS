<?php

namespace App\Http\Controllers\Catalog;

use App\Data\Products\CreateProductData;
use App\Data\Products\ProductCatalogFilters;
use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\StoreProductRequest;
use App\Models\Category;
use App\Models\Organization;
use App\Models\Product;
use App\Models\Unit;
use App\Services\Catalog\CreateProduct;
use App\Services\Catalog\ListProducts;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ProductController extends Controller
{
    public function index(Request $request, ListProducts $listProducts): Response|RedirectResponse
    {
        /** @var Organization $organization */
        $organization = $request->attributes->get('currentOrganization');
        $this->authorize('viewCatalog', $organization);

        $branch = $request->attributes->get('currentBranch');
        if ($branch === null) {
            return redirect()->route('branches.index')->with('error', 'لا يوجد فرع متاح لهذا الحساب.');
        }

        $filters = ProductCatalogFilters::fromQuery(
            $request->query(),
            (int) $organization->getKey(),
            (int) $branch->getKey(),
        );
        $search = $filters->search;
        $products = $listProducts->handle($filters);

        $products->through(static function (Product $product): array {
            return [
                'id' => $product->getKey(),
                'sku' => $product->sku,
                'name' => $product->name,
                'description' => $product->description,
                'category' => $product->category?->only(['id', 'name']),
                'baseUnit' => $product->baseUnit?->only(['id', 'code', 'name_ar', 'name_en']),
                'barcodes' => $product->barcodes->map(fn ($barcode): array => $barcode->only(['id', 'barcode', 'unit_id']))->values(),
                'purchasePrice' => $product->purchase_price,
                'sellingPrice' => $product->selling_price,
                'lowStockThreshold' => $product->low_stock_threshold,
                'quantity' => $product->inventoryBalances->first()?->quantity ?? '0.000',
                'isActive' => $product->is_active,
            ];
        });

        return Inertia::render('Products/Index', [
            'organization' => $organization->only(['id', 'name', 'currency']),
            'currentBranch' => $branch->only(['id', 'name', 'code']),
            'products' => $products,
            'categories' => Category::query()->forOrganization((int) $organization->getKey())->orderBy('name')->get(['id', 'name']),
            'units' => Unit::query()->orderBy('id')->get(['id', 'code', 'name_ar', 'name_en']),
            'filters' => ['search' => $search],
            'canManageCatalog' => $request->user()->can('manageCatalog', $organization),
            'canManageInventory' => $request->user()->can('manageInventory', $organization),
            'flashMessage' => $request->session()->get('success'),
            'errorMessage' => $request->session()->get('error'),
        ]);
    }

    public function store(StoreProductRequest $request, CreateProduct $createProduct): RedirectResponse
    {
        /** @var Organization $organization */
        $organization = $request->attributes->get('currentOrganization');
        $branch = $request->attributes->get('currentBranch');

        if ($branch === null) {
            return redirect()->route('branches.index')->with('error', 'لا يوجد فرع متاح لهذا الحساب.');
        }

        $data = CreateProductData::fromValidated(
            $request->validated(),
            (int) $organization->getKey(),
            (int) $branch->getKey(),
            (int) $request->user()->getKey(),
        );

        $createProduct->handle($data);

        return redirect()->route('products.index')->with('success', 'تم حفظ المنتج وتسجيل رصيده الافتتاحي بنجاح.');
    }
}
