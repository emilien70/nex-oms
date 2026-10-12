<?php

namespace Modules\Integrations\PayNow\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Integrations\PayNow\Enums\PayNowEnvironment;

class UpdatePayNowAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'environment' => ['required', Rule::enum(PayNowEnvironment::class)],
            'api_key' => ['nullable', 'string', 'max:500'],
            'signature_key' => ['nullable', 'string', 'max:500'],
            'is_active' => ['required', 'boolean'],
            'link_validity_seconds' => ['required', 'integer', 'min:60', 'max:7862400'],
            'description_template' => ['required', 'string', 'max:100'],
            'continue_url' => ['nullable', 'string', 'max:1000', 'url:http,https'],
        ];
    }

    public function messages(): array
    {
        return [
            'environment.enum' => 'Wybierz prawidłowe środowisko PayNow.',
            'link_validity_seconds.min' => 'Link musi być ważny przez co najmniej 60 sekund.',
            'link_validity_seconds.max' => 'Link może być ważny maksymalnie przez 91 dni.',
            'continue_url.url' => 'Adres powrotu musi być prawidłowym adresem HTTP lub HTTPS.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'name' => trim((string) $this->input('name', '')),
            'api_key' => trim((string) $this->input('api_key', '')),
            'signature_key' => trim((string) $this->input('signature_key', '')),
            'description_template' => trim((string) $this->input('description_template', '')),
            'continue_url' => trim((string) $this->input('continue_url', '')) ?: null,
            'is_active' => $this->boolean('is_active'),
        ]);
    }
}
