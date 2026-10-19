<?php

namespace Modules\Products\Http\Requests;

use App\Rules\ValidCurrencyCode;
use App\Support\CurrencyCatalog;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Products\Models\Product;

class SaveProductBundleRequest extends FormRequest
{
    private const STANDARD_VAT_RATES = ['23.00', '8.00', '5.00', '0.00'];

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $bundle = $this->route('bundle');
        $bundleId = $bundle instanceof Product ? $bundle->getKey() : null;

        return [
            'name' => ['required', 'string', 'max:255'],
            'ean' => [
                'nullable',
                'regex:/^\d{8,14}$/',
                Rule::unique('products', 'ean')->ignore($bundleId),
            ],
            'price_gross' => ['required', 'decimal:0,2', 'min:0', 'max:9999999999999.99'],
            'currency' => ['required', 'string', 'size:3', new ValidCurrencyCode(app(CurrencyCatalog::class))],
            'vat_rate_preset' => ['required', Rule::in([...self::STANDARD_VAT_RATES, 'custom'])],
            'custom_vat_rate' => ['nullable', 'required_if:vat_rate_preset,custom', 'decimal:0,2', 'min:0', 'max:100'],
            'vat_rate' => ['required', 'decimal:0,2', 'min:0', 'max:100'],
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
            'components' => ['required', 'array', 'min:2', 'max:100'],
            'components.*.product_id' => [
                'required',
                'integer',
                'distinct',
                Rule::exists('products', 'id')->where(
                    fn ($query) => $query->where('item_type', Product::TYPE_PRODUCT),
                ),
            ],
            'components.*.quantity' => ['required', 'integer', 'min:1', 'max:2147483647'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'name' => 'nazwa zestawu',
            'ean' => 'EAN',
            'price_gross' => 'cena brutto',
            'currency' => 'waluta',
            'vat_rate_preset' => 'stawka VAT',
            'custom_vat_rate' => 'inna stawka VAT',
            'image' => 'zdjęcie zestawu',
            'is_active' => 'aktywność zestawu',
            'components' => 'skład zestawu',
            'components.*.product_id' => 'produkt',
            'components.*.quantity' => 'ilość produktu',
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'components.min' => 'Zestaw musi zawierać co najmniej dwa różne produkty.',
            'components.*.product_id.distinct' => 'Każdy produkt może wystąpić w składzie zestawu tylko raz.',
            'components.*.product_id.exists' => 'Skład zestawu może zawierać wyłącznie istniejące produkty.',
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
