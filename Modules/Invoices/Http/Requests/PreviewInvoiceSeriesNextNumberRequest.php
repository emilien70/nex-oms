<?php

namespace Modules\Invoices\Http\Requests;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Modules\Invoices\Enums\InvoiceSeriesResetPeriod;
use Modules\Invoices\Models\InvoiceSeries;

class PreviewInvoiceSeriesNextNumberRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        $rules = [
            'next_sequence_number' => ['nullable', 'integer', 'min:1'],
        ];

        return match ($this->series()->reset_period) {
            InvoiceSeriesResetPeriod::Monthly => $rules + [
                'period_month' => ['required', 'date_format:Y-m'],
            ],
            InvoiceSeriesResetPeriod::Yearly => $rules + [
                'period_year' => ['required', 'integer', 'between:1900,9999'],
            ],
            InvoiceSeriesResetPeriod::None => $rules,
        };
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'period_month.required' => 'Wybierz miesiąc okresu numeracji.',
            'period_month.date_format' => 'Wybrany miesiąc okresu numeracji jest nieprawidłowy.',
            'period_year.required' => 'Wybierz rok rozpoczęcia okresu numeracji.',
            'period_year.integer' => 'Rok rozpoczęcia okresu numeracji jest nieprawidłowy.',
            'period_year.between' => 'Rok rozpoczęcia okresu numeracji jest nieprawidłowy.',
            'next_sequence_number.integer' => 'Nowy następny numer musi być liczbą całkowitą.',
            'next_sequence_number.min' => 'Nowy następny numer musi być większy lub równy 1.',
        ];
    }

    public function numberingDate(): CarbonImmutable
    {
        return match ($this->series()->reset_period) {
            InvoiceSeriesResetPeriod::Monthly => CarbonImmutable::createFromFormat(
                '!Y-m',
                (string) $this->validated('period_month'),
            ),
            InvoiceSeriesResetPeriod::Yearly => CarbonImmutable::create(
                (int) $this->validated('period_year'),
                $this->series()->fiscal_year_start_month,
                1,
            )->startOfDay(),
            InvoiceSeriesResetPeriod::None => CarbonImmutable::now()->startOfDay(),
        };
    }

    public function candidateNextSequenceNumber(): ?int
    {
        $value = $this->validated('next_sequence_number');

        return $value === null ? null : (int) $value;
    }

    private function series(): InvoiceSeries
    {
        $series = $this->route('series');

        abort_unless($series instanceof InvoiceSeries, 404);

        return $series;
    }
}
