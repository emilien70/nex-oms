<?php

namespace Modules\Invoices\Http\Requests;

use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Unique;

class StoreInvoiceSeriesRequest extends InvoiceSeriesRequest
{
    protected function uniqueNameRule(): Unique
    {
        return Rule::unique('invoice_series', 'name')
            ->where('document_type', (string) $this->input('document_type'));
    }
}
