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
            'payment_method' => ['required', Rule::in(['cash', 'card', 'mobile_wallet', 'other'])],
            'notes' => ['nullable', 'string', 'max:1000'],
            'items' => ['required', 'array', 'min:1', 'max:60'],
            'items.*' => ['required', 'array:product_id,quantity'],
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
            'items' => 'أصناف الفاتورة',
            'items.*.product_id' => 'المنتج',
            'items.*.quantity' => 'الكمية',
        ];
    }
}
