<?php

namespace Modules\Integrations\VonHalsky\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SaveVonHalskyProductLinkRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('external_offer_id'))) {
            $this->merge(['external_offer_id' => trim($this->input('external_offer_id'))]);
        }
    }

    public function rules(): array
    {
        return [
            'expected_connection_revision' => ['required', 'integer', 'min:0'],
            'external_offer_id' => $this->isMethod('DELETE') ? ['prohibited']
                : ['required', 'string', 'regex:/\A[A-Za-z0-9][A-Za-z0-9._-]{0,254}\z/D'],
        ];
    }
}
