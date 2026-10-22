<?php

namespace App\Http\Requests\Catalog;

use App\Models\Organization;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        $organization = $this->attributes->get('currentOrganization');

        return $organization instanceof Organization
            && $this->user()?->can('manageCatalog', $organization) === true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['name' => trim((string) $this->input('name', ''))]);
    }

    public function rules(): array
    {
        /** @var Organization|null $organization */
        $organization = $this->attributes->get('currentOrganization');

        return [
            'name' => [
                'required', 'string', 'max:120',
                Rule::unique('categories', 'name')->where(
                    fn ($query) => $query->where('organization_id', $organization?->getKey() ?? 0)
                )->ignore((int) $this->route('categoryId', 0)),
            ],
        ];
    }

    public function attributes(): array
    {
        return ['name' => 'اسم التصنيف'];
    }
}
