<?php

namespace App\Http\Requests\Sales;

use App\Models\Organization;
use App\Models\Sale;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSaleReturnRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var Organization|null $organization */
        $organization = $this->attributes->get('currentOrganization');
        $branch = $this->attributes->get('currentBranch');

        if (! $organization instanceof Organization || ! $branch) {
            return false;
        }

        $sale = Sale::query()
            ->forOrganization((int) $organization->getKey())
            ->where('branch_id', $branch->getKey())
            ->whereKey((int) $this->route('saleId'))
            ->first();

        return $sale !== null && $this->user()?->can('createReturn', $sale) === true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'client_operation_uuid' => strtolower(trim((string) $this->input('client_operation_uuid', ''))),
            'refund_method' => trim((string) $this->input('refund_method', 'cash')),
            'refund_reference' => trim((string) $this->input('refund_reference', '')) ?: null,
            'reason' => trim((string) $this->input('reason', '')),
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
        $organizationId = (int) ($organization?->getKey() ?? 0);
        $saleId = (int) $this->route('saleId');

        return [
            'client_operation_uuid' => ['required', 'uuid'],
            'refund_method' => ['required', Rule::in(['cash', 'card', 'mobile_wallet', 'other'])],
            'refund_reference' => ['nullable', 'required_if:refund_method,card,mobile_wallet,other', 'string', 'max:120'],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
            'items' => ['required', 'array', 'min:1', 'max:60'],
            'items.*' => ['required', 'array:sale_item_id,quantity,restock'],
            'items.*.sale_item_id' => [
                'required', 'integer', 'distinct',
                Rule::exists('sale_items', 'id')->where(fn ($query) => $query
                    ->where('organization_id', $organizationId)
                    ->where('sale_id', $saleId)),
            ],
            'items.*.quantity' => ['required', 'numeric', 'gt:0', 'max:10000', 'decimal:0,3'],
            'items.*.restock' => ['required', 'boolean'],
        ];
    }

    public function attributes(): array
    {
        return [
            'client_operation_uuid' => 'معرّف عملية المرتجع',
            'refund_method' => 'طريقة رد المبلغ',
            'refund_reference' => 'مرجع عملية الاسترداد',
            'reason' => 'سبب المرتجع',
            'items' => 'أصناف المرتجع',
            'items.*.sale_item_id' => 'بند فاتورة البيع',
            'items.*.quantity' => 'الكمية المرتجعة',
            'items.*.restock' => 'إعادة للمخزون',
        ];
    }
}
