<?php

namespace Modules\Invoices\Http\Requests;

use App\Support\CountryCatalog;
use Illuminate\Validation\Rule;

class UpdateInvoiceBuyerRequest extends InvoiceEditRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge(['country_code' => app(CountryCatalog::class)->normalize($this->input('country_code'))]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return $this->addressRules(true);
    }

    /** @return array<string, mixed> */
    protected function addressRules(bool $withTaxId): array
    {
        $rules = [
            'expected_lock_version' => ['required', 'integer', 'min:1'],
            'name' => ['nullable', 'string', 'max:255', 'required_without:company_name'],
            'company_name' => ['nullable', 'string', 'max:255', 'required_without:name'],
            'street' => ['nullable', 'string', 'max:255'],
            'building_number' => ['nullable', 'string', 'max:50'],
            'apartment_number' => ['nullable', 'string', 'max:50'],
            'postal_code' => ['nullable', 'string', 'max:20'],
            'city' => ['nullable', 'string', 'max:150'],
            'province' => ['nullable', 'string', 'max:150'],
            'country_code' => ['nullable', 'string', Rule::in(app(CountryCatalog::class)->codes())],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
        ];
        if ($withTaxId) {
            $rules['tax_id'] = ['nullable', 'string', 'max:30'];
        }

        return $rules;
    }
}
