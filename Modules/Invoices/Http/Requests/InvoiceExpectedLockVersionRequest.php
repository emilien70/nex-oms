<?php

namespace Modules\Invoices\Http\Requests;

class InvoiceExpectedLockVersionRequest extends InvoiceEditRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'expected_lock_version' => ['required', 'integer', 'min:1'],
            'return_to' => ['nullable', 'in:invoices,proformas,corrections'],
        ];
    }
}
