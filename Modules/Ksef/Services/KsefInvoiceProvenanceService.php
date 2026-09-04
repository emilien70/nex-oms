<?php

namespace Modules\Ksef\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\Invoices\Enums\InvoiceDocumentStatus;
use Modules\Invoices\Enums\InvoiceDocumentType;
use Modules\Invoices\Exceptions\InvoiceDomainException;
use Modules\Invoices\Models\Invoice;
use Modules\Ksef\Enums\KsefEnvironment;
use Modules\Ksef\Enums\KsefInvoiceProvenanceType;
use Modules\Ksef\Models\KsefInvoiceProvenance;
use Modules\Ksef\Models\KsefInvoiceSubmission;
use Modules\Ksef\Models\KsefOfflineIssuance;

final class KsefInvoiceProvenanceService
{
    public function markOutsideKsef(
        Invoice $invoice,
        KsefEnvironment $environment,
    ): KsefInvoiceProvenance {
        return DB::transaction(function () use ($invoice, $environment): KsefInvoiceProvenance {
            $managed = Invoice::query()
                ->lockForUpdate()
                ->findOrFail($invoice->getKey());

            if ($managed->document_type !== InvoiceDocumentType::Invoice
                || $managed->status !== InvoiceDocumentStatus::Issued
                || ! is_string($managed->number)
                || trim($managed->number) === ''
                || $managed->issue_date === null) {
                throw new InvoiceDomainException(
                    'ksef_invoice_provenance_document_invalid',
                    'Jako wystawioną poza KSeF można oznaczyć wyłącznie wystawioną Fakturę VAT.',
                    ['invoice_id' => $managed->getKey()],
                );
            }

            if (! $managed->isFinalized()) {
                throw new InvoiceDomainException(
                    'ksef_invoice_provenance_document_not_finalized',
                    'Przed oznaczeniem Faktury jako wystawionej poza KSeF należy ją najpierw zamknąć.',
                    ['invoice_id' => $managed->getKey()],
                );
            }

            if (KsefOfflineIssuance::query()
                ->where('invoice_id', $managed->getKey())
                ->where('environment', $environment->value)
                ->lockForUpdate()
                ->exists()) {
                throw new InvoiceDomainException(
                    'ksef_invoice_provenance_offline_issuance_exists',
                    'Faktura została wystawiona w trybie Offline w wybranym środowisku.',
                    [
                        'invoice_id' => $managed->getKey(),
                        'environment' => $environment->value,
                    ],
                );
            }

            $submissions = KsefInvoiceSubmission::query()
                ->where('invoice_id', $managed->getKey())
                ->where('environment', $environment->value)
                ->lockForUpdate()
                ->get(['id', 'status']);
            if ($submissions->isNotEmpty()) {
                throw new InvoiceDomainException(
                    'ksef_invoice_provenance_submission_history_exists',
                    'Faktura posiada historię przekazywania do KSeF w wybranym środowisku.',
                    [
                        'invoice_id' => $managed->getKey(),
                        'environment' => $environment->value,
                        'submission_statuses' => $submissions
                            ->map(static fn (KsefInvoiceSubmission $submission): string => $submission->status->value)
                            ->uniqueStrict()
                            ->sort()
                            ->values()
                            ->all(),
                    ],
                );
            }

            $existing = KsefInvoiceProvenance::query()
                ->where('invoice_id', $managed->getKey())
                ->where('environment', $environment->value)
                ->lockForUpdate()
                ->first();
            if ($existing !== null) {
                return $existing;
            }

            return KsefInvoiceProvenance::query()->create([
                'invoice_id' => $managed->getKey(),
                'environment' => $environment,
                'provenance' => KsefInvoiceProvenanceType::OutsideKsef,
                'recorded_at' => CarbonImmutable::now('UTC'),
            ]);
        }, 3);
    }
}
