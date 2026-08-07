<?php

namespace Modules\Invoices\Http\Requests;

class CorrectionBulkDeleteRequest extends InvoiceBulkDeleteRequest
{
    protected function documentSingularAccusative(): string
    {
        return 'Korektę';
    }

    protected function documentPluralGenitive(): string
    {
        return 'Korekt';
    }
}
