<?php

namespace Modules\Integrations\VonHalsky\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveVonHalskyImportSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'expected_lock_version' => ['required', 'integer', 'min:0'],
            'initial_status_setting_id' => ['nullable', 'integer', Rule::exists('order_status_settings', 'id')->whereNull('deleted_at')],
            'default_vat_rate' => ['nullable', 'string', 'regex:/\A(?:100(?:\.00)?|[0-9]{1,2}(?:\.[0-9]{1,2})?)\z/D'],
            'use_linked_products' => ['sometimes', 'required', 'boolean'],
            'use_catalog_product_names' => ['sometimes', 'required', 'boolean'],
            'use_catalog_vat' => ['sometimes', 'required', 'boolean'],
        ];
    }
}
