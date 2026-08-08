<?php

namespace Modules\Invoices\Http\Requests;

class InvoiceExpectedLockVersionRequest extends InvoiceEditRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'expected_lock_version' => ['required', 'integer', 'min:1'],
            'return_to' => ['nullable', 'string', 'max:32'],
            'return_query' => ['nullable', 'string', 'max:4096'],
        ];
    }
}
