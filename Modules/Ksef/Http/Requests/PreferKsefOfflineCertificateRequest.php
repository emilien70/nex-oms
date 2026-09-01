<?php

namespace Modules\Ksef\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Ksef\Enums\KsefEnvironment;

class PreferKsefOfflineCertificateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'environment' => ['required', Rule::enum(KsefEnvironment::class)],
        ];
    }
}
