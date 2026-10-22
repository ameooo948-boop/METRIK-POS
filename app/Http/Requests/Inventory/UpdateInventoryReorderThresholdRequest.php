<?php

namespace App\Http\Requests\Inventory;

use Illuminate\Foundation\Http\FormRequest;

class UpdateInventoryReorderThresholdRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'reorder_threshold' => [
                'present',
                'nullable',
                'numeric',
                'min:0',
                'max:99999999999.999',
                'regex:/^\d{1,11}(?:\.\d{1,3})?$/',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'reorder_threshold.present' => 'أرسل حد إعادة الطلب أو اتركه فارغًا لاستخدام الإعداد الافتراضي.',
            'reorder_threshold.numeric' => 'حد إعادة الطلب يجب أن يكون رقمًا صالحًا.',
            'reorder_threshold.min' => 'لا يمكن أن يكون حد إعادة الطلب أقل من صفر.',
            'reorder_threshold.max' => 'حد إعادة الطلب أكبر من الحد المسموح.',
            'reorder_threshold.regex' => 'استخدم حتى 11 رقمًا صحيحًا و3 منازل عشرية كحد أقصى.',
        ];
    }
}
