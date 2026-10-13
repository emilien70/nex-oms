<?php

namespace Modules\Integrations\Prestashop\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ImportPrestashopOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['order_identifier' => trim((string) $this->input('order_identifier'))]);
    }

    public function rules(): array
    {
        return [
            'order_identifier' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9_-]+$/'],
        ];
    }

    public function messages(): array
    {
        return [
            'order_identifier.required' => 'Podaj ID albo numer referencyjny zamówienia PrestaShop.',
            'order_identifier.regex' => 'ID lub numer referencyjny może zawierać litery, cyfry, myślnik i podkreślenie.',
        ];
    }
}
