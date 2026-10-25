<?php

namespace Modules\Integrations\Allegro\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Invoices\Rules\InvoiceVatPercentageRule;
use Modules\Invoices\Services\InvoiceFinancialValueValidator;

class UpdateAllegroOrderImportSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'use_linked_products' => ['required', 'boolean'],
            'use_catalog_product_names' => ['required', 'boolean'],
            'use_catalog_vat' => ['required', 'boolean'],
            'default_vat_rate' => ['sometimes', 'nullable', new InvoiceVatPercentageRule(app(InvoiceFinancialValueValidator::class))],
        ];
    }
}
