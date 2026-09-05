<?php

namespace Modules\Ksef\Services;

use Illuminate\Support\Collection;
use Modules\Invoices\Exceptions\InvoiceDomainException;
use Modules\Invoices\Models\Invoice;
use Modules\Ksef\Models\KsefOfflineIssuance;

final class KsefOfflineStandardPdfGuard
{
    public function assertAllowed(Invoice $invoice): void
    {
        if (! $invoice->isInvoice() && ! $invoice->isCorrection()) {
            return;
        }

        if (KsefOfflineIssuance::query()->where('invoice_id', $invoice->getKey())->exists()) {
            throw new InvoiceDomainException(
                'invoice_pdf_ksef_offline24_requires_delivery_policy',
                'Ta Faktura została wystawiona w trybie Offline. Pobierz dokument właściwy dla nabywcy z panelu KSeF.',
            );
        }
    }

    /** @param Collection<int, Invoice> $invoices */
    public function assertManyAllowed(Collection $invoices): void
    {
        if ($invoices->isEmpty()) {
            return;
        }

        $invoiceIds = $invoices
            ->filter(fn (Invoice $invoice): bool => $invoice->isInvoice() || $invoice->isCorrection())
            ->map(fn (Invoice $invoice): int => (int) $invoice->getKey())
            ->all();

        if ($invoiceIds !== [] && KsefOfflineIssuance::query()->whereIn('invoice_id', $invoiceIds)->exists()) {
            throw new InvoiceDomainException(
                'invoice_pdf_ksef_offline24_requires_delivery_policy',
                'Zbiorczy PDF nie może zawierać Faktury wystawionej w trybie Offline.',
            );
        }
    }
}
