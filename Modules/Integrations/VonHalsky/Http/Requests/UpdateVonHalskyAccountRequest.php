<?php

namespace Modules\Integrations\VonHalsky\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Integrations\VonHalsky\Enums\VonHalskyEnvironment;

class UpdateVonHalskyAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'environment' => ['required', Rule::enum(VonHalskyEnvironment::class)],
            'organization_id' => ['required', 'uuid'],
            'client_id' => ['required', 'string', 'max:255', 'not_regex:/[\x00-\x1F\x7F]/'],
            'client_secret' => ['nullable', 'string', 'max:4096', 'not_regex:/[\x00-\x1F\x7F]/'],
            'expected_lock_version' => ['required', 'integer', 'min:0'],
        ];
    }

    public function attributes(): array
    {
        return ['organization_id' => 'Organization ID', 'client_id' => 'Client ID', 'client_secret' => 'Client Secret'];
    }

    protected function prepareForValidation(): void
    {
        foreach (['name', 'organization_id', 'client_id', 'client_secret'] as $key) {
            if (is_string($this->input($key))) {
                $this->merge([$key => trim($this->input($key))]);
            }
        }
        if (is_string($this->input('organization_id'))) {
            $this->merge(['organization_id' => strtolower($this->input('organization_id'))]);
        }
    }
}
