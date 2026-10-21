<?php

namespace App\Http\Requests\Inventory;

use App\Models\Organization;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AdjustStockRequest extends FormRequest
{
    public function authorize(): bool
    {
        $organization = $this->attributes->get('currentOrganization');
        $branch = $this->attributes->get('currentBranch');

        return $organization instanceof Organization
            && $branch !== null
            && $this->user()?->can('manageInventory', $organization) === true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'quantity' => trim((string) $this->input('quantity', '')),
            'reason' => trim((string) $this->input('reason', '')) ?: null,
        ]);
    }

    public function rules(): array
    {
        /** @var Organization|null $organization */
        $organization = $this->attributes->get('currentOrganization');

        return [
            'product_id' => [
                'required', 'integer',
                Rule::exists('products', 'id')->where(
                    fn ($query) => $query->where('organization_id', $organization?->getKey() ?? 0)
                        ->whereNull('deleted_at')
                ),
            ],
            'type' => ['required', Rule::in(['stock_in', 'stock_out'])],
            'quantity' => ['required', 'numeric', 'gt:0', 'max:99999999999.999', 'decimal:0,3'],
            'reason' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function attributes(): array
    {
        return [
            'product_id' => 'المنتج',
            'type' => 'نوع الحركة',
            'quantity' => 'الكمية',
            'reason' => 'سبب الحركة',
        ];
    }
}
