<?php

namespace App\Http\Requests\Customers;

use App\Models\Organization;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveCustomerRequest extends FormRequest
{
    public function authorize(): bool
    {
        $organization = $this->attributes->get('currentOrganization');
        return $organization instanceof Organization && $this->user()?->can('manageCustomers', $organization) === true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'code' => trim((string) $this->input('code', '')) === '' ? null : strtoupper(trim((string) $this->input('code'))),
            'name' => trim((string) $this->input('name', '')),
            'phone' => trim((string) $this->input('phone', '')) ?: null,
            'email' => strtolower(trim((string) $this->input('email', ''))) ?: null,
            'address' => trim((string) $this->input('address', '')) ?: null,
            'notes' => trim((string) $this->input('notes', '')) ?: null,
        ]);
    }

    public function rules(): array
    {
        /** @var Organization|null $organization */
        $organization = $this->attributes->get('currentOrganization');
        $organizationId = (int) ($organization?->getKey() ?? 0);
        $uniqueCode = Rule::unique('customers', 'code')->where(fn ($query) => $query->where('organization_id', $organizationId));
        if ($this->isMethod('PATCH') || $this->isMethod('PUT')) {
            $uniqueCode->ignore((int) $this->route('customerId'));
        }

        $rules = [
            'code' => ['nullable', 'string', 'max:40', 'regex:/^[A-Z0-9._-]+$/', $uniqueCode],
            'name' => ['required', 'string', 'max:180'],
            'phone' => ['nullable', 'string', 'max:40'],
            'email' => ['nullable', 'email', 'max:180'],
            'address' => ['nullable', 'string', 'max:500'],
            'notes' => ['nullable', 'string', 'max:3000'],
            'credit_limit' => ['required', 'numeric', 'min:0', 'max:999999999999.99', 'decimal:0,2'],
            'is_active' => ['sometimes', 'boolean'],
        ];
        if ($this->isMethod('POST')) {
            $rules['opening_balance'] = ['nullable', 'numeric', 'min:0', 'max:999999999999.99', 'decimal:0,2'];
        }

        return $rules;
    }

    public function attributes(): array
    {
        return [
            'code' => 'كود العميل', 'name' => 'اسم العميل', 'phone' => 'رقم الهاتف',
            'email' => 'البريد الإلكتروني', 'address' => 'العنوان', 'notes' => 'ملاحظات',
            'credit_limit' => 'الحد الائتماني', 'opening_balance' => 'الرصيد الافتتاحي',
        ];
    }
}
