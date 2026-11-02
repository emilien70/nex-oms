<?php

namespace Modules\Integrations\VonHalsky\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class VonHalskyAccountActionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $rules = ['expected_lock_version' => ['required', 'integer', 'min:0']];
        if ($this->routeIs('integrations.von-halsky.preview')) {
            $rules['order_identifier'] = ['required', 'string', 'max:255', 'regex:/\A[A-Za-z0-9][A-Za-z0-9._-]{0,254}\z/D'];
        }

        return $rules;
    }

    public function attributes(): array
    {
        return ['order_identifier' => 'ID zamówienia'];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('order_identifier'))) {
            $this->merge(['order_identifier' => trim($this->input('order_identifier'))]);
        }
    }
}
