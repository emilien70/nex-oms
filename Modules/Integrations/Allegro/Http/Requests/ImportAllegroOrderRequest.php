<?php

namespace Modules\Integrations\Allegro\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ImportAllegroOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('order_identifier'))) {
            $this->merge(['order_identifier' => strtolower(trim($this->input('order_identifier')))]);
        }
    }

    public function rules(): array
    {
        return [
            'order_identifier' => ['required', 'uuid'],
            'preview_hash' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/'],
        ];
    }

    public function attributes(): array
    {
        return ['order_identifier' => 'identyfikator zamówienia Allegro', 'preview_hash' => 'potwierdzenie podglądu'];
    }
}
