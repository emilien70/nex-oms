<?php

namespace Modules\Ksef\Services;

use Modules\Invoices\Exceptions\InvoiceDomainException;
use Modules\Invoices\Models\Invoice;
use Modules\Ksef\Enums\KsefContextIdentifierType;
use Modules\Ksef\Enums\KsefEnvironment;
use Modules\Ksef\Enums\KsefOfflineIssuanceProcedure;
use Modules\Ksef\Exceptions\KsefApiException;
use Modules\Ksef\Models\KsefInvoiceSubmission;
use Modules\Ksef\Models\KsefOfflineIssuance;
use Modules\Ksef\Services\Fa3\KsefFa3CorrectionSourceReferenceResolver;

final class KsefOfflineSubmissionIntegrityService
{
    public function __construct(
        private readonly KsefOfflinePresentationDataExtractor $presentations,
        private readonly KsefFa3CorrectionSourceReferenceResolver $correctionSources,
        private readonly KsefFa3BuyerIdentityResolver $buyerIdentity,
    ) {}

    public function assertIssuance(KsefOfflineIssuance $issuance, ?Invoice $invoice = null): void
    {
        $invoice ??= Invoice::query()->find($issuance->invoice_id);

        if (! in_array($issuance->procedure, KsefOfflineIssuanceProcedure::cases(), true)
            || ! in_array($issuance->environment, [KsefEnvironment::Test, KsefEnvironment::Demo], true)
            || $issuance->context_identifier_type !== KsefContextIdentifierType::Nip
            || $invoice === null
            || (! $invoice->isInvoice() && ! $invoice->isCorrection())
            || $invoice->getKey() !== $issuance->invoice_id
            || preg_match('/^\d{10}$/', (string) $issuance->context_identifier_value) !== 1
            || preg_match('/^\d{10}$/', (string) $issuance->seller_nip) !== 1
            || ! hash_equals((string) $issuance->seller_nip, (string) $issuance->context_identifier_value)) {
            throw $this->invalid();
        }

        try {
            $presentation = $this->presentations->extract($issuance);
            if ($invoice->isCorrection() !== ($presentation->correction !== null)) {
                throw $this->invalid();
            }
            if ($invoice->isCorrection()) {
                if (! $invoice->isFinalized() || $presentation->invoiceNumber !== $invoice->number) {
                    throw $this->invalid();
                }
                $source = $this->correctionSources->resolve($invoice, $issuance->environment, lock: true);
                $frozen = $presentation->correction;
                $rootSellerNip = $this->buyerIdentity->normalizePolishNip(
                    data_get($invoice->correctedInvoice()->firstOrFail()->seller_snapshot, 'tax_id'),
                );
                if ($rootSellerNip !== $issuance->seller_nip
                    || $source->rootInvoiceId !== $invoice->corrected_invoice_id
                    || $source->rootInvoiceNumber !== $frozen['source_number']
                    || $source->correctedInvoiceIssueDate !== $frozen['source_issue_date']
                    || $source->rootKsefNumber !== $frozen['source_ksef_number']
                    || ($source->rootProvenanceId !== null) !== $frozen['outside_ksef']) {
                    throw $this->invalid();
                }
            }
        } catch (KsefApiException|InvoiceDomainException) {
            throw $this->invalid();
        }
    }

    public function linkedIssuance(
        KsefInvoiceSubmission $submission,
        ?string $expectedPayload = null,
    ): KsefOfflineIssuance {
        if ($submission->offline_issuance_id === null) {
            throw $this->invalid();
        }

        $issuance = KsefOfflineIssuance::query()->find($submission->offline_issuance_id);
        if ($issuance === null) {
            throw $this->invalid();
        }

        $this->assertIssuance($issuance);

        $payload = $submission->payload_xml;
        if (! is_string($payload)
            || $payload === ''
            || $submission->invoice_id !== $issuance->invoice_id
            || $submission->environment !== $issuance->environment
            || ! hash_equals((string) $submission->context_nip, (string) $issuance->context_identifier_value)
            || ! hash_equals((string) $submission->seller_nip, (string) $issuance->seller_nip)
            || ! hash_equals((string) $submission->schema_id, (string) $issuance->schema_id)
            || ! hash_equals($payload, (string) $issuance->payload_xml)
            || ! hash_equals((string) $submission->invoice_hash, (string) $issuance->invoice_hash)
            || $submission->invoice_size !== $issuance->invoice_size
            || $submission->generated_at?->getTimestamp() !== $issuance->issued_at?->getTimestamp()
            || ($expectedPayload !== null && ! hash_equals($expectedPayload, $payload))) {
            throw $this->invalid();
        }

        return $issuance;
    }

    private function invalid(): KsefApiException
    {
        return new KsefApiException(
            'Zamrożone dane Faktury Offline są niekompletne lub niespójne. Dokument nie został wysłany.',
            'ksef_offline_submission_integrity_invalid',
        );
    }
}
