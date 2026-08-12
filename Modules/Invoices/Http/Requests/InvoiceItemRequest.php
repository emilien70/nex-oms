<?php

namespace Modules\Invoices\Http\Requests;

use Modules\Invoices\Rules\InvoiceFinancialStorageRule;
use Modules\Invoices\Rules\InvoiceVatPercentageRule;
use Modules\Invoices\Services\InvoiceFinancialLimits;
use Modules\Invoices\Services\InvoiceFinancialValueValidator;

class InvoiceItemRequest extends InvoiceEditRequest
{
    protected function prepareForValidation(): void
    {
        $code = strtoupper(trim((string) $this->input('vat_code')));
        $this->merge([
            'vat_code' => $code === '' ? null : $code,
            'vat_rate' => $code === '' ? $this->input('vat_rate') : null,
        ]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'expected_lock_version' => ['required', 'integer', 'min:1'],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'unit_name' => ['required', 'string', 'max:30'],
            'quantity' => [
                'required',
                'regex:/^(?:0|[1-9]\d*)$/',
                new InvoiceFinancialStorageRule(
                    app(InvoiceFinancialValueValidator::class),
                    InvoiceFinancialLimits::INVOICE_ITEM_QUANTITY,
                    'Ilość przekracza maksymalny obsługiwany zakres.',
                ),
            ],
            'unit_price_gross' => [
                'required',
                'regex:/^\d+(?:[\.,]\d{1,2})?$/',
                new InvoiceFinancialStorageRule(
                    app(InvoiceFinancialValueValidator::class),
                    InvoiceFinancialLimits::INVOICE_ITEM_UNIT_PRICE,
                    'Cena brutto przekracza maksymalną obsługiwaną wartość.',
                ),
            ],
            'vat_rate' => [
                'nullable',
                new InvoiceVatPercentageRule(app(InvoiceFinancialValueValidator::class)),
                'required_without:vat_code',
            ],
            'vat_code' => ['nullable', 'string', 'max:20', 'regex:/^[A-Za-z0-9_]+$/', 'required_without:vat_rate'],
            'position' => ['required', 'integer', 'min:1', 'max:65535'],
        ];
    }
}
