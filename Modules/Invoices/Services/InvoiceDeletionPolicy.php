<?php

namespace Modules\Invoices\Services;

use Modules\Invoices\Enums\InvoiceDocumentStatus;
use Modules\Invoices\Enums\InvoiceDocumentType;
use Modules\Invoices\Exceptions\InvoiceDomainException;
use Modules\Invoices\Models\Invoice;
use Modules\Invoices\Models\OrderDocumentSlot;

class InvoiceDeletionPolicy
{
    public function assertHasOrderReference(Invoice $invoice): void
    {
        if ($invoice->order_id === null) {
            throw $this->inconsistent();
        }
    }

    public function assertDeletable(
        Invoice $invoice,
        ?OrderDocumentSlot $slot,
        int $expectedLockVersion,
    ): void {
        if ($invoice->document_type !== InvoiceDocumentType::Invoice
            || $invoice->status !== InvoiceDocumentStatus::Issued) {
            throw new InvoiceDomainException(
                'invoice_delete_not_allowed',
                'Usunąć można wyłącznie wystawioną Fakturę VAT.',
            );
        }

        if ($invoice->lock_version !== $expectedLockVersion) {
            throw new InvoiceDomainException(
                'invoice_delete_conflict',
                'Faktura została w międzyczasie zmieniona. Odśwież stronę i spróbuj ponownie.',
            );
        }

        if ($invoice->number === null
            || trim($invoice->number) === ''
            || $invoice->sequence_number === null
            || $invoice->numbering_period_key === null
            || $invoice->invoice_series_id === null
            || $invoice->order_id === null
            || $invoice->series()->doesntExist()
            || $invoice->order()->doesntExist()) {
            throw $this->inconsistent();
        }

        if ($invoice->corrections()->exists()) {
            throw new InvoiceDomainException(
                'invoice_delete_blocked_by_correction',
                'Nie można usunąć Faktury, ponieważ została do niej wystawiona Korekta.',
            );
        }

        if ($slot === null || $slot->invoice_id !== $invoice->getKey()) {
            throw $this->inconsistent();
        }
    }

    private function inconsistent(): InvoiceDomainException
    {
        return new InvoiceDomainException(
            'invoice_delete_inconsistent_document',
            'Nie można usunąć Faktury, ponieważ jej dane lub powiązania są niespójne.',
        );
    }
}
