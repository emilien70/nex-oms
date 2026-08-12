<?php

namespace Modules\Invoices\Http\Requests;

use App\Support\CountryCatalog;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Modules\Invoices\Enums\CorrectionReason;
use Modules\Invoices\Rules\InvoiceFinancialStorageRule;
use Modules\Invoices\Rules\InvoiceVatPercentageRule;
use Modules\Invoices\Services\InvoiceFinancialLimits;
use Modules\Invoices\Services\InvoiceFinancialValueValidator;

class CorrectionDraftRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $buyer = $this->input('buyer', []);
        if (is_array($buyer)) {
            $buyer['country_code'] = app(CountryCatalog::class)->normalize($buyer['country_code'] ?? null);
        }

        $items = $this->input('items', []);
        if (is_array($items)) {
            foreach ($items as $index => $item) {
                if (is_array($item)) {
                    $code = strtoupper(trim((string) ($item['vat_code'] ?? '')));
                    $items[$index]['vat_code'] = $code === '' ? null : $code;
                    if ($code !== '') {
                        $items[$index]['vat_rate'] = null;
                    }
                }
            }
        }

        $this->merge([
            'change_items' => $this->boolean('change_items'),
            'change_buyer' => $this->boolean('change_buyer'),
            'buyer' => $buyer,
            'items' => $items,
        ]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'expected_source_lock_version' => ['required', 'integer', 'min:1'],
            'correction_series_id' => ['required', 'integer', 'exists:invoice_series,id'],
            'reason' => ['required', Rule::enum(CorrectionReason::class)],
            'other_reason' => ['nullable', 'string', 'max:1000', 'required_if:reason,'.CorrectionReason::Other->value],
            'issue_date' => ['required', 'date_format:Y-m-d'],
            'sale_date' => ['required', 'date_format:Y-m-d'],
            'payment_method' => ['nullable', 'string', 'max:255'],
            'issuer_name' => ['nullable', 'string', 'max:255'],
            'additional_information' => ['nullable', 'string', 'max:5000'],
            'change_items' => ['required', 'boolean'],
            'change_buyer' => ['required', 'boolean'],
            'items' => ['nullable', 'array'],
            'items.*.source_item_id' => ['nullable', 'integer', 'distinct'],
            'items.*.order_item_id' => ['nullable', 'integer'],
            'items.*.line_type' => ['required', Rule::in(['product', 'shipping', 'custom'])],
            'items.*.position' => ['required', 'integer', 'min:1', 'max:65535'],
            'items.*.name' => ['required', 'string', 'max:255'],
            'items.*.description' => ['nullable', 'string', 'max:1000'],
            'items.*.unit_name' => ['required', 'string', 'max:30'],
            'items.*.quantity' => [
                'required',
                'regex:/^(?:0|[1-9]\d*)$/',
                new InvoiceFinancialStorageRule(
                    app(InvoiceFinancialValueValidator::class),
                    InvoiceFinancialLimits::INVOICE_ITEM_QUANTITY,
                    'Ilość przekracza maksymalny obsługiwany zakres.',
                ),
            ],
            'items.*.unit_price_gross' => [
                'required',
                'regex:/^\d+(?:[\.,]\d{1,2})?$/',
                new InvoiceFinancialStorageRule(
                    app(InvoiceFinancialValueValidator::class),
                    InvoiceFinancialLimits::INVOICE_ITEM_UNIT_PRICE,
                    'Cena brutto przekracza maksymalną obsługiwaną wartość.',
                ),
            ],
            'items.*.vat_rate' => [
                'nullable',
                new InvoiceVatPercentageRule(app(InvoiceFinancialValueValidator::class)),
            ],
            'items.*.vat_code' => ['nullable', 'string', 'max:20', 'regex:/^[A-Za-z0-9_]+$/'],
            'buyer.name' => ['nullable', 'string', 'max:255'],
            'buyer.company_name' => ['nullable', 'string', 'max:255'],
            'buyer.tax_id' => ['nullable', 'string', 'max:30'],
            'buyer.street' => ['nullable', 'string', 'max:255'],
            'buyer.building_number' => ['nullable', 'string', 'max:50'],
            'buyer.apartment_number' => ['nullable', 'string', 'max:50'],
            'buyer.postal_code' => ['nullable', 'string', 'max:20'],
            'buyer.city' => ['nullable', 'string', 'max:150'],
            'buyer.country_code' => ['nullable', 'string', Rule::in(app(CountryCatalog::class)->codes())],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if (! $this->boolean('change_items') && ! $this->boolean('change_buyer')) {
                $validator->errors()->add('correction', 'Wybierz pozycje lub dane nabywcy, które mają zostać skorygowane.');
            }

            if ($this->boolean('change_buyer')) {
                $buyer = $this->input('buyer', []);
                if (trim((string) ($buyer['name'] ?? '')) === '' && trim((string) ($buyer['company_name'] ?? '')) === '') {
                    $validator->errors()->add('buyer.name', 'Podaj imię i nazwisko albo nazwę firmy nabywcy.');
                }
            }
        });
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'expected_source_lock_version.required' => 'Brakuje technicznej wersji blokady korygowanej Faktury.',
            'expected_source_lock_version.integer' => 'Techniczna wersja blokady korygowanej Faktury jest nieprawidłowa.',
            'expected_source_lock_version.min' => 'Techniczna wersja blokady korygowanej Faktury jest nieprawidłowa.',
            'reason.required' => 'Wybierz powód wystawienia korekty.',
            'other_reason.required_if' => 'Uzupełnij własny powód wystawienia korekty.',
            'buyer.country_code.in' => 'Wybrany kraj nabywcy jest nieprawidłowy.',
            'items.*.quantity.regex' => 'Ilość musi być liczbą całkowitą równą lub większą od zera.',
            'items.*.unit_price_gross.regex' => 'Cena brutto może mieć najwyżej dwa miejsca po przecinku.',
        ];
    }
}
