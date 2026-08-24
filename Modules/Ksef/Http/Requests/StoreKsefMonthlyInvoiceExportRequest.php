<?php

namespace Modules\Ksef\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Ksef\Services\KsefMonthlyExportPeriod;

class StoreKsefMonthlyInvoiceExportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(KsefMonthlyExportPeriod $periods): array
    {
        return [
            'month' => [
                'bail',
                'required',
                'string',
                'date_format:Y-m',
                Rule::in($periods->values()),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'month.required' => 'Wybierz miesiąc eksportu.',
            'month.date_format' => 'Miesiąc eksportu ma nieprawidłowy format.',
            'month.in' => 'Wybrany miesiąc jest poza dozwolonym zakresem eksportu.',
        ];
    }

    protected function getRedirectUrl(): string
    {
        return route('integrations.ksef.edit', ['tab' => 'export']);
    }
}
