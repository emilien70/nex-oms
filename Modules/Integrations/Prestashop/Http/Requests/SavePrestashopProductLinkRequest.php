<?php

namespace Modules\Integrations\Prestashop\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SavePrestashopProductLinkRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'external_product_id' => ['required', 'integer', 'min:1'],
        ];
    }

    public function messages(): array
    {
        return [
            'external_product_id.required' => 'Podaj ID produktu z PrestaShop.',
            'external_product_id.integer' => 'ID produktu z PrestaShop musi być liczbą całkowitą.',
            'external_product_id.min' => 'ID produktu z PrestaShop musi być większe od zera.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'external_product_id' => trim((string) $this->input('external_product_id', '')),
        ]);
    }
}
