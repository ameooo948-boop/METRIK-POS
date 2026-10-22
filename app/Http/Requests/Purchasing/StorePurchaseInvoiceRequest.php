<?php

namespace App\Http\Requests\Purchasing;

use App\Models\Organization;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePurchaseInvoiceRequest extends FormRequest
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
            'supplier_id' => [
                'required', 'integer',
                Rule::exists('suppliers', 'id')->where(
                    fn ($query) => $query->where('organization_id', $organizationId)
                        ->where('is_active', true)->whereNull('deleted_at')
                ),
            ],
            'supplier_invoice_number' => ['nullable', 'string', 'max:120'],
            'paid_amount' => ['required', 'numeric', 'min:0', 'max:999999999999.99', 'decimal:0,2'],
            'notes' => ['nullable', 'string', 'max:3000'],
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*' => ['required', 'array:product_id,quantity,unit_cost,unit_id'],
            'items.*.unit_id' => ['nullable', 'integer', Rule::exists('units', 'id')],
            'items.*.product_id' => [
                'required', 'integer', 'distinct',
                Rule::exists('products', 'id')->where(
                    fn ($query) => $query->where('organization_id', $organizationId)
                        ->where('is_active', true)->whereNull('deleted_at')
                ),
            ],
            'items.*.quantity' => ['required', 'numeric', 'min:0.001', 'max:10000', 'decimal:0,3'],
            'items.*.unit_cost' => ['required', 'numeric', 'min:0', 'max:9999999.99', 'decimal:0,2'],
        ];
    }

    public function attributes(): array
    {
        return [
            'supplier_id' => 'المورد',
            'supplier_invoice_number' => 'رقم فاتورة المورد',
            'paid_amount' => 'المبلغ المدفوع',
            'items' => 'أصناف الفاتورة',
            'items.*.product_id' => 'المنتج',
            'items.*.quantity' => 'الكمية',
            'items.*.unit_cost' => 'سعر شراء الوحدة المختارة',
            'items.*.unit_id' => 'وحدة القياس',
        ];
    }
}
