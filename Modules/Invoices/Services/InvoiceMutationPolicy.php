<?php

namespace Modules\Invoices\Services;

use Modules\Invoices\Exceptions\InvoiceDomainException;
use Modules\Invoices\Models\Invoice;

class InvoiceMutationPolicy
{
    public function assertContentMutable(Invoice $invoice): void
    {
        if (! $invoice->isFinalized()) {
            return;
        }

        throw new InvoiceDomainException(
            $invoice->isCorrection() ? 'correction_finalized' : 'invoice_finalized',
            $invoice->isCorrection()
                ? 'Korekta została zamknięta i nie może być edytowana ani usunięta.'
                : 'Dokument został zamknięty i nie może być edytowany ani usunięty.',
        );
    }
}
