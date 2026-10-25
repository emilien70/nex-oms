<?php

namespace Modules\Integrations\Prestashop\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Integrations\Prestashop\Enums\PrestashopCatalogVatMode;
use Modules\Invoices\Rules\InvoiceVatPercentageRule;
use Modules\Invoices\Services\InvoiceFinancialValueValidator;

class UpdatePrestashopOrderImportSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'order_import_enabled' => ['required', 'boolean'],
            'use_linked_products' => ['sometimes', 'boolean'],
            'use_catalog_product_names' => ['sometimes', 'boolean'],
            'catalog_vat_mode' => ['sometimes', Rule::enum(PrestashopCatalogVatMode::class)],
            'default_vat_rate' => ['sometimes', 'nullable', new InvoiceVatPercentageRule(app(InvoiceFinancialValueValidator::class))],
        ];
    }

    protected function prepareForValidation(): void
    {
        $normalized = [
            'order_import_enabled' => $this->boolean('order_import_enabled'),
        ];

        foreach (['use_linked_products', 'use_catalog_product_names'] as $field) {
            if ($this->has($field)) {
                $normalized[$field] = $this->boolean($field);
            }
        }

        $this->merge($normalized);
    }
}
