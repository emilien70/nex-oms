<?php

namespace Modules\Products\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateProductStockRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'stock_quantity' => ['required', 'integer', 'between:-2147483648,2147483647'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'stock_quantity' => 'stan magazynowy',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'stock_quantity' => trim((string) $this->input('stock_quantity', '')),
        ]);
    }
}
