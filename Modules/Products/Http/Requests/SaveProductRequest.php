<?php

namespace Modules\Products\Http\Requests;

use App\Rules\ValidCurrencyCode;
use App\Support\CurrencyCatalog;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Products\Models\Product;

class SaveProductRequest extends FormRequest
{
    private const STANDARD_VAT_RATES = ['23.00', '8.00', '5.00', '0.00'];

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $product = $this->route('product');
        $productId = $product instanceof Product ? $product->getKey() : null;

        return [
            'name' => ['required', 'string', 'max:255'],
            'ean' => [
                'nullable',
                'regex:/^\d{8,14}$/',
                Rule::unique('products', 'ean')->ignore($productId),
            ],
            'price_gross' => ['required', 'decimal:0,2', 'min:0', 'max:9999999999999.99'],
            'currency' => ['required', 'string', 'size:3', new ValidCurrencyCode(app(CurrencyCatalog::class))],
            'vat_rate_preset' => ['required', Rule::in([...self::STANDARD_VAT_RATES, 'custom'])],
            'custom_vat_rate' => ['nullable', 'required_if:vat_rate_preset,custom', 'decimal:0,2', 'min:0', 'max:100'],
            'vat_rate' => ['required', 'decimal:0,2', 'min:0', 'max:100'],
            'stock_quantity' => ['required', 'integer', 'between:-2147483648,2147483647'],
            'package_weight_kg' => ['nullable', 'decimal:0,3', 'min:0', 'max:9999999.999'],
            'package_length_cm' => [
                'nullable',
                'required_with:package_width_cm,package_height_cm',
                'decimal:0,2',
                'gt:0',
                'max:99999999.99',
            ],
            'package_width_cm' => [
                'nullable',
                'required_with:package_length_cm,package_height_cm',
                'decimal:0,2',
                'gt:0',
                'max:99999999.99',
            ],
            'package_height_cm' => [
                'nullable',
                'required_with:package_length_cm,package_width_cm',
                'decimal:0,2',
                'gt:0',
                'max:99999999.99',
            ],
            'image' => [
                'nullable',
                Rule::prohibitedIf(fn (): bool => $this->boolean('remove_image')),
                'image',
                'mimes:jpg,jpeg,png,webp',
                'max:5120',
                'dimensions:min_width=1,min_height=1,max_width=6000,max_height=6000',
            ],
            'remove_image' => ['required', 'boolean'],
            'is_active' => ['required', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'name' => 'nazwa produktu',
            'ean' => 'EAN',
            'price_gross' => 'cena brutto',
            'currency' => 'waluta',
            'vat_rate_preset' => 'stawka VAT',
            'custom_vat_rate' => 'inna stawka VAT',
            'stock_quantity' => 'stan magazynowy',
            'package_weight_kg' => 'waga opakowania',
            'package_length_cm' => 'długość opakowania',
            'package_width_cm' => 'szerokość opakowania',
            'package_height_cm' => 'wysokość opakowania',
            'image' => 'zdjęcie produktu',
            'is_active' => 'aktywność produktu',
        ];
    }

    protected function prepareForValidation(): void
    {
        $vatPreset = trim((string) $this->input('vat_rate_preset', '23.00'));
        $customVatRate = $this->normalizeDecimalInput($this->input('custom_vat_rate'));

        $this->merge([
            'name' => trim((string) $this->input('name', '')),
            'ean' => $this->normalizeEan($this->input('ean')),
            'price_gross' => $this->normalizeDecimalInput($this->input('price_gross')),
            'currency' => app(CurrencyCatalog::class)->normalize($this->input('currency')),
            'vat_rate_preset' => $vatPreset,
            'custom_vat_rate' => $customVatRate,
            'vat_rate' => in_array($vatPreset, self::STANDARD_VAT_RATES, true)
                ? $vatPreset
                : $customVatRate,
            'stock_quantity' => trim((string) $this->input('stock_quantity', '0')),
            'package_weight_kg' => $this->normalizeDecimalInput($this->input('package_weight_kg')),
            'package_length_cm' => $this->normalizeDecimalInput($this->input('package_length_cm')),
            'package_width_cm' => $this->normalizeDecimalInput($this->input('package_width_cm')),
            'package_height_cm' => $this->normalizeDecimalInput($this->input('package_height_cm')),
            'remove_image' => $this->boolean('remove_image'),
            'is_active' => $this->boolean('is_active'),
        ]);
    }

    private function normalizeEan(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $normalized = preg_replace('/\s+/u', '', trim($value));

        return is_string($normalized) && $normalized !== '' ? $normalized : null;
    }

    private function normalizeDecimalInput(mixed $value): ?string
    {
        if (! is_string($value) && ! is_int($value)) {
            return null;
        }

        $normalized = str_replace(',', '.', trim((string) $value));

        return $normalized !== '' ? $normalized : null;
    }
}
