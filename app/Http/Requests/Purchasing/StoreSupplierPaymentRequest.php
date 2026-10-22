<?php

namespace App\Http\Requests\Purchasing;

use App\Models\Organization;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSupplierPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $organization = $this->attributes->get('currentOrganization');

        return $organization instanceof Organization
            && $this->user()?->can('manageSupplierPayments', $organization) === true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'client_operation_uuid' => strtolower(trim((string) $this->input('client_operation_uuid', ''))),
            'payment_method' => trim((string) $this->input('payment_method', 'bank_transfer')),
            'reference_number' => trim((string) $this->input('reference_number', '')) ?: null,
            'notes' => trim((string) $this->input('notes', '')) ?: null,
            'amount' => trim((string) $this->input('amount', '')),
        ]);
    }

    public function rules(): array
    {
        /** @var Organization|null $organization */
        $organization = $this->attributes->get('currentOrganization');
        $branch = $this->attributes->get('currentBranch');
        $organizationId = (int) ($organization?->getKey() ?? 0);
        $branchId = (int) ($branch?->getKey() ?? 0);

        return [
            'purchase_invoice_id' => [
                'required', 'integer',
                Rule::exists('purchase_invoices', 'id')->where(fn ($query) => $query
                    ->where('organization_id', $organizationId)
                    ->where('branch_id', $branchId)
                    ->where('status', 'received')),
            ],
            'client_operation_uuid' => ['required', 'uuid'],
            'amount' => ['required', 'numeric', 'gt:0', 'max:999999999999.99', 'decimal:0,2'],
            'payment_method' => ['required', Rule::in(['cash', 'bank_transfer', 'mobile_wallet', 'cheque'])],
            'cash_shift_id' => [
                'nullable', 'integer', 'required_if:payment_method,cash',
                Rule::exists('cash_shifts', 'id')->where(fn ($query) => $query
                    ->where('organization_id', $organizationId)
                    ->where('branch_id', $branchId)
                    ->where('status', 'open')),
            ],
            'reference_number' => ['nullable', 'string', 'max:120', 'required_unless:payment_method,cash'],
            'notes' => ['nullable', 'string', 'max:500'],
        ];
    }
}
