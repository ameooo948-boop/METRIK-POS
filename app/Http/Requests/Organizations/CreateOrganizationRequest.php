<?php

namespace App\Http\Requests\Organizations;

use Illuminate\Foundation\Http\FormRequest;

class CreateOrganizationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null
            && ! $this->user()->organizations()->exists();
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'organization_name' => trim((string) $this->input('organization_name', '')),
        ]);
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'organization_name' => ['required', 'string', 'max:255'],
        ];
    }

    public function attributes(): array
    {
        return [
            'organization_name' => 'اسم المؤسسة أو السوبر ماركت',
        ];
    }
}
