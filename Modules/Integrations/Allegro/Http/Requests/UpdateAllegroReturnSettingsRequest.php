<?php

namespace Modules\Integrations\Allegro\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateAllegroReturnSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'identity' => ['required', 'string', 'max:255'], 'configuration_revision' => ['required', 'integer', 'min:0'],
            'returns_enabled' => ['required', 'boolean'], 'returns_from' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
        ];
    }
}
