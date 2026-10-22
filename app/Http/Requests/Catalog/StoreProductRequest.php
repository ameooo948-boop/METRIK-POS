<?php

namespace App\Http\Requests\Catalog;

use App\Models\Organization;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        $organization = $this->attributes->get('currentOrganization');

        return $organization instanceof Organization
            && $this->user()?->can('manageCatalog', $organization) === true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'name' => trim((string) $this->input('name', '')),
            'sku' => mb_strtoupper(trim((string) $this->input('sku', ''))),
            'description' => trim((string) $this->input('description', '')) ?: null,
            'barcode' => trim((string) $this->input('barcode', '')) ?: null,
            'category_id' => $this->input('category_id') === '' ? null : $this->input('category_id'),
            'purchase_price' => trim((string) $this->input('purchase_price', '0')),
            'selling_price' => trim((string) $this->input('selling_price', '0')),
            'low_stock_threshold' => trim((string) $this->input('low_stock_threshold', '0')),
            'initial_stock' => trim((string) $this->input('initial_stock', '0')),
        ]);
    }

    public function rules(): array
    {
        /** @var Organization|null $organization */
        $organization = $this->attributes->get('currentOrganization');
        $organizationId = $organization?->getKey() ?? 0;

        return [
            'name' => ['required', 'string', 'max:180'],
            'sku' => [
                'required', 'string', 'max:64', 'regex:/^[A-Z0-9._-]+$/',
                Rule::unique('products', 'sku')->where(
                    fn ($query) => $query->where('organization_id', $organizationId)
                ),
            ],
            'description' => ['nullable', 'string', 'max:5000'],
            'category_id' => [
                'nullable', 'integer',
                Rule::exists('categories', 'id')->where(
                    fn ($query) => $query->where('organization_id', $organizationId)->where('is_active', true)
                ),
            ],
            'base_unit_id' => ['required', 'integer', Rule::exists('units', 'id')],
            'barcode' => [
                'nullable', 'string', 'max:128', 'regex:/^[A-Za-z0-9._-]+$/',
                Rule::unique('product_barcodes', 'barcode')->where(
                    fn ($query) => $query->where('organization_id', $organizationId)
                ),
            ],
            'purchase_price' => ['required', 'numeric', 'min:0', 'max:9999999999.99', 'decimal:0,2'],
            'selling_price' => ['required', 'numeric', 'min:0', 'max:9999999999.99', 'decimal:0,2'],
            'low_stock_threshold' => ['required', 'numeric', 'min:0', 'max:99999999999.999', 'decimal:0,3'],
            'initial_stock' => ['required', 'numeric', 'min:0', 'max:99999999999.999', 'decimal:0,3'],
        ];
    }

    public function attributes(): array
    {
        return [
            'name' => 'اسم المنتج',
            'sku' => 'كود المنتج',
            'description' => 'الوصف',
            'category_id' => 'التصنيف',
            'base_unit_id' => 'وحدة القياس الأساسية',
            'barcode' => 'الباركود',
            'purchase_price' => 'سعر الشراء',
            'selling_price' => 'سعر البيع',
            'low_stock_threshold' => 'حد التنبيه للمخزون',
            'initial_stock' => 'الرصيد الافتتاحي',
        ];
    }
}
