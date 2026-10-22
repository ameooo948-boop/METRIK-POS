<?php

namespace App\Http\Requests\Inventory;

use App\Models\Organization;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreStockTransferRequest extends FormRequest
{
    public function authorize(): bool
    {
        $organization = $this->attributes->get('currentOrganization');
        $branch = $this->attributes->get('currentBranch');

        return $organization instanceof Organization
            && $branch !== null
            && $this->user()?->can('manageInventory', $organization) === true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'client_operation_uuid' => strtolower(trim((string) $this->input('client_operation_uuid', ''))),
            'notes' => trim((string) $this->input('notes', '')) ?: null,
        ]);

        if (is_array($this->input('items'))) {
            $this->merge(['items' => array_map(static function ($item): mixed {
                if (! is_array($item)) {
                    return $item;
                }

                $item['quantity'] = trim((string) ($item['quantity'] ?? ''));

                return $item;
            }, $this->input('items'))]);
        }
    }

    public function rules(): array
    {
        /** @var Organization|null $organization */
        $organization = $this->attributes->get('currentOrganization');
        $branch = $this->attributes->get('currentBranch');
        $organizationId = (int) ($organization?->getKey() ?? 0);
        $sourceBranchId = (int) ($branch?->getKey() ?? 0);

        return [
            'destination_branch_id' => [
                'required', 'integer',
                Rule::exists('branches', 'id')->where(fn ($query) => $query
                    ->where('organization_id', $organizationId)
                    ->where('status', 'active')
                    ->where('id', '!=', $sourceBranchId)),
            ],
            'client_operation_uuid' => ['required', 'uuid'],
            'notes' => ['nullable', 'string', 'max:500'],
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*' => ['required', 'array:product_id,quantity'],
            'items.*.product_id' => [
                'required', 'integer', 'distinct',
                Rule::exists('products', 'id')->where(fn ($query) => $query
                    ->where('organization_id', $organizationId)
                    ->where('is_active', true)
                    ->whereNull('deleted_at')),
            ],
            'items.*.quantity' => ['required', 'numeric', 'gt:0', 'max:99999999999.999', 'decimal:0,3'],
        ];
    }

    public function attributes(): array
    {
        return [
            'destination_branch_id' => 'الفرع المستلم',
            'client_operation_uuid' => 'معرّف عملية التحويل',
            'items' => 'أصناف التحويل',
            'items.*.product_id' => 'المنتج',
            'items.*.quantity' => 'الكمية',
            'notes' => 'ملاحظات التحويل',
        ];
    }
}
