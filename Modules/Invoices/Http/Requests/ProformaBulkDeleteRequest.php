<?php

namespace Modules\Invoices\Http\Requests;

class ProformaBulkDeleteRequest extends InvoiceBulkDeleteRequest
{
    protected function documentSingularAccusative(): string
    {
        return 'Pro formę';
    }

    protected function documentPluralGenitive(): string
    {
        return 'Pro form';
    }
}
