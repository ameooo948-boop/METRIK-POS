<?php

namespace App\Http\Requests\Inventory;

use App\Models\Organization;
use Illuminate\Foundation\Http\FormRequest;

class StoreInventoryStockCountRequest extends FormRequest
{
    public function authorize(): bool
    {
        $organization = $this->attributes->get('currentOrganization');

        return $organization instanceof Organization
            && $this->user()?->can('manageInventory', $organization) === true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'client_operation_uuid' => ['required', 'uuid'],
            'notes' => ['nullable', 'string', 'max:500'],
            'items' => ['required', 'array', 'min:1', 'max:1000'],
            'items.*' => ['required', 'array:product_id,expected_quantity,counted_quantity'],
            'items.*.product_id' => ['required', 'integer', 'min:1', 'distinct'],
            'items.*.expected_quantity' => ['required', 'string', 'regex:/^\d{1,11}(?:\.\d{1,3})?$/'],
            'items.*.counted_quantity' => ['required', 'string', 'regex:/^\d{1,11}(?:\.\d{1,3})?$/'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'client_operation_uuid.required' => 'معرّف عملية الجرد مطلوب.',
            'client_operation_uuid.uuid' => 'معرّف عملية الجرد غير صالح.',
            'items.required' => 'أضف صنفًا واحدًا على الأقل إلى الجرد.',
            'items.min' => 'أضف صنفًا واحدًا على الأقل إلى الجرد.',
            'items.max' => 'لا يمكن جرد أكثر من 1000 صنف في العملية الواحدة.',
            'items.*.product_id.distinct' => 'لا يمكن إضافة الصنف نفسه مرتين في نفس عملية الجرد.',
            'items.*.expected_quantity.regex' => 'كمية الرصيد قبل الجرد يجب أن تكون رقمًا موجبًا أو صفرًا وبحد أقصى 3 منازل عشرية.',
            'items.*.counted_quantity.regex' => 'الكمية الفعلية يجب أن تكون رقمًا موجبًا أو صفرًا وبحد أقصى 3 منازل عشرية.',
        ];
    }
}
