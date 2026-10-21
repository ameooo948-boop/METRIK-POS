<?php

namespace App\Http\Requests\Suppliers;

use App\Models\Organization;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSupplierRequest extends FormRequest
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
            'name' => trim((string) $this->input('name', '')),
            'code' => trim((string) $this->input('code', '')) === '' ? null : strtoupper(trim((string) $this->input('code'))),
            'phone' => trim((string) $this->input('phone', '')) ?: null,
            'email' => trim((string) $this->input('email', '')) ?: null,
            'address' => trim((string) $this->input('address', '')) ?: null,
            'notes' => trim((string) $this->input('notes', '')) ?: null,
        ]);
    }

    public function rules(): array
    {
        /** @var Organization|null $organization */
        $organization = $this->attributes->get('currentOrganization');
        $organizationId = $organization?->getKey() ?? 0;

        return [
            'name' => ['required', 'string', 'max:180'],
            'code' => [
                'nullable', 'string', 'max:40', 'regex:/^[A-Z0-9._-]+$/',
                Rule::unique('suppliers', 'code')->where(
                    fn ($query) => $query->where('organization_id', $organizationId)
                ),
            ],
            'phone' => ['nullable', 'string', 'max:40'],
            'email' => ['nullable', 'email', 'max:180'],
            'address' => ['nullable', 'string', 'max:500'],
            'notes' => ['nullable', 'string', 'max:3000'],
        ];
    }

    public function attributes(): array
    {
        return [
            'name' => 'اسم المورد', 'code' => 'كود المورد', 'phone' => 'رقم الهاتف',
            'email' => 'البريد الإلكتروني', 'address' => 'العنوان', 'notes' => 'ملاحظات',
        ];
    }
}
