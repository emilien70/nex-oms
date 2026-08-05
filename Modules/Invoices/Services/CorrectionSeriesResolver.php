<?php

namespace Modules\Invoices\Services;

use Illuminate\Database\Eloquent\Collection;
use Modules\Invoices\Enums\InvoiceDocumentType;
use Modules\Invoices\Enums\InvoiceSeriesSystemKey;
use Modules\Invoices\Exceptions\InvoiceDomainException;
use Modules\Invoices\Models\Invoice;
use Modules\Invoices\Models\InvoiceSeries;

class CorrectionSeriesResolver
{
    /** @return Collection<int, InvoiceSeries> */
    public function active(): Collection
    {
        return InvoiceSeries::query()
            ->where('document_type', InvoiceDocumentType::Correction)
            ->where('is_active', true)
            ->orderByDesc('is_system')
            ->orderBy('name')
            ->orderBy('id')
            ->get();
    }

    public function resolve(Invoice $sourceInvoice, ?int $requestedSeriesId = null): InvoiceSeries
    {
        if ($requestedSeriesId !== null) {
            $requested = InvoiceSeries::query()->find($requestedSeriesId);

            if ($requested !== null
                && $requested->is_active
                && $requested->document_type === InvoiceDocumentType::Correction) {
                return $requested;
            }

            throw new InvoiceDomainException(
                'correction_series_invalid',
                'Wybrana seria numeracji nie może zostać użyta do wystawienia Korekty.',
            );
        }

        $sourceInvoice->loadMissing('series.defaultCorrectionSeries');
        $assigned = $sourceInvoice->series?->defaultCorrectionSeries;

        if ($assigned !== null
            && $assigned->is_active
            && $assigned->document_type === InvoiceDocumentType::Correction) {
            return $assigned;
        }

        $system = InvoiceSeries::query()
            ->where('document_type', InvoiceDocumentType::Correction)
            ->where('system_key', InvoiceSeriesSystemKey::Correction)
            ->where('is_active', true)
            ->first();

        if ($system !== null) {
            return $system;
        }

        throw new InvoiceDomainException(
            'correction_series_missing',
            'Brak aktywnej serii numeracji dla Korekt.',
        );
    }
}
