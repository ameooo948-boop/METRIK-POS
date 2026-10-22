<?php

namespace App\Http\Controllers\Catalog;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\Product;
use App\Models\ProductBarcode;
use App\Models\ProductUnitConversion;
use App\Models\Unit;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class ProductUnitController extends Controller
{
    public function edit(Request $request, int $productId): Response
    {
        $organization = $this->organization($request);
        $this->authorize('manageCatalog', $organization);
        $product = $this->product($organization, $productId);
        $product->load(['baseUnit:id,code,name_ar,name_en', 'unitConversions.unit:id,code,name_ar,name_en', 'barcodes.unit:id,code,name_ar,name_en']);

        return Inertia::render('Products/Units', [
            'organization' => $organization->only(['id', 'name', 'currency']),
            'product' => [
                'id' => (int) $product->getKey(), 'sku' => $product->sku, 'name' => $product->name,
                'baseUnit' => $product->baseUnit?->only(['id', 'code', 'name_ar', 'name_en']),
                'purchasePrice' => (string) $product->purchase_price,
                'sellingPrice' => (string) $product->selling_price,
            ],
            'units' => Unit::query()->orderBy('name_ar')->get(['id', 'code', 'name_ar', 'name_en']),
            'conversions' => $product->unitConversions
                ->reject(static fn (ProductUnitConversion $conversion): bool => (int) $conversion->unit_id === (int) $product->base_unit_id)
                ->sortBy('unit_id')->map(static fn (ProductUnitConversion $conversion): array => [
                'id' => (int) $conversion->getKey(), 'unitId' => (int) $conversion->unit_id,
                'unitName' => $conversion->unit?->name_ar ?? $conversion->unit?->name_en ?? 'وحدة',
                'unitCode' => $conversion->unit?->code,
                'factorToBase' => (string) $conversion->factor_to_base,
                'purchasePrice' => $conversion->purchase_price === null ? '' : (string) $conversion->purchase_price,
                'sellingPrice' => $conversion->selling_price === null ? '' : (string) $conversion->selling_price,
                'isActive' => (bool) $conversion->is_active,
            ])->values(),
            'barcodes' => $product->barcodes->map(static fn (ProductBarcode $barcode): array => [
                'id' => (int) $barcode->getKey(), 'barcode' => $barcode->barcode,
                'unitId' => (int) $barcode->unit_id,
                'unitName' => $barcode->unit?->name_ar ?? $barcode->unit?->name_en ?? 'وحدة',
            ])->values(),
            'flashMessage' => $request->session()->get('success'),
            'errorMessage' => $request->session()->get('error'),
            'validationErrors' => $request->session()->get('errors')?->getBag('default')->toArray() ?? [],
        ]);
    }

    public function storeConversion(Request $request, int $productId): RedirectResponse
    {
        $organization = $this->organization($request);
        $this->authorize('manageCatalog', $organization);
        $product = $this->product($organization, $productId);
        $data = $request->validate([
            'unit_id' => ['required', 'integer', 'exists:units,id'],
            'factor_to_base' => ['required', 'numeric', 'gt:0', 'max:100000', 'decimal:0,3'],
            'purchase_price' => ['nullable', 'numeric', 'min:0', 'max:999999999999.99', 'decimal:0,2'],
            'selling_price' => ['nullable', 'numeric', 'min:0', 'max:999999999999.99', 'decimal:0,2'],
        ]);

        $unitId = (int) $data['unit_id'];
        $this->assertConversionUnit($product, $unitId);
        $conversion = ProductUnitConversion::query()->where('product_id', $product->getKey())->where('unit_id', $unitId)->first();
        $attributes = [
            'factor_to_base' => $data['factor_to_base'],
            'purchase_price' => ($data['purchase_price'] ?? '') === '' ? null : $data['purchase_price'],
            'selling_price' => ($data['selling_price'] ?? '') === '' ? null : $data['selling_price'],
            'is_active' => true,
        ];

        if ($conversion) {
            $conversion->fill($attributes)->save();
            $message = 'تم تحديث وحدة القياس وإعادة تفعيلها للمنتج.';
        } else {
            ProductUnitConversion::query()->create($attributes + [
                'product_id' => $product->getKey(), 'unit_id' => $unitId,
            ]);
            $message = 'تمت إضافة وحدة القياس للمنتج.';
        }

        return redirect()->route('products.units.edit', $product->getKey())->with('success', $message);
    }

    public function updateConversion(Request $request, int $productId, int $conversionId): RedirectResponse
    {
        $organization = $this->organization($request);
        $this->authorize('manageCatalog', $organization);
        $product = $this->product($organization, $productId);
        $conversion = ProductUnitConversion::query()->where('product_id', $product->getKey())->whereKey($conversionId)->firstOrFail();
        $this->assertConversionUnit($product, (int) $conversion->unit_id);
        $data = $request->validate([
            'factor_to_base' => ['required', 'numeric', 'gt:0', 'max:100000', 'decimal:0,3'],
            'purchase_price' => ['nullable', 'numeric', 'min:0', 'max:999999999999.99', 'decimal:0,2'],
            'selling_price' => ['nullable', 'numeric', 'min:0', 'max:999999999999.99', 'decimal:0,2'],
        ]);
        $conversion->fill([
            'factor_to_base' => $data['factor_to_base'],
            'purchase_price' => ($data['purchase_price'] ?? '') === '' ? null : $data['purchase_price'],
            'selling_price' => ($data['selling_price'] ?? '') === '' ? null : $data['selling_price'],
            'is_active' => true,
        ])->save();

        return redirect()->route('products.units.edit', $product->getKey())->with('success', 'تم حفظ بيانات الوحدة. عمليات البيع السابقة تحتفظ بمعامل التحويل الذي سُجل وقتها.');
    }

    public function destroyConversion(Request $request, int $productId, int $conversionId): RedirectResponse
    {
        $organization = $this->organization($request);
        $this->authorize('manageCatalog', $organization);
        $product = $this->product($organization, $productId);
        $conversion = ProductUnitConversion::query()->where('product_id', $product->getKey())->whereKey($conversionId)->firstOrFail();
        $this->assertConversionUnit($product, (int) $conversion->unit_id);

        if (ProductBarcode::query()->where('product_id', $product->getKey())->where('unit_id', $conversion->unit_id)->exists()) {
            throw ValidationException::withMessages([
                'unit_id' => 'احذف الباركودات المرتبطة بهذه الوحدة أولًا قبل إيقاف استخدامها.',
            ]);
        }

        $conversion->forceFill(['is_active' => false])->save();

        return redirect()->route('products.units.edit', $product->getKey())->with('success', 'تم إيقاف الوحدة دون حذف سجلها؛ الفواتير القديمة ستظل محفوظة.');
    }

    public function storeBarcode(Request $request, int $productId): RedirectResponse
    {
        $organization = $this->organization($request);
        $this->authorize('manageCatalog', $organization);
        $product = $this->product($organization, $productId);
        $data = $request->validate([
            'unit_id' => ['required', 'integer', 'exists:units,id'],
            'barcode' => [
                'required', 'string', 'max:128', 'regex:/^[A-Za-z0-9\-_.]+$/',
                Rule::unique('product_barcodes', 'barcode')->where('organization_id', $organization->getKey()),
            ],
        ], [
            'barcode.regex' => 'الباركود يجب أن يحتوي على حروف وأرقام وشرطة أو نقطة أو شرطة سفلية فقط.',
            'barcode.unique' => 'هذا الباركود مسجل بالفعل لمنتج داخل المؤسسة.',
        ]);
        $unitId = (int) $data['unit_id'];
        $this->assertProductUnit($product, $unitId);

        try {
            ProductBarcode::query()->create([
                'organization_id' => $organization->getKey(), 'product_id' => $product->getKey(),
                'unit_id' => $unitId, 'barcode' => trim($data['barcode']),
            ]);
        } catch (QueryException $exception) {
            throw ValidationException::withMessages(['barcode' => 'الباركود مسجل بالفعل في هذه المؤسسة.']);
        }

        return redirect()->route('products.units.edit', $product->getKey())->with('success', 'تم ربط الباركود بالمنتج ووحدة القياس المحددة.');
    }

    public function destroyBarcode(Request $request, int $productId, int $barcodeId): RedirectResponse
    {
        $organization = $this->organization($request);
        $this->authorize('manageCatalog', $organization);
        $product = $this->product($organization, $productId);
        ProductBarcode::query()->where('organization_id', $organization->getKey())
            ->where('product_id', $product->getKey())->whereKey($barcodeId)->firstOrFail()->delete();

        return redirect()->route('products.units.edit', $product->getKey())->with('success', 'تم حذف الباركود.');
    }

    private function organization(Request $request): Organization
    {
        $organization = $request->attributes->get('currentOrganization');
        abort_unless($organization instanceof Organization, 404);
        return $organization;
    }

    private function product(Organization $organization, int $productId): Product
    {
        return Product::query()->forOrganization((int) $organization->getKey())->whereKey($productId)->firstOrFail();
    }

    private function assertConversionUnit(Product $product, int $unitId): void
    {
        if ($unitId === (int) $product->base_unit_id) {
            throw ValidationException::withMessages(['unit_id' => 'هذه هي الوحدة الأساسية بالفعل؛ اختر وحدة أخرى للتحويل.']);
        }

        if (! Unit::query()->whereKey($unitId)->exists()) {
            throw ValidationException::withMessages(['unit_id' => 'وحدة القياس غير موجودة.']);
        }
    }

    private function assertProductUnit(Product $product, int $unitId): void
    {
        if ($unitId === (int) $product->base_unit_id) {
            return;
        }

        $activeConversionExists = ProductUnitConversion::query()
            ->where('product_id', $product->getKey())
            ->where('unit_id', $unitId)
            ->where('is_active', true)
            ->exists();

        if (! $activeConversionExists) {
            throw ValidationException::withMessages(['unit_id' => 'اختر الوحدة الأساسية أو وحدة تحويل مفعّلة لهذا المنتج.']);
        }
    }
}
