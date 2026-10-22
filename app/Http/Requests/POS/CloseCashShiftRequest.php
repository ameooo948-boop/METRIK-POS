<?php

namespace App\Http\Requests\POS;

use App\Models\Organization;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CloseCashShiftRequest extends FormRequest
{
    public function authorize(): bool
    {
        $organization = $this->attributes->get('currentOrganization');

        return $organization instanceof Organization
            && $this->user()?->can('usePos', $organization) === true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'closing_amount' => trim((string) $this->input('closing_amount', '')),
            'closing_notes' => trim((string) $this->input('closing_notes', '')),
        ]);
    }

    public function rules(): array
    {
        return [
            'closing_amount' => ['required', 'numeric', 'min:0', 'max:999999999999.99', 'decimal:0,2'],
            'closing_notes' => ['nullable', 'string', 'max:500'],
            'cash_count' => ['nullable', 'array', 'max:9'],
            'cash_count.*.denomination' => ['required', 'string', Rule::in(['200.00', '100.00', '50.00', '20.00', '10.00', '5.00', '1.00', '0.50', '0.25']), 'distinct'],
            'cash_count.*.count' => ['required', 'integer', 'min:0', 'max:10000'],
        ];
    }

    public function attributes(): array
    {
        return [
            'closing_amount' => 'النقدية الفعلية عند الإغلاق',
            'closing_notes' => 'ملاحظة إغلاق الوردية',
            'cash_count' => 'تفاصيل عدّ الفئات النقدية',
            'cash_count.*.denomination' => 'فئة النقدية',
            'cash_count.*.count' => 'عدد الأوراق أو العملات',
        ];
    }
}
