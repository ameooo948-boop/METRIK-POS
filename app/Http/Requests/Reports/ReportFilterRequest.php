<?php

namespace App\Http\Requests\Reports;

use App\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class ReportFilterRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        /** @var Organization|null $organization */
        $organization = $this->attributes->get('currentOrganization');
        $timezone = $organization?->timezone ?: config('app.timezone');
        $today = now($timezone)->toDateString();
        $requestedTo = $this->input('to_date') ?: $today;
        $effectiveTo = $today;

        if (is_string($requestedTo)) {
            try {
                $effectiveTo = CarbonImmutable::createFromFormat('!Y-m-d', $requestedTo)->toDateString();
            } catch (\Throwable) {
                // Let the validator report an invalid supplied date below.
            }
        }

        $this->merge([
            'from_date' => $this->input('from_date') ?: CarbonImmutable::createFromFormat('!Y-m-d', $effectiveTo)->subDays(6)->toDateString(),
            'to_date' => $requestedTo,
            'branch_id' => $this->input('branch_id') ?: (string) ($this->attributes->get('currentBranch')?->getKey() ?? 'all'),
        ]);
    }

    public function authorize(): bool
    {
        $organization = $this->attributes->get('currentOrganization');

        return $organization instanceof Organization
            && $this->user()?->can('viewReports', $organization) === true;
    }

    public function rules(): array
    {
        $branches = collect($this->attributes->get('accessibleBranches', []));
        $allowedBranchIds = $branches->pluck('id')->map(static fn ($id): string => (string) $id)->all();
        $allowedBranchIds[] = 'all';

        return [
            'from_date' => ['required', 'date_format:Y-m-d'],
            'to_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:from_date'],
            'branch_id' => ['required', Rule::in($allowedBranchIds)],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $from = $this->input('from_date');
            $to = $this->input('to_date');

            if (! is_string($from) || ! is_string($to)) {
                return;
            }

            try {
                $days = CarbonImmutable::createFromFormat('!Y-m-d', $from)
                    ->diffInDays(CarbonImmutable::createFromFormat('!Y-m-d', $to));
            } catch (\Throwable) {
                return;
            }

            if ($days > 365) {
                $validator->errors()->add('to_date', 'أقصى فترة للتقرير 366 يومًا لتجنب الاستعلامات الثقيلة.');
            }
        });
    }

    public function messages(): array
    {
        return [
            'branch_id.in' => 'الفرع المحدد غير متاح لحسابك.',
            'to_date.after_or_equal' => 'تاريخ النهاية يجب أن يكون بعد تاريخ البداية أو مساويًا له.',
        ];
    }
}
