<?php

namespace Modules\Integrations\VonHalsky\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Integrations\VonHalsky\Enums\VonHalskyOrderAction;

class SaveVonHalskyStatusMappingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['expected_lock_version' => ['required', 'integer', 'min:0'],
            'status_sync_enabled' => ['required', 'boolean'], 'outbound' => ['required', 'array'],
            'outbound.*' => ['nullable', 'string', Rule::enum(VonHalskyOrderAction::class)]];
    }
}
