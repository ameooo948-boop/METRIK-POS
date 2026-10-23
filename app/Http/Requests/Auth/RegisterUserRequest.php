<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

class RegisterUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $normalized = [
            'email' => Str::lower(trim((string) $this->input('email', ''))),
            'name' => trim((string) $this->input('name', '')),
            'organization_name' => trim((string) $this->input('organization_name', '')),
        ];

        // Older clients that submit the original signup form remain owners by default.
        if (! array_key_exists('account_type', $this->all())) {
            $normalized['account_type'] = 'owner';
        }

        $this->merge($normalized);
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'account_type' => ['required', 'string', 'in:owner,employee'],
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'organization_name' => ['required_if:account_type,owner', 'nullable', 'string', 'max:255'],
            'password' => ['required', 'string', 'confirmed', Password::min(8)],
        ];
    }

    public function attributes(): array
    {
        return [
            'account_type' => 'نوع الحساب',
            'name' => 'الاسم',
            'email' => 'البريد الإلكتروني',
            'organization_name' => 'اسم المؤسسة أو السوبر ماركت',
            'password' => 'كلمة المرور',
        ];
    }
}
