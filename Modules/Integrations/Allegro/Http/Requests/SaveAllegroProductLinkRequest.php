<?php

namespace Modules\Integrations\Allegro\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SaveAllegroProductLinkRequest extends FormRequest
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
        return ['external_offer_id' => ['required', 'string', 'regex:/^[1-9][0-9]{0,31}$/']];
    }

    public function attributes(): array
    {
        return ['external_offer_id' => 'ID oferty Allegro'];
    }
}
