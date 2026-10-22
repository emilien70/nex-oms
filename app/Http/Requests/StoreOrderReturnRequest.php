<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreOrderReturnRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'request_key' => ['required', 'uuid'], 'order_id' => ['nullable', 'integer', 'min:1'],
            'order_fingerprint' => ['required_with:order_id', 'nullable', 'string', 'size:64'],
            'reference_number' => ['nullable', 'string', 'max:255'], 'notes' => ['nullable', 'string', 'max:20000'],
            'buyer_name' => ['required_without:order_id', 'nullable', 'string', 'max:255'],
            'buyer_login' => ['nullable', 'string', 'max:255'], 'buyer_email' => ['nullable', 'email', 'max:255'],
            'buyer_phone' => ['nullable', 'string', 'max:255'], 'currency' => ['required_without:order_id', 'nullable', 'string', 'size:3'],
            'address' => ['nullable', 'array:name,company_name,street,building_number,apartment_number,postal_code,city,province,country_code'],
            'address.*' => ['nullable', 'string', 'max:255'],
            'items' => ['required', 'array', 'min:1', 'max:1000'], 'items.*' => ['required', 'array:order_item_id,product_id,name,quantity,unit_price,reason,comment'],
            'items.*.order_item_id' => ['nullable', 'integer', 'min:1'], 'items.*.product_id' => ['nullable', 'integer', 'exists:products,id'],
            'items.*.name' => ['nullable', 'string', 'max:10000'], 'items.*.quantity' => ['required', 'integer', 'min:0', 'max:1000000'],
            'items.*.unit_price' => ['nullable', 'string', 'regex:/^\d{1,12}(?:\.\d{1,2})?$/D'],
            'items.*.reason' => ['nullable', 'string', 'max:100'], 'items.*.comment' => ['nullable', 'string', 'max:20000'],
        ];
    }
}
