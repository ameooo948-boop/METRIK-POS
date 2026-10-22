<?php

namespace App\Http\Requests\Purchasing;

use App\Models\Organization;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ReceivePurchaseOrderRequest extends FormRequest
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
            'supplier_invoice_number' => trim((string) $this->input('supplier_invoice_number', '')) ?: null,
            'paid_amount' => trim((string) $this->input('paid_amount', '0.00')) === '' ? '0.00' : trim((string) $this->input('paid_amount', '0.00')),
            'notes' => trim((string) $this->input('notes', '')) ?: null,
        ]);
        $items = $this->input('items');
        if (is_array($items)) {
            $this->merge(['items' => array_map(static function ($item): mixed {
                if (! is_array($item)) {
                    return $item;
                }
                $item['quantity'] = trim((string) ($item['quantity'] ?? ''));
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
            'supplier_invoice_number' => ['nullable', 'string', 'max:120'],
            'paid_amount' => ['required', 'numeric', 'min:0', 'max:999999999999.99', 'decimal:0,2'],
            'notes' => ['nullable', 'string', 'max:3000'],
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*' => ['required', 'array:order_item_id,quantity'],
            'items.*.order_item_id' => ['required', 'integer', 'distinct', Rule::exists('purchase_order_items', 'id')->where(
                fn ($query) => $query->where('organization_id', $organizationId)
            )],
            'items.*.quantity' => ['required', 'numeric', 'min:0', 'max:100000', 'decimal:0,3'],
        ];
    }
}
