<?php

namespace Modules\Ksef\Http\Requests;

use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Invoices\Enums\InvoiceDocumentType;

class UpdateKsefSeriesSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'series_ids' => ['present', 'array'],
            'series_ids.*' => [
                'integer',
                'min:1',
                'distinct',
                Rule::exists('invoice_series', 'id')->where(
                    fn (Builder $query): Builder => $query
                        ->where('is_active', true)
                        ->whereIn('document_type', [
                            InvoiceDocumentType::Invoice->value,
                            InvoiceDocumentType::Correction->value,
                        ]),
                ),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'series_ids.array' => 'Ustawienia serii mają nieprawidłowy format.',
            'series_ids.*.exists' => 'Do KSeF można przypisać wyłącznie aktywną serię Faktur VAT albo Korekt.',
            'series_ids.*.distinct' => 'Każdą serię można wskazać tylko raz.',
        ];
    }

    protected function prepareForValidation(): void
    {
        if (! $this->exists('series_ids')) {
            $this->merge(['series_ids' => []]);
        }
    }
}
