<?php

namespace Modules\Ksef\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Ksef\Enums\KsefAuthenticationMethod;
use Modules\Ksef\Enums\KsefEnvironment;

class UpdateKsefSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'environment' => ['required', Rule::enum(KsefEnvironment::class)],
            'context_nip' => ['required', 'regex:/^\d{10}$/'],
            'authentication_method' => ['required', Rule::enum(KsefAuthenticationMethod::class)],
            'api_token' => ['nullable', 'string', 'max:4096'],
            'automatic_submission' => ['required', 'boolean'],
            'send_without_buyer_nip' => ['required', 'boolean'],
            'include_recipient_data' => ['required', 'boolean'],
            'include_buyer_contact_data' => ['required', 'boolean'],
            'include_additional_information' => ['required', 'boolean'],
            'include_order_reference' => ['required', 'boolean'],
            'include_bank_account' => ['required', 'boolean'],
            'include_gtu' => ['required', 'boolean'],
            'include_sale_date' => ['required', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'context_nip.required' => 'Wpisz NIP kontekstu KSeF.',
            'context_nip.regex' => 'NIP musi zawierać dokładnie 10 cyfr.',
            'environment.enum' => 'Wybierz prawidłowe środowisko KSeF.',
            'authentication_method.enum' => 'Wybierz prawidłową metodę uwierzytelnienia.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $nip = trim((string) $this->input('context_nip', ''));

        $this->merge([
            'name' => trim((string) $this->input('name', '')),
            'context_nip' => preg_replace('/[\s-]+/u', '', $nip),
        ]);
    }
}
