<?php

namespace Modules\Invoices\Services;

use Modules\Invoices\Enums\InvoiceDocumentStatus;
use Modules\Invoices\Enums\InvoiceDocumentType;
use Modules\Invoices\Exceptions\InvoiceDomainException;
use Modules\Invoices\Models\Invoice;
use Modules\Invoices\Models\OrderDocumentSlot;

class InvoiceEditabilityPolicy
{
    public function assertEditable(Invoice $invoice): void
    {
        if ($invoice->document_type !== InvoiceDocumentType::Invoice
            || $invoice->status !== InvoiceDocumentStatus::Issued) {
            throw new InvoiceDomainException(
                'invoice_edit_not_allowed',
                'Edytować można wyłącznie wystawioną Fakturę VAT.',
            );
        }

        if ($invoice->number === null
            || trim($invoice->number) === ''
            || $invoice->sequence_number === null
            || $invoice->numbering_period_key === null
            || $invoice->invoice_series_id === null
            || $invoice->series()->doesntExist()) {
            throw $this->inconsistent();
        }

        if ($invoice->corrections()->exists()) {
            throw new InvoiceDomainException(
                'invoice_edit_blocked_by_correction',
                'Nie można edytować Faktury, ponieważ została do niej wystawiona Korekta.',
            );
        }

        $slot = OrderDocumentSlot::query()
            ->where('order_id', $invoice->order_id)
            ->where('document_type', InvoiceDocumentType::Invoice)
            ->first();

        if ($slot === null || $slot->invoice_id !== $invoice->getKey()) {
            throw $this->inconsistent();
        }
    }

    private function inconsistent(): InvoiceDomainException
    {
        return new InvoiceDomainException(
            'invoice_edit_inconsistent_document',
            'Nie można edytować Faktury, ponieważ jej dane lub powiązania są niespójne.',
        );
    }
}
