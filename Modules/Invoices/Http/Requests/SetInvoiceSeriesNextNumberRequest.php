<?php

namespace Modules\Invoices\Http\Requests;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Invoices\Enums\InvoiceSeriesResetPeriod;
use Modules\Invoices\Models\InvoiceSeries;

class SetInvoiceSeriesNextNumberRequest extends FormRequest
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
            'next_number_series_id' => [
                'required',
                'integer',
                Rule::in([$this->series()->getKey()]),
            ],
            'next_sequence_number' => ['required', 'integer', 'min:1'],
            'reason' => ['required', 'string', 'min:3', 'max:1000'],
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
            'next_number_series_id.in' => 'Wybrana seria numeracji nie odpowiada adresowi operacji.',
            'next_sequence_number.required' => 'Podaj nowy następny numer.',
            'next_sequence_number.integer' => 'Nowy następny numer musi być liczbą całkowitą.',
            'next_sequence_number.min' => 'Nowy następny numer musi być większy lub równy 1.',
            'reason.required' => 'Podaj powód zmiany następnego numeru.',
            'reason.string' => 'Powód zmiany musi być tekstem.',
            'reason.min' => 'Powód zmiany musi zawierać co najmniej 3 znaki.',
            'reason.max' => 'Powód zmiany nie może być dłuższy niż 1000 znaków.',
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

    public function nextSequenceNumber(): int
    {
        return (int) $this->validated('next_sequence_number');
    }

    private function series(): InvoiceSeries
    {
        $series = $this->route('series');

        abort_unless($series instanceof InvoiceSeries, 404);

        return $series;
    }
}
