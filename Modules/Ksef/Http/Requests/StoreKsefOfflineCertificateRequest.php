<?php

namespace Modules\Ksef\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Ksef\Enums\KsefEnvironment;

class StoreKsefOfflineCertificateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'environment' => ['required', Rule::enum(KsefEnvironment::class)],
            'label' => ['nullable', 'string', 'max:120'],
            'offline_certificate' => ['required', 'file', 'max:128'],
            'offline_private_key' => ['required', 'file', 'max:128'],
            'offline_private_key_passphrase' => ['nullable', 'string', 'max:1024'],
        ];
    }

    public function messages(): array
    {
        return [
            'environment.required' => 'Wybierz środowisko certyfikatu Offline.',
            'environment.enum' => 'Wybierz prawidłowe środowisko certyfikatu Offline.',
            'offline_certificate.required' => 'Wybierz certyfikat Offline.',
            'offline_certificate.file' => 'Wybierz prawidłowy plik certyfikatu Offline.',
            'offline_certificate.max' => 'Plik certyfikatu Offline może mieć maksymalnie 128 KiB.',
            'offline_private_key.required' => 'Wybierz klucz prywatny certyfikatu Offline.',
            'offline_private_key.file' => 'Wybierz prawidłowy plik klucza prywatnego.',
            'offline_private_key.max' => 'Plik klucza prywatnego może mieć maksymalnie 128 KiB.',
        ];
    }
}
