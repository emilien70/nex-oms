<?php

namespace Modules\Integrations\VonHalsky\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ImportVonHalskyOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'order_identifier' => ['required', 'string', 'max:255', 'regex:/\A[A-Za-z0-9][A-Za-z0-9._-]{0,254}\z/D'],
            'preview_hash' => ['required', 'string', 'regex:/\A[a-f0-9]{64}\z/D'],
            'quantities' => ['present', 'array', 'min:1', 'max:1000'],
            'quantities.*' => ['required', 'integer', 'min:1', 'max:999999', function (string $attribute, mixed $value, \Closure $fail): void {
                if ((! is_int($value) && ! is_string($value)) || ! preg_match('/\A[1-9][0-9]{0,5}\z/D', (string) $value)) {
                    $fail('Podaj całkowitą ilość od 1 do 999999.');
                }
            }],
            'confirmed_quantities' => ['accepted'],
        ];
    }
}
