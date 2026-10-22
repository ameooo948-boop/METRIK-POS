<?php

namespace App\Http\Requests\Purchasing;

use App\Models\Organization;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePurchaseOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        $organization = $this->attributes->get('currentOrganization');

        return $organization instanceof Organization
            && $this->user()?->can('managePurchases', $organization) === true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'notes' => trim((string) $this->input('notes', '')) ?: null,
            'expected_at' => trim((string) $this->input('expected_at', '')) ?: null,
        ]);

        $items = $this->input('items');
        if (is_array($items)) {
            $this->merge(['items' => array_map(static function ($item): mixed {
                if (! is_array($item)) {
                    return $item;
                }
                $item['quantity'] = trim((string) ($item['quantity'] ?? ''));
                $item['unit_cost'] = trim((string) ($item['unit_cost'] ?? ''));
                return $item;
            }, $items)]);
        }
    }

    public function rules(): array
    {
        /** @var Organization|null $organization */
        $organization = $this->attributes->get('currentOrganization');
        $organizationId = $organization?->getKey() ?? 0;

        return [
            'supplier_id' => ['required', 'integer', Rule::exists('suppliers', 'id')->where(
                fn ($query) => $query->where('organization_id', $organizationId)->where('is_active', true)->whereNull('deleted_at')
            )],
            'expected_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:3000'],
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*' => ['required', 'array:product_id,unit_id,quantity,unit_cost'],
            'items.*.product_id' => ['required', 'integer', 'distinct', Rule::exists('products', 'id')->where(
                fn ($query) => $query->where('organization_id', $organizationId)->where('is_active', true)->whereNull('deleted_at')
            )],
            'items.*.unit_id' => ['required', 'integer', Rule::exists('units', 'id')],
            'items.*.quantity' => ['required', 'numeric', 'min:0.001', 'max:100000', 'decimal:0,3'],
            'items.*.unit_cost' => ['required', 'numeric', 'min:0', 'max:9999999.99', 'decimal:0,2'],
        ];
    }
}
