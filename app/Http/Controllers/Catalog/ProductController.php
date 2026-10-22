<?php

namespace App\Http\Controllers\Catalog;

use App\Data\Products\CreateProductData;
use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\StoreProductRequest;
use App\Http\Requests\Catalog\UpdateProductRequest;
use App\Models\Branch;
use App\Models\Category;
use App\Models\Organization;
use App\Models\Product;
use App\Models\Unit;
use App\Services\Catalog\CreateProduct;
use App\Services\Catalog\ImportProductsCsv;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ProductController extends Controller
{
    public function index(Request $request): Response|RedirectResponse
    {
        /** @var Organization $organization */
        $organization = $request->attributes->get('currentOrganization');
        $this->authorize('viewCatalog', $organization);

        $branch = $request->attributes->get('currentBranch');
        if ($branch === null) {
            return redirect()->route('branches.index')->with('error', 'لا يوجد فرع متاح لهذا الحساب.');
        }

        $search = trim((string) $request->query('search', ''));
        if (mb_strlen($search) > 100) {
            $search = mb_substr($search, 0, 100);
        }

        $products = Product::query()
            ->forOrganization((int) $organization->getKey())
            ->with([
                'category:id,name',
                'baseUnit:id,code,name_ar,name_en',
                'barcodes:id,product_id,barcode,unit_id',
                'inventoryBalances' => fn ($query) => $query
                    ->where('branch_id', $branch->getKey())
                    ->select(['id', 'organization_id', 'branch_id', 'product_id', 'quantity', 'reorder_threshold']),
            ])
            ->search($search)
            ->orderBy('name')
            ->paginate(15)
            ->withQueryString();

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
                'lowStockThreshold' => $product->inventoryBalances->first()?->reorder_threshold ?? $product->low_stock_threshold,
                'globalLowStockThreshold' => $product->low_stock_threshold,
                'quantity' => $product->inventoryBalances->first()?->quantity ?? '0.000',
                'isActive' => $product->is_active,
            ];
        });

        return Inertia::render('Products/Index', [
            'organization' => $organization->only(['id', 'name', 'currency']),
            'currentBranch' => $branch->only(['id', 'name', 'code']),
            'products' => $products,
            'categories' => Category::query()->forOrganization((int) $organization->getKey())->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'allCategories' => Category::query()->forOrganization((int) $organization->getKey())->withCount('products')->orderBy('name')->get(['id', 'name', 'is_active'])->map(static fn (Category $category): array => [
                'id' => $category->getKey(),
                'name' => $category->name,
                'isActive' => (bool) $category->is_active,
                'productsCount' => (int) $category->products_count,
            ])->values(),
            'units' => Unit::query()->orderBy('id')->get(['id', 'code', 'name_ar', 'name_en']),
            'filters' => ['search' => $search],
            'canManageCatalog' => $request->user()->can('manageCatalog', $organization),
            'canManageInventory' => $request->user()->can('manageInventory', $organization),
            'flashMessage' => $request->session()->get('success'),
            'errorMessage' => $request->session()->get('error'),
            'importErrors' => $request->session()->get('importErrors', []),
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

    public function update(UpdateProductRequest $request, int $productId): RedirectResponse
    {
        /** @var Organization $organization */
        $organization = $request->attributes->get('currentOrganization');
        $data = $request->validated();

        DB::transaction(function () use ($organization, $productId, $data): void {
            $product = Product::query()
                ->forOrganization((int) $organization->getKey())
                ->whereKey($productId)
                ->lockForUpdate()
                ->firstOrFail();

            $product->fill([
                'name' => $data['name'],
                'sku' => $data['sku'],
                'description' => $data['description'] ?? null,
                'category_id' => $data['category_id'] ?? null,
                'purchase_price' => $data['purchase_price'],
                'selling_price' => $data['selling_price'],
                'low_stock_threshold' => $data['low_stock_threshold'],
            ])->save();
        });

        return redirect()->route('products.index')->with('success', 'تم تحديث بيانات المنتج بنجاح. لم يتم تغيير أرصدة المخزون أو سجل الحركات.');
    }

    public function updateStatus(Request $request, int $productId): RedirectResponse
    {
        /** @var Organization $organization */
        $organization = $request->attributes->get('currentOrganization');
        $this->authorize('manageCatalog', $organization);
        $validated = $request->validate(['is_active' => ['required', 'boolean']]);

        $product = Product::query()
            ->forOrganization((int) $organization->getKey())
            ->whereKey($productId)
            ->firstOrFail();
        $product->is_active = (bool) $validated['is_active'];
        $product->save();

        return redirect()->route('products.index')->with(
            'success',
            $product->is_active ? 'تمت إعادة تفعيل المنتج.' : 'تمت أرشفة المنتج وإيقاف بيعه الجديد مع الاحتفاظ بسجلاته.'
        );
    }

    public function export(Request $request): StreamedResponse|RedirectResponse
    {
        /** @var Organization $organization */
        $organization = $request->attributes->get('currentOrganization');
        $this->authorize('manageCatalog', $organization);

        $branch = $request->attributes->get('currentBranch');
        if ($branch === null) {
            return redirect()->route('branches.index')->with('error', 'لا يوجد فرع متاح لهذا الحساب.');
        }

        $headers = ImportProductsCsv::HEADERS;
        $organizationId = (int) $organization->getKey();
        $branchId = (int) $branch->getKey();

        return response()->streamDownload(function () use ($organizationId, $branchId, $headers): void {
            $output = fopen('php://output', 'wb');
            if ($output === false) {
                return;
            }

            fwrite($output, "\xEF\xBB\xBF");
            fputcsv($output, $headers);

            Product::query()
                ->forOrganization($organizationId)
                ->with([
                    'category:id,name',
                    'baseUnit:id,code',
                    'barcodes:id,product_id,barcode,unit_id',
                    'inventoryBalances' => fn ($query) => $query
                        ->where('branch_id', $branchId)
                        ->select(['id', 'organization_id', 'branch_id', 'product_id', 'quantity']),
                ])
                ->orderBy('id')
                ->chunkById(500, function ($products) use ($output): void {
                    foreach ($products as $product) {
                        $row = [
                            $product->sku,
                            $product->name,
                            $product->description,
                            $product->category?->name,
                            $product->baseUnit?->code,
                            $product->barcodes->first()?->barcode,
                            $product->purchase_price,
                            $product->selling_price,
                            $product->low_stock_threshold,
                            $product->inventoryBalances->first()?->quantity ?? '0.000',
                            $product->is_active ? '1' : '0',
                        ];

                        fputcsv($output, array_map(fn ($value): string => $this->safeCsvCell($value), $row));
                    }
                });

            fclose($output);
        }, 'metrik-products-'.now()->format('Ymd-His').'.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'private, no-store, max-age=0, must-revalidate',
        ]);
    }

    public function template(Request $request): StreamedResponse
    {
        /** @var Organization $organization */
        $organization = $request->attributes->get('currentOrganization');
        $this->authorize('manageCatalog', $organization);

        return response()->streamDownload(function (): void {
            $output = fopen('php://output', 'wb');
            if ($output === false) {
                return;
            }
            fwrite($output, "\xEF\xBB\xBF");
            fputcsv($output, ImportProductsCsv::HEADERS);
            fclose($output);
        }, 'metrik-products-template.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'private, no-store, max-age=0, must-revalidate',
        ]);
    }

    public function import(Request $request, CreateProduct $createProduct, ImportProductsCsv $importer): RedirectResponse
    {
        /** @var Organization $organization */
        $organization = $request->attributes->get('currentOrganization');
        $this->authorize('manageCatalog', $organization);

        /** @var Branch|null $branch */
        $branch = $request->attributes->get('currentBranch');
        if ($branch === null) {
            return redirect()->route('branches.index')->with('error', 'لا يوجد فرع متاح لهذا الحساب.');
        }

        $validated = $request->validate([
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:10240'],
        ], [
            'file.required' => 'اختار ملف CSV للاستيراد.',
            'file.file' => 'الملف المرفوع غير صالح.',
            'file.mimes' => 'صيغة الملف لازم تكون CSV أو TXT.',
            'file.max' => 'حجم الملف يجب ألا يتجاوز 10 ميجابايت.',
        ]);

        $result = $importer->handle(
            $validated['file'],
            $organization,
            $branch,
            (int) $request->user()->getKey(),
            $createProduct,
        );

        $message = sprintf(
            'انتهى الاستيراد: إنشاء %d، تحديث %d، وصفوف متجاوزة %d من أصل %d. الرصيد الافتتاحي يطبّق على المنتجات الجديدة فقط.',
            $result['created'],
            $result['updated'],
            $result['failed'],
            $result['processed'],
        );

        return redirect()->route('products.index')
            ->with($result['failed'] > 0 ? 'error' : 'success', $message)
            ->with('importErrors', $result['errors']);
    }

    private function safeCsvCell(mixed $value): string
    {
        $value = (string) ($value ?? '');

        if (preg_match('/^[\x09\x0D=+@\-]/u', $value) === 1) {
            return "'".$value;
        }

        return $value;
    }
}
