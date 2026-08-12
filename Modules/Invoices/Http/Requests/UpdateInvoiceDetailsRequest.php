<?php

namespace Modules\Invoices\Http\Requests;

use App\Support\CountryCatalog;
use Illuminate\Validation\Rule;
use Modules\Invoices\Rules\InvoiceFinancialStorageRule;
use Modules\Invoices\Services\InvoiceFinancialLimits;
use Modules\Invoices\Services\InvoiceFinancialValueValidator;

class UpdateInvoiceDetailsRequest extends InvoiceEditRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge(['seller_country_code' => app(CountryCatalog::class)->normalize($this->input('seller_country_code'))]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'expected_lock_version' => ['required', 'integer', 'min:1'],
            'issue_date' => ['required', 'date_format:Y-m-d'],
            'sale_date' => ['required', 'date_format:Y-m-d'],
            'payment_due_date' => ['nullable', 'date_format:Y-m-d'],
            'payment_method' => ['nullable', 'string', 'max:255'],
            'payment_identifier' => ['nullable', 'string', 'max:255'],
            'paid_amount' => [
                'required',
                'regex:/^\d+(?:[\.,]\d{1,2})?$/',
                new InvoiceFinancialStorageRule(
                    app(InvoiceFinancialValueValidator::class),
                    InvoiceFinancialLimits::INVOICE_DOCUMENT_TOTAL,
                    'Kwota zapłacona przekracza maksymalną obsługiwaną wartość.',
                ),
            ],
            'place_of_issue' => ['nullable', 'string', 'max:255'],
            'issuer_name' => ['nullable', 'string', 'max:255'],
            'additional_information_text' => ['nullable', 'string', 'max:10000'],
            'seller_name' => ['nullable', 'string', 'max:255'],
            'seller_tax_id' => ['nullable', 'string', 'max:30'],
            'seller_regon' => ['nullable', 'string', 'max:30'],
            'seller_bdo' => ['nullable', 'string', 'max:30'],
            'seller_street' => ['nullable', 'string', 'max:255'],
            'seller_building_number' => ['nullable', 'string', 'max:50'],
            'seller_apartment_number' => ['nullable', 'string', 'max:50'],
            'seller_postal_code' => ['nullable', 'string', 'max:20'],
            'seller_city' => ['nullable', 'string', 'max:150'],
            'seller_province' => ['nullable', 'string', 'max:150'],
            'seller_country_code' => ['nullable', 'string', Rule::in(app(CountryCatalog::class)->codes())],
            'seller_email' => ['nullable', 'email', 'max:255'],
            'seller_phone' => ['nullable', 'string', 'max:50'],
            'seller_bank_name' => ['nullable', 'string', 'max:255'],
            'seller_bank_account' => ['nullable', 'string', 'max:100'],
            'seller_bank_swift' => ['nullable', 'string', 'max:30'],
        ];
    }
}
