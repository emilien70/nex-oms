<?php

namespace Modules\Invoices\Http\Requests;

class UpdateInvoiceRecipientRequest extends UpdateInvoiceBuyerRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return $this->addressRules(false);
    }
}
