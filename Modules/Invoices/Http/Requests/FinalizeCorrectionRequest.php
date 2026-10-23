<?php

namespace Modules\Invoices\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Ksef\Enums\KsefEnvironment;

class FinalizeCorrectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'expected_lock_version' => ['required', 'integer', 'min:1'],
            'expected_ksef_environment' => ['required', Rule::enum(KsefEnvironment::class)],
            'confirm_outside_ksef' => ['required', 'accepted'],
        ];
    }
}
