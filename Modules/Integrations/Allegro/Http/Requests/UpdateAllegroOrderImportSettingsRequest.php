<?php

namespace Modules\Integrations\Allegro\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateAllegroOrderImportSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'use_linked_products' => ['required', 'boolean'],
            'use_catalog_product_names' => ['required', 'boolean'],
            'use_catalog_vat' => ['required', 'boolean'],
        ];
    }
}
