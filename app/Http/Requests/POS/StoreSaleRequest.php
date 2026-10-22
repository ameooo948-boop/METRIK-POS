<?php

namespace App\Http\Requests\POS;

use App\Models\Organization;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSaleRequest extends FormRequest
{
    public function authorize(): bool
    {
        $organization = $this->attributes->get('currentOrganization');
        $branch = $this->attributes->get('currentBranch');

        return $organization instanceof Organization
            && $branch !== null
            && $this->user()?->can('usePos', $organization) === true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'client_operation_uuid' => strtolower(trim((string) $this->input('client_operation_uuid', ''))),
            'payment_method' => trim((string) $this->input('payment_method', 'cash')),
            'notes' => trim((string) $this->input('notes', '')) ?: null,
            'promotion_code' => strtoupper(trim((string) $this->input('promotion_code', ''))) ?: null,
            'manual_discount_percent' => trim((string) $this->input('manual_discount_percent', '0.00')) ?: '0.00',
            'expected_total' => trim((string) $this->input('expected_total', '')) ?: null,
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
            'client_operation_uuid' => ['required', 'uuid'],
            'payment_method' => ['required', Rule::in(['cash', 'card', 'mobile_wallet', 'other', 'credit'])],
            'customer_id' => [
                'nullable', 'required_if:payment_method,credit', 'integer',
                Rule::exists('customers', 'id')->where(fn ($query) => $query
                    ->where('organization_id', $organizationId)
                    ->where('is_active', true)
                    ->whereNull('deleted_at')),
            ],
            'notes' => ['nullable', 'string', 'max:1000'],
            'promotion_code' => ['nullable', 'string', 'max:40', 'regex:/^[A-Za-z0-9_-]+$/'],
            'manual_discount_percent' => ['nullable', 'numeric', 'min:0', 'max:100', 'decimal:0,2'],
            'expected_total' => ['nullable', 'numeric', 'min:0', 'max:999999999999.99', 'decimal:0,2'],
            'items' => ['required', 'array', 'min:1', 'max:60'],
            'items.*' => ['required', 'array:product_id,quantity,unit_id'],
            'items.*.unit_id' => ['nullable', 'integer', Rule::exists('units', 'id')],
            'items.*.product_id' => [
                'required', 'integer', 'distinct',
                Rule::exists('products', 'id')->where(fn ($query) => $query
                    ->where('organization_id', $organizationId)
                    ->where('is_active', true)
                    ->whereNull('deleted_at')),
            ],
            'items.*.quantity' => ['required', 'numeric', 'gt:0', 'max:10000', 'decimal:0,3'],
        ];
    }

    public function attributes(): array
    {
        return [
            'client_operation_uuid' => 'معرّف عملية البيع',
            'payment_method' => 'طريقة الدفع',
            'customer_id' => 'العميل',
            'promotion_code' => 'كود العرض',
            'manual_discount_percent' => 'نسبة الخصم اليدوي',
            'expected_total' => 'الإجمالي المتوقع',
            'items' => 'أصناف الفاتورة',
            'items.*.product_id' => 'المنتج',
            'items.*.unit_id' => 'وحدة القياس',
            'items.*.quantity' => 'الكمية',
        ];
    }
}
