<?php

namespace App\Http\Requests\POS;

use Illuminate\Validation\Rule;

class SyncOfflineSaleRequest extends StoreSaleRequest
{
    public function rules(): array
    {
        $rules = parent::rules();

        // Offline payloads must include the exact shift captured by the local device,
        // the local creation timestamp and the price shown from the cached catalog.
        $rules['payment_method'] = ['required', Rule::in(['cash', 'card', 'mobile_wallet', 'other'])];
        $rules['items.*'] = ['required', 'array:product_id,quantity,unit_id,price_snapshot'];
        $rules['items.*.unit_id'] = ['nullable', 'integer', \Illuminate\Validation\Rule::exists('units', 'id')];
        $rules['items.*.price_snapshot'] = ['required', 'numeric', 'min:0', 'max:9999999.99', 'decimal:0,2'];
        $rules['shift_id'] = ['required', 'integer', 'min:1'];
        $rules['offline_created_at'] = ['required', 'date'];

        return $rules;
    }

    public function attributes(): array
    {
        return array_merge(parent::attributes(), [
            'shift_id' => 'الوردية الأصلية',
            'offline_created_at' => 'وقت تسجيل البيع دون اتصال',
            'items.*.price_snapshot' => 'السعر المحفوظ محليًا',
        ]);
    }
}
