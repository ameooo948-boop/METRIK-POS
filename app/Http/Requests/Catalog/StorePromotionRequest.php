<?php

namespace App\Http\Requests\Catalog;

use App\Enums\OrganizationRole;
use App\Models\Organization;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StorePromotionRequest extends FormRequest
{
    public function authorize(): bool
    {
        $organization = $this->attributes->get('currentOrganization');

        return $organization instanceof Organization
            && $this->user()?->can('managePromotions', $organization) === true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'name' => trim((string) $this->input('name', '')),
            'code' => strtoupper(trim((string) $this->input('code', ''))) ?: null,
            'type' => trim((string) $this->input('type', '')),
            'product_ids' => is_array($this->input('product_ids')) ? array_values(array_unique(array_map('intval', $this->input('product_ids')))) : $this->input('product_ids'),
        ]);
    }

    public function rules(): array
    {
        /** @var Organization|null $organization */
        $organization = $this->attributes->get('currentOrganization');
        $organizationId = (int) ($organization?->getKey() ?? 0);

        return [
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'code' => [
                'nullable', 'string', 'max:40', 'regex:/^[A-Z0-9_-]+$/',
                Rule::unique('promotions', 'code')->where(fn ($query) => $query->where('organization_id', $organizationId)),
            ],
            'type' => ['required', Rule::in(['buy_x_get_y', 'product_percentage', 'bundle_percentage'])],
            'branch_id' => [
                'nullable', 'integer',
                Rule::exists('branches', 'id')->where(fn ($query) => $query->where('organization_id', $organizationId)),
            ],
            'unit_id' => ['nullable', 'integer', Rule::exists('units', 'id')],
            'product_ids' => ['required', 'array', 'min:1', 'max:40'],
            'product_ids.*' => [
                'required', 'integer', 'distinct',
                Rule::exists('products', 'id')->where(fn ($query) => $query
                    ->where('organization_id', $organizationId)
                    ->where('is_active', true)
                    ->whereNull('deleted_at')),
            ],
            'buy_quantity' => ['nullable', 'integer', 'min:1', 'max:1000', 'required_if:type,buy_x_get_y'],
            'free_quantity' => ['nullable', 'integer', 'min:1', 'max:1000', 'required_if:type,buy_x_get_y'],
            'discount_percent' => ['nullable', 'numeric', 'gt:0', 'max:90', 'decimal:0,2', 'required_if:type,product_percentage,bundle_percentage'],
            'priority' => ['nullable', 'integer', 'min:0', 'max:1000'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $type = (string) $this->input('type');
            $productIds = $this->input('product_ids', []);
            $unitId = $this->input('unit_id');

            if ($type === 'buy_x_get_y' && count(is_array($productIds) ? $productIds : []) !== 1) {
                $validator->errors()->add('product_ids', 'عرض اشترِ واحصل على المجاني يجب أن يرتبط بمنتج واحد فقط.');
            }

            if ($type === 'buy_x_get_y' && ! $unitId) {
                $validator->errors()->add('unit_id', 'حدد وحدة البيع التي ينطبق عليها عرض اشترِ واحصل على المجاني.');
            }

            if ($type === 'bundle_percentage' && count(is_array($productIds) ? $productIds : []) < 2) {
                $validator->errors()->add('product_ids', 'عرض المجموعة يحتاج إلى منتجين على الأقل.');
            }

            if ($unitId && is_array($productIds) && count($productIds) > 1) {
                $validator->errors()->add('unit_id', 'اختيار وحدة محددة متاح عند ربط العرض بمنتج واحد فقط.');
            }

            if ($unitId && is_array($productIds) && count($productIds) === 1) {
                /** @var Organization|null $organization */
                $organization = $this->attributes->get('currentOrganization');
                $product = $organization?->products()
                    ->whereKey((int) $productIds[0])
                    ->with('unitConversions')
                    ->first();
                $validUnit = $product && ((int) $product->base_unit_id === (int) $unitId
                    || $product->unitConversions->contains(static fn ($conversion): bool => (int) $conversion->unit_id === (int) $unitId && (bool) ($conversion->is_active ?? true)));
                if (! $validUnit) {
                    $validator->errors()->add('unit_id', 'وحدة القياس المحددة ليست وحدة نشطة لهذا المنتج.');
                }
            }
        });
    }
}
