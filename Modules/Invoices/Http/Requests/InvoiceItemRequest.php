<?php

namespace Modules\Invoices\Http\Requests;

class InvoiceItemRequest extends InvoiceEditRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'expected_lock_version' => ['required', 'integer', 'min:1'],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'unit_name' => ['required', 'string', 'max:30'],
            'quantity' => ['required', 'regex:/^\d+(?:[\.,]\d{1,4})?$/', 'not_in:0,0.0,0.00,0.000,0.0000'],
            'unit_price_gross' => ['required', 'regex:/^\d+(?:[\.,]\d{1,4})?$/'],
            'vat_rate' => ['nullable', 'regex:/^\d+(?:[\.,]\d{1,2})?$/', 'required_without:vat_code'],
            'vat_code' => ['nullable', 'string', 'max:20', 'regex:/^[A-Za-z0-9_]+$/', 'required_without:vat_rate'],
            'position' => ['required', 'integer', 'min:1', 'max:65535'],
        ];
    }
}
