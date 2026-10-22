<?php

namespace Modules\Integrations\Allegro\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Integrations\Allegro\Enums\AllegroFulfillmentStatus;

class UpdateAllegroStatusMappingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status_sync_enabled' => ['required', 'boolean'],
            'identity' => ['required', 'string', 'max:255'],
            'configuration_revision' => ['required', 'integer', 'min:0'],
            'outbound' => ['present', 'array'],
            'outbound.*' => ['nullable', Rule::enum(AllegroFulfillmentStatus::class)],
        ];
    }

    public function messages(): array
    {
        return [
            'outbound.present' => 'Brakuje listy powiązań statusów.',
            'outbound.*.enum' => 'Wybrany status realizacji Allegro jest nieprawidłowy.',
        ];
    }
}
