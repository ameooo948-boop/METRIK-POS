<?php

namespace App\Http\Requests\Purchasing;

use App\Models\Organization;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePurchaseReturnRequest extends FormRequest
{
    public function authorize(): bool
    {
        $organization = $this->attributes->get('currentOrganization');

        return $organization instanceof Organization
            && $this->user()?->can('managePurchases', $organization) === true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'client_operation_uuid' => strtolower(trim((string) $this->input('client_operation_uuid', ''))),
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
        $invoiceId = (int) $this->route('purchaseInvoice');

        return [
            'client_operation_uuid' => ['required', 'uuid'],
            'reason' => ['required', 'string', 'max:500'],
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*' => ['required', 'array:purchase_invoice_item_id,quantity'],
            'items.*.purchase_invoice_item_id' => [
                'required', 'integer', 'distinct',
                Rule::exists('purchase_invoice_items', 'id')->where(fn ($query) => $query
                    ->where('organization_id', $organizationId)
                    ->where('purchase_invoice_id', $invoiceId)),
            ],
            'items.*.quantity' => ['required', 'numeric', 'gt:0', 'max:10000', 'decimal:0,3'],
        ];
    }
}
