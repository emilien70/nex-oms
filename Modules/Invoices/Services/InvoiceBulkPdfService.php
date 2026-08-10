<?php

namespace Modules\Invoices\Services;

use Illuminate\Support\Collection;
use Modules\Invoices\Enums\InvoiceDocumentStatus;
use Modules\Invoices\Enums\InvoiceDocumentType;
use Modules\Invoices\Exceptions\InvoiceDomainException;
use Modules\Invoices\Models\Invoice;

class InvoiceBulkPdfService
{
    public function __construct(
        private readonly InvoicePdfRenderer $renderer,
    ) {}

    /** @param array<int, int> $invoiceIds */
    public function contents(
        array $invoiceIds,
        InvoiceDocumentType $documentType = InvoiceDocumentType::Invoice,
    ): string {
        $invoicesById = Invoice::query()
            ->with('items')
            ->whereIn('id', $invoiceIds)
            ->get()
            ->keyBy(fn (Invoice $invoice): int => (int) $invoice->getKey());

        $invoices = collect($invoiceIds)
            ->map(fn (int $id): ?Invoice => $invoicesById->get($id));

        if ($invoices->contains(null)
            || $invoices->contains(fn (?Invoice $invoice): bool => $invoice === null
                || $invoice->document_type !== $documentType
                || $invoice->status !== InvoiceDocumentStatus::Issued)) {
            $documentLabel = match ($documentType) {
                InvoiceDocumentType::Invoice => 'Faktury VAT',
                InvoiceDocumentType::Proforma => 'Pro formy',
                InvoiceDocumentType::Correction => 'Korekty',
            };

            throw new InvoiceDomainException(
                'invoice_bulk_pdf_invalid_selection',
                'Zbiorczy wydruk może zawierać wyłącznie wystawione '.$documentLabel.'.',
            );
        }

        /** @var Collection<int, Invoice> $invoices */
        return $this->renderer->renderMany($invoices, $documentType);
    }
}
