<?php

namespace App\Http\Requests\Customers;

use App\Models\Organization;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CollectCustomerAccountPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $organization = $this->attributes->get('currentOrganization');
        return $organization instanceof Organization && $this->user()?->can('manageCustomerAccounts', $organization) === true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'sale_id' => trim((string) $this->input('sale_id', '')) === '' ? null : $this->input('sale_id'),
            'operation_uuid' => strtolower(trim((string) $this->input('operation_uuid', ''))),
            'payment_method' => trim((string) $this->input('payment_method', 'cash')),
            'reference' => trim((string) $this->input('reference', '')) ?: null,
        ]);
    }

    public function rules(): array
    {
        /** @var Organization|null $organization */
        $organization = $this->attributes->get('currentOrganization');
        $organizationId = (int) ($organization?->getKey() ?? 0);
        $customerId = (int) $this->route('customerId');

        return [
            'sale_id' => [
                'nullable', 'integer',
                Rule::exists('sales', 'id')->where(fn ($query) => $query
                    ->where('organization_id', $organizationId)->where('customer_id', $customerId)),
            ],
            'operation_uuid' => ['required', 'uuid'],
            'amount' => ['required', 'numeric', 'gt:0', 'max:999999999999.99', 'decimal:0,2'],
            'payment_method' => ['required', Rule::in(['cash', 'card', 'mobile_wallet', 'other'])],
            'reference' => ['nullable', 'string', 'max:120'],
        ];
    }

    public function attributes(): array
    {
        return [
            'sale_id' => 'فاتورة العميل', 'operation_uuid' => 'معرّف التحصيل',
            'amount' => 'مبلغ التحصيل', 'payment_method' => 'طريقة التحصيل', 'reference' => 'مرجع التحصيل',
        ];
    }
}
