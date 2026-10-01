<?php

namespace App\Http\Requests;

use App\Rules\ValidCurrencyCode;
use App\Support\CurrencyCatalog;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Invoices\Rules\InvoiceFinancialStorageRule;
use Modules\Invoices\Services\InvoiceFinancialLimits;
use Modules\Invoices\Services\InvoiceFinancialValueValidator;

class AddCatalogProductsToOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'currency' => app(CurrencyCatalog::class)->normalize($this->input('currency')),
        ]);

        $items = $this->input('items');

        if (! is_array($items)) {
            return;
        }

        foreach ($items as $index => $item) {
            if (! is_array($item) || ! array_key_exists('unit_price_gross', $item)) {
                continue;
            }

            $value = $item['unit_price_gross'];
            if (is_string($value)) {
                $value = preg_replace('/[\s\x{00A0}]+/u', '', trim($value)) ?? trim($value);
                $items[$index]['unit_price_gross'] = str_replace(',', '.', $value);
            }
        }

        $this->merge(['items' => $items]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'currency' => [
                'required',
                'string',
                'size:3',
                'regex:/^[A-Z]{3}$/',
                new ValidCurrencyCode(app(CurrencyCatalog::class)),
            ],
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.product_id' => [
                'required',
                'integer',
                'distinct',
                Rule::exists('products', 'id')->where('is_active', true),
            ],
            'items.*.quantity' => [
                'required',
                'integer',
                'min:1',
                'max:'.InvoiceFinancialLimits::ORDER_QUANTITY_MAX,
            ],
            'items.*.unit_price_gross' => [
                'required',
                'regex:/^\d+(?:\.\d{1,2})?$/',
                new InvoiceFinancialStorageRule(
                    app(InvoiceFinancialValueValidator::class),
                    InvoiceFinancialLimits::ORDER_MONEY,
                    'Cena brutto przekracza maksymalną obsługiwaną wartość.',
                ),
            ],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'currency.required' => 'Wybierz walutę dodawanych produktów.',
            'currency.size' => CurrencyCatalog::INVALID_CURRENCY_MESSAGE,
            'currency.regex' => CurrencyCatalog::INVALID_CURRENCY_MESSAGE,
            'items.required' => 'Wybierz co najmniej jeden produkt.',
            'items.min' => 'Wybierz co najmniej jeden produkt.',
            'items.max' => 'Jednocześnie można dodać najwyżej 100 produktów.',
            'items.*.product_id.exists' => 'Wybrany produkt nie istnieje albo został ukryty.',
            'items.*.product_id.distinct' => 'Ten sam produkt został wybrany więcej niż raz.',
            'items.*.quantity.integer' => 'Ilość musi być liczbą całkowitą.',
            'items.*.quantity.min' => 'Ilość musi wynosić co najmniej 1.',
            'items.*.unit_price_gross.regex' => 'Cena brutto może mieć najwyżej dwa miejsca po przecinku.',
        ];
    }
}
