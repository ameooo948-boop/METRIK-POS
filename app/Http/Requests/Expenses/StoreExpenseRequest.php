<?php

namespace App\Http\Requests\Expenses;

use App\Models\Organization;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreExpenseRequest extends FormRequest
{
    public function authorize(): bool
    {
        $organization = $this->attributes->get('currentOrganization');

        return $organization instanceof Organization
            && $this->user()?->can('manageExpenses', $organization) === true;
    }

    protected function prepareForValidation(): void
    {
        $organization = $this->attributes->get('currentOrganization');
        $timezone = $organization?->timezone ?: config('app.timezone');

        $this->merge([
            'category' => trim((string) $this->input('category', '')),
            'description' => trim((string) $this->input('description', '')),
            'amount' => trim((string) $this->input('amount', '')),
            'payment_method' => trim((string) $this->input('payment_method', 'cash')),
            'expense_date' => trim((string) $this->input('expense_date', '')) ?: now($timezone)->toDateString(),
            'notes' => trim((string) $this->input('notes', '')) ?: null,
        ]);
    }

    public function rules(): array
    {
        $organization = $this->attributes->get('currentOrganization');
        $timezone = $organization instanceof Organization && $organization->timezone
            ? $organization->timezone
            : (string) config('app.timezone');
        $organizationToday = now($timezone)->toDateString();

        return [
            'category' => ['required', 'string', 'max:80'],
            'description' => ['required', 'string', 'max:180'],
            'amount' => ['required', 'numeric', 'gt:0', 'max:9999999999.99', 'decimal:0,2'],
            'payment_method' => ['required', Rule::in(['cash', 'bank_transfer', 'e_wallet', 'other'])],
            'expense_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.$organizationToday],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function attributes(): array
    {
        return [
            'category' => 'تصنيف المصروف',
            'description' => 'وصف المصروف',
            'amount' => 'المبلغ',
            'payment_method' => 'طريقة الدفع',
            'expense_date' => 'تاريخ المصروف',
            'notes' => 'ملاحظات',
        ];
    }
}
