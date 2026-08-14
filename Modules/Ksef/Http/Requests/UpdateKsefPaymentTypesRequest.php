<?php

namespace Modules\Ksef\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Ksef\Enums\KsefPaymentType;

class UpdateKsefPaymentTypesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'default_payment_type' => ['required', Rule::enum(KsefPaymentType::class)],
            'mappings' => ['present', 'array'],
            'mappings.*' => ['array:source_key,target_type'],
            'mappings.*.source_key' => ['required', 'string', 'max:255', 'distinct'],
            'mappings.*.target_type' => ['nullable', Rule::enum(KsefPaymentType::class)],
        ];
    }

    public function messages(): array
    {
        return [
            'default_payment_type.enum' => 'Wybierz prawidłowy domyślny typ płatności FA(3).',
            'mappings.array' => 'Lista form płatności ma nieprawidłowy format.',
            'mappings.*.source_key.distinct' => 'Każda forma płatności może wystąpić tylko raz.',
            'mappings.*.target_type.enum' => 'Wybierz prawidłowy typ płatności FA(3).',
        ];
    }

    protected function getRedirectUrl(): string
    {
        return route('integrations.ksef.edit', ['tab' => 'payment-types']);
    }
}
