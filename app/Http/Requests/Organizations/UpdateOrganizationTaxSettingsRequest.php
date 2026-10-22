<?php

namespace App\Http\Requests\Organizations;

use App\Models\Organization;
use Illuminate\Foundation\Http\FormRequest;

class UpdateOrganizationTaxSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        $organization = $this->attributes->get('currentOrganization');

        return $organization instanceof Organization
            && $this->user()?->can('manageTaxSettings', $organization) === true;
    }

    protected function prepareForValidation(): void
    {
        $rate = $this->input('rate_percent', '0.00');

        // Normalize the decimal string without silently converting malformed checkbox
        // input to false; the boolean rule must reject values outside its accepted set.
        if (is_string($rate) || is_numeric($rate)) {
            $this->merge(['rate_percent' => trim((string) $rate)]);
        }
    }

    public function rules(): array
    {
        return [
            'enabled' => ['required', 'boolean'],
            'rate_percent' => ['required', 'numeric', 'min:0', 'max:100', 'decimal:0,2'],
        ];
    }

    public function attributes(): array
    {
        return [
            'enabled' => 'تفعيل ضريبة المبيعات',
            'rate_percent' => 'نسبة الضريبة',
        ];
    }
}
