<?php

namespace Modules\Invoices\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Unique;
use Modules\Invoices\Enums\InvoiceDocumentType;
use Modules\Invoices\Enums\InvoiceSeriesResetPeriod;

abstract class InvoiceSeriesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'document_type' => ['required', Rule::enum(InvoiceDocumentType::class)],
            'name' => ['required', 'string', 'max:120', $this->uniqueNameRule()],
            'number_format' => ['required', 'string', 'max:120', 'regex:/%N+/'],
            'reset_period' => ['required', Rule::enum(InvoiceSeriesResetPeriod::class)],
            'fiscal_year_start_month' => ['required', 'integer', 'min:1', 'max:12'],
            'default_currency' => ['required', 'string', 'regex:/^[A-Z]{3}$/'],
            'is_active' => ['required', 'boolean'],
            'form_mode' => ['sometimes', Rule::in(['create', 'edit'])],
            'editing_series_id' => ['nullable', 'integer'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'document_type.required' => 'Wybierz typ dokumentu.',
            'document_type.enum' => 'Wybrany typ dokumentu jest nieprawidłowy.',
            'name.required' => 'Podaj nazwę serii.',
            'name.string' => 'Nazwa serii musi być tekstem.',
            'name.max' => 'Nazwa serii nie może być dłuższa niż 120 znaków.',
            'name.unique' => 'Seria numeracji o tej nazwie już istnieje dla wybranego typu dokumentu.',
            'number_format.required' => 'Podaj format numeracji.',
            'number_format.string' => 'Format numeracji musi być tekstem.',
            'number_format.max' => 'Format numeracji nie może być dłuższy niż 120 znaków.',
            'number_format.regex' => 'Format numeracji musi zawierać token %N.',
            'reset_period.required' => 'Wybierz sposób resetowania numeracji.',
            'reset_period.enum' => 'Wybrany sposób resetowania numeracji jest nieprawidłowy.',
            'fiscal_year_start_month.required' => 'Podaj początek roku fiskalnego.',
            'fiscal_year_start_month.integer' => 'Początek roku fiskalnego musi być miesiącem od 1 do 12.',
            'fiscal_year_start_month.min' => 'Początek roku fiskalnego musi być miesiącem od 1 do 12.',
            'fiscal_year_start_month.max' => 'Początek roku fiskalnego musi być miesiącem od 1 do 12.',
            'default_currency.required' => 'Podaj domyślną walutę.',
            'default_currency.regex' => 'Waluta musi składać się z trzech liter.',
            'is_active.required' => 'Wybierz stan aktywności serii.',
            'is_active.boolean' => 'Stan aktywności serii jest nieprawidłowy.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'name' => trim((string) $this->input('name', '')),
            'number_format' => trim((string) $this->input('number_format', '')),
            'default_currency' => strtoupper(trim((string) $this->input('default_currency', ''))),
        ]);
    }

    abstract protected function uniqueNameRule(): Unique;
}
