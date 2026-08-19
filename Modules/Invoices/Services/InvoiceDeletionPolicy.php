<?php

namespace Modules\Invoices\Services;

use Modules\Invoices\Enums\InvoiceDocumentStatus;
use Modules\Invoices\Enums\InvoiceDocumentType;
use Modules\Invoices\Exceptions\InvoiceDomainException;
use Modules\Invoices\Models\Invoice;
use Modules\Invoices\Models\OrderDocumentSlot;
use Modules\Invoices\ValueObjects\InvoiceDeletionFacts;

class InvoiceDeletionPolicy
{
    public function __construct(
        private readonly InvoiceMutationPolicy $mutationPolicy,
    ) {}

    public function assertHasOrderReference(Invoice $invoice): void
    {
        if ($invoice->order_id === null) {
            throw $this->inconsistent($invoice);
        }
    }

    public function assertDeletable(
        Invoice $invoice,
        ?OrderDocumentSlot $slot,
        int $expectedLockVersion,
        ?InvoiceDeletionFacts $facts = null,
    ): void {
        if (! in_array($invoice->document_type, [
            InvoiceDocumentType::Invoice,
            InvoiceDocumentType::Proforma,
            InvoiceDocumentType::Correction,
        ], true) || $invoice->status !== InvoiceDocumentStatus::Issued) {
            throw new InvoiceDomainException(
                'invoice_delete_not_allowed',
                'Usunąć można wyłącznie wystawioną Fakturę VAT, aktywną Pro formę albo Korektę.',
            );
        }

        if ($invoice->isInvoice()
            && ($facts?->hasKsefSubmission ?? $invoice->ksefSubmissions()->exists())) {
            throw new InvoiceDomainException(
                'invoice_delete_blocked_by_ksef_submission',
                'Nie można usunąć Faktury, ponieważ posiada historię przekazania do KSeF.',
            );
        }

        $this->mutationPolicy->assertContentMutable($invoice);

        if ($invoice->lock_version !== $expectedLockVersion) {
            if ($invoice->isCorrection()) {
                throw new InvoiceDomainException(
                    'correction_delete_conflict',
                    'Korekta została w międzyczasie zmieniona. Odśwież stronę i spróbuj ponownie.',
                );
            }

            throw new InvoiceDomainException(
                'invoice_delete_conflict',
                $invoice->isProforma()
                    ? 'Pro forma została w międzyczasie zmieniona. Odśwież stronę i spróbuj ponownie.'
                    : 'Faktura została w międzyczasie zmieniona. Odśwież stronę i spróbuj ponownie.',
            );
        }

        if ($invoice->number === null
            || trim($invoice->number) === ''
            || $invoice->sequence_number === null
            || $invoice->numbering_period_key === null
            || $invoice->invoice_series_id === null
            || $invoice->order_id === null
            || ! ($facts?->seriesExists ?? $invoice->series()->exists())
            || ! ($facts?->orderExists ?? $invoice->order()->exists())) {
            throw $this->inconsistent($invoice);
        }

        if ($invoice->isInvoice() && ($facts?->hasCorrection ?? $invoice->corrections()->exists())) {
            throw new InvoiceDomainException(
                'invoice_delete_blocked_by_correction',
                'Nie można usunąć Faktury, ponieważ została do niej wystawiona Korekta.',
            );
        }

        if ($invoice->isProforma()
            && ($invoice->proforma_superseded_at !== null || $invoice->superseded_by_invoice_id !== null)) {
            throw new InvoiceDomainException(
                'proforma_delete_blocked_by_invoice',
                'Do Pro Forma została już wystawiona Faktura VAT.',
            );
        }

        if ($invoice->isCorrection()) {
            $this->assertCorrectionRelations($invoice);
        }

        if ($slot === null
            || $slot->document_type !== $invoice->document_type
            || $slot->invoice_id !== $invoice->getKey()) {
            throw $this->inconsistent($invoice);
        }
    }

    private function inconsistent(Invoice $invoice): InvoiceDomainException
    {
        if ($invoice->isCorrection()) {
            return new InvoiceDomainException(
                'correction_delete_inconsistent_document',
                'Nie można usunąć Korekty, ponieważ jej dane lub powiązania są niespójne.',
            );
        }

        return new InvoiceDomainException(
            'invoice_delete_inconsistent_document',
            $invoice->isProforma()
                ? 'Nie można usunąć Pro formy, ponieważ jej dane lub powiązania są niespójne.'
                : 'Nie można usunąć Faktury, ponieważ jej dane lub powiązania są niespójne.',
        );
    }

    private function assertCorrectionRelations(Invoice $invoice): void
    {
        $source = $invoice->relationLoaded('correctedInvoice')
            ? $invoice->correctedInvoice
            : $invoice->correctedInvoice()->first();

        if ($source === null
            || ! $source->isInvoice()
            || $source->status !== InvoiceDocumentStatus::Issued
            || $source->order_id !== $invoice->order_id) {
            throw $this->inconsistent($invoice);
        }

    }
}
