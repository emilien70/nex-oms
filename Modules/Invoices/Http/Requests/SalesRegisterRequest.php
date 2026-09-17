<?php

namespace Modules\Invoices\Http\Requests;

use Closure;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\ViewErrorBag;
use Illuminate\Validation\Rule;
use Modules\Invoices\Services\SalesRegisterFormData;
use Modules\Invoices\ValueObjects\SalesRegisterFilters;

class SalesRegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Invoice routes currently share the web boundary without a separate user policy.
        return true;
    }

    public function rules(): array
    {
        $ids = $this->routeIs('invoices.sales-register.selected') || $this->input('mode') === 'ids';
        $rules = ['mode' => ['required', Rule::in(['period', 'ids'])]];
        if ($ids) {
            $rules['document_ids'] = ['bail', 'required', 'string', 'json', function (string $attribute, mixed $value, Closure $fail): void {
                $list = json_decode($value);
                if (! is_array($list) || ! array_is_list($list) || $list === []) {
                    $fail('Zaznacz co najmniej jeden dokument. Lista dokumentów musi być pełną tablicą ID.');

                    return;
                }
                foreach ($list as $id) {
                    if (! is_int($id) || $id < 1) {
                        $fail('Lista dokumentów zawiera nieprawidłowe ID.');
                        break;
                    }
                }
            }];
        } else {
            $rules += [
                'month' => ['required', 'integer', 'between:1,12'], 'year' => ['required', 'integer', 'between:1000,9999'],
                'issue_from' => ['nullable', 'required_with:issue_to', 'date_format:Y-m-d'],
                'issue_to' => ['nullable', 'required_with:issue_from', 'date_format:Y-m-d', 'after_or_equal:issue_from'],
                'sale_from' => ['nullable', 'date_format:Y-m-d'],
                'sale_to' => ['nullable', 'date_format:Y-m-d', ...($this->filled('sale_from') ? ['after_or_equal:sale_from'] : [])],
                'series_ids' => ['required', 'array', 'list', 'min:1'], 'series_ids.*' => ['required', 'integer', 'min:1'],
                'tax_id_presence' => ['required', Rule::in(['all', 'with', 'without'])],
                'currency' => ['nullable', 'regex:/^[A-Za-z]{3}$/D'], 'country' => ['nullable', 'regex:/^[A-Za-z]{2}$/D'],
            ];
        }
        if (! $this->routeIs('invoices.sales-register.selected')) {
            $rules += ['format' => ['required', Rule::in(['html', 'xlsx', 'xml', 'jpk_v7m3'])], 'include_ksef' => ['required_unless:format,jpk_v7m3', 'boolean']];
            foreach (['include_header', 'include_exchange_rates'] as $field) {
                $rules[$field] = ['exclude_if:format,xlsx,xml,jpk_v7m3', 'required', 'boolean'];
            }
            if ($this->input('format') === 'jpk_v7m3') {
                foreach (['year', 'month', 'type', 'nip', 'office', 'email', 'purpose'] as $field) {
                    $rules['jpk_'.$field] = ['required', 'string', 'max:255'];
                }
                foreach (['phone', 'name', 'first_name', 'last_name', 'birth_date'] as $field) {
                    $rules['jpk_'.$field] = ['nullable', 'string', 'max:512'];
                }
                $rules += [
                    'jpk_action' => ['required', Rule::in(['review', 'download'])],
                    'jpk_fingerprint' => ['required_if:jpk_action,download', 'string', 'regex:/^[a-f0-9]{64}$/D'],
                    'jpk_confirm' => ['accepted_if:jpk_action,download'],
                    'jpk_markers' => ['sometimes', 'array'],
                    'jpk_markers.*' => ['nullable', 'string', Rule::in(['BFK', 'OFF', 'DI'])],
                ];
            }
        }

        return $rules;
    }

    protected function prepareForValidation(): void
    {
        if ($this->routeIs('invoices.sales-register.selected')) {
            $this->merge(['mode' => 'ids']);
        }
    }

    public function filters(): SalesRegisterFilters
    {
        $input = $this->validated();
        $includeKsef = ($input['format'] ?? null) === 'jpk_v7m3' || (bool) ($input['include_ksef'] ?? false);
        if ($input['mode'] === 'ids') {
            return SalesRegisterFilters::forDocuments(json_decode($input['document_ids'], true), $includeKsef);
        }
        $input['month'] = sprintf('%04d-%02d', $input['year'], $input['month']);
        $input['include_ksef'] = $includeKsef;
        if (empty($input['issue_from']) && empty($input['issue_to'])) {
            unset($input['issue_from'], $input['issue_to']);
        }

        return SalesRegisterFilters::forPeriod($input);
    }

    public function messages(): array
    {
        return [
            'required' => 'Uzupełnij pole :attribute.', 'boolean' => 'Wybierz Tak albo Nie.',
            'in' => 'Wybierz prawidłową wartość pola :attribute.', 'integer' => 'Wybierz prawidłową liczbę.',
            'date_format' => 'Podaj prawidłową datę.', 'required_with' => 'Podaj obie daty wystawienia.',
            'after_or_equal' => 'Data końcowa nie może poprzedzać początkowej.',
            'series_ids.required' => 'Wybierz co najmniej jedną serię numeracji.',
            'document_ids.required' => 'Zaznacz co najmniej jeden dokument.',
            'document_ids.json' => 'Lista dokumentów jest niepełna lub nieprawidłowa.',
            'regex' => 'Podaj prawidłowy kod.',
        ];
    }

    public function attributes(): array
    {
        return ['jpk_type' => 'Typ podatnika', 'jpk_nip' => 'NIP podatnika', 'jpk_office' => 'Urząd skarbowy',
            'jpk_email' => 'E-mail', 'jpk_phone' => 'Telefon', 'jpk_name' => 'Pełna nazwa podatnika',
            'jpk_first_name' => 'Pierwsze imię', 'jpk_last_name' => 'Nazwisko', 'jpk_birth_date' => 'Data urodzenia',
            'jpk_year' => 'Rok JPK', 'jpk_month' => 'Miesiąc JPK', 'jpk_purpose' => 'Cel pliku'];
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(response()->view('invoices.sales-register.form',
            app(SalesRegisterFormData::class)->build($this) + ['errors' => (new ViewErrorBag)->put('default', $validator->errors())],
            422, ['Cache-Control' => 'private, no-store']));
    }
}
