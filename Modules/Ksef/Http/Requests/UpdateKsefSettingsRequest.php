<?php

namespace Modules\Ksef\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Modules\Ksef\Enums\KsefAuthenticationMethod;
use Modules\Ksef\Enums\KsefEnvironment;
use Modules\Ksef\Enums\KsefZeroVatClassification;

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
            'api_token_environment' => ['nullable', 'string'],
            'authentication_certificate' => ['nullable', 'file', 'max:128', 'required_with:authentication_private_key'],
            'authentication_private_key' => ['nullable', 'file', 'max:128', 'required_with:authentication_certificate'],
            'authentication_private_key_passphrase' => ['nullable', 'string', 'max:1024'],
            'is_active' => ['required', 'boolean'],
            'automatic_submission' => ['required', 'boolean'],
            'send_without_buyer_nip' => ['required', 'boolean'],
            'include_recipient_data' => ['required', 'boolean'],
            'include_buyer_contact_data' => ['required', 'boolean'],
            'include_additional_information' => ['required', 'boolean'],
            'include_order_reference' => ['required', 'boolean'],
            'include_bank_account' => ['required', 'boolean'],
            'include_gtu' => ['required', 'boolean'],
            'zero_vat_classification' => ['required', Rule::enum(KsefZeroVatClassification::class)],
            'default_split_payment' => ['required', 'boolean'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if (! filled($this->input('api_token'))) {
                    return;
                }

                $environment = $this->input('environment');
                $tokenEnvironment = $this->input('api_token_environment');

                if (! is_string($environment)
                    || ! is_string($tokenEnvironment)
                    || KsefEnvironment::tryFrom($tokenEnvironment) === null
                    || $tokenEnvironment !== $environment) {
                    $validator->errors()->add(
                        'api_token_environment',
                        'Token KSeF nie odpowiada wybranemu środowisku. Wprowadź token ponownie.',
                    );
                }
            },
        ];
    }

    public function messages(): array
    {
        return [
            'context_nip.required' => 'Wpisz NIP kontekstu KSeF.',
            'context_nip.regex' => 'NIP musi zawierać dokładnie 10 cyfr.',
            'environment.enum' => 'Wybierz prawidłowe środowisko KSeF.',
            'authentication_method.enum' => 'Wybierz prawidłową metodę uwierzytelnienia.',
            'authentication_certificate.file' => 'Wybierz prawidłowy plik certyfikatu.',
            'authentication_certificate.max' => 'Plik certyfikatu może mieć maksymalnie 128 KiB.',
            'authentication_certificate.required_with' => 'Aby zmienić klucz prywatny, wybierz również odpowiadający mu certyfikat.',
            'authentication_private_key.file' => 'Wybierz prawidłowy plik klucza prywatnego.',
            'authentication_private_key.max' => 'Plik klucza prywatnego może mieć maksymalnie 128 KiB.',
            'authentication_private_key.required_with' => 'Aby zmienić certyfikat, wybierz również odpowiadający mu klucz prywatny.',
            'zero_vat_classification.enum' => 'Wybierz prawidłową klasyfikację stawki VAT 0%.',
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
