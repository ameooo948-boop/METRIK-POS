<?php

namespace App\Http\Requests\POS;

use App\Models\Organization;
use Illuminate\Foundation\Http\FormRequest;

class OpenCashShiftRequest extends FormRequest
{
    public function authorize(): bool
    {
        $organization = $this->attributes->get('currentOrganization');

        return $organization instanceof Organization
            && $this->user()?->can('usePos', $organization) === true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['opening_amount' => trim((string) $this->input('opening_amount', '0.00'))]);
    }

    public function rules(): array
    {
        return ['opening_amount' => ['required', 'numeric', 'min:0', 'max:999999999999.99', 'decimal:0,2']];
    }

    public function attributes(): array { return ['opening_amount' => 'الرصيد الافتتاحي للوردية']; }
}
