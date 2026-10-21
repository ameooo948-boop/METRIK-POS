<?php

namespace App\Http\Requests\Organizations;

use App\Enums\OrganizationRole;
use App\Models\Organization;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateTeamMemberRequest extends FormRequest
{
    public function authorize(): bool
    {
        $organization = $this->attributes->get('currentOrganization');

        return $organization instanceof Organization
            && $this->user()?->can('manageTeam', $organization) === true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'branch_ids' => is_array($this->input('branch_ids'))
                ? array_values(array_unique($this->input('branch_ids')))
                : $this->input('branch_ids'),
        ]);
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        /** @var Organization|null $organization */
        $organization = $this->attributes->get('currentOrganization');
        $assignableRoles = array_values(array_diff(OrganizationRole::values(), [OrganizationRole::OWNER->value]));

        return [
            'role' => ['required', 'string', Rule::in($assignableRoles)],
            'branch_ids' => ['sometimes', 'array', 'max:100'],
            'branch_ids.*' => [
                'integer',
                'distinct',
                Rule::exists('branches', 'id')->where(
                    fn ($query) => $query->where('organization_id', $organization?->getKey() ?? 0)
                ),
            ],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($this->input('role') !== OrganizationRole::ADMIN->value
                && (! is_array($this->input('branch_ids')) || count($this->input('branch_ids')) < 1)) {
                $validator->errors()->add('branch_ids', 'حدد فرعًا واحدًا على الأقل لهذا الموظف.');
            }
        });
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'role' => 'الدور الوظيفي',
            'branch_ids' => 'الفروع',
            'branch_ids.*' => 'الفرع',
        ];
    }
}
