<?php

namespace Modules\Ksef\Services;

use DOMDocument;
use DOMXPath;
use Illuminate\Contracts\Encryption\DecryptException;
use Modules\Invoices\Exceptions\InvoiceDomainException;
use Modules\Invoices\Models\Invoice;
use Modules\Ksef\Enums\KsefContextIdentifierType;
use Modules\Ksef\Enums\KsefInvoiceSubmissionStatus;
use Modules\Ksef\Enums\KsefTechnicalCorrectionEligibility;
use Modules\Ksef\Exceptions\KsefApiException;
use Modules\Ksef\Models\KsefInvoiceSubmission;
use Modules\Ksef\Models\KsefOfflineIssuance;
use Modules\Ksef\Models\KsefOfflineTechnicalCorrection;
use Modules\Ksef\Services\Fa3\KsefFa3IssueDateReader;
use Modules\Ksef\Services\Fa3\KsefFa3SchemaValidator;
use Modules\Ksef\Services\Fa3\KsefFa3XmlBuilder;

final class KsefOfflineTechnicalCorrectionIntegrityService
{
    public function __construct(
        private readonly KsefOfflineTechnicalCorrectionEligibilityService $eligibility,
        private readonly KsefFa3SchemaValidator $schema,
        private readonly KsefFa3IssueDateReader $issueDates,
    ) {}

    public function assertSource(
        Invoice $invoice,
        KsefOfflineIssuance $issuance,
        KsefInvoiceSubmission $source,
    ): void {
        if (! $invoice->isInvoice()) {
            throw new KsefApiException(
                'Korekta techniczna jest obecnie dostępna wyłącznie dla zwykłej Faktury VAT Offline.',
                'ksef_technical_correction_document_type_not_supported',
            );
        }

        if (! $invoice->isIssued() || ! $invoice->isFinalized()) {
            throw $this->sourceInvalid();
        }

        try {
            $issuancePayload = $issuance->payload_xml;
            $sourcePayload = $source->payload_xml;
        } catch (DecryptException) {
            throw $this->sourceInvalid();
        }

        if ($source->status !== KsefInvoiceSubmissionStatus::Rejected
            || $source->ksef_number !== null
            || $issuance->context_identifier_type !== KsefContextIdentifierType::Nip
            || $invoice->issue_date === null
            || $issuance->issue_date === null
            || $invoice->issue_date->toDateString() !== $issuance->issue_date->toDateString()
            || $invoice->getKey() !== $issuance->invoice_id
            || $source->invoice_id !== $invoice->getKey()
            || $source->offline_issuance_id !== $issuance->getKey()
            || $source->offline_technical_correction_id !== null
            || $source->environment !== $issuance->environment
            || ! hash_equals((string) $source->context_nip, (string) $issuance->context_identifier_value)
            || ! hash_equals((string) $source->seller_nip, (string) $issuance->seller_nip)
            || ! hash_equals((string) $source->schema_id, (string) $issuance->schema_id)
            || ! is_string($issuancePayload)
            || $issuancePayload === ''
            || ! is_string($sourcePayload)
            || ! hash_equals($issuancePayload, $sourcePayload)
            || ! hash_equals((string) $source->invoice_hash, (string) $issuance->invoice_hash)
            || $source->invoice_size !== $issuance->invoice_size
            || $source->generated_at?->getTimestamp() !== $issuance->issued_at?->getTimestamp()
            || strlen($issuancePayload) !== $issuance->invoice_size
            || ! hash_equals((string) $issuance->invoice_hash, $this->hash($issuancePayload))) {
            throw $this->sourceInvalid();
        }

        $classification = $this->eligibility->classify($source->ksef_status_code);
        if ($classification === KsefTechnicalCorrectionEligibility::Ineligible) {
            throw new KsefApiException(
                'To odrzucenie nie kwalifikuje się do korekty technicznej KSeF.',
                'ksef_technical_correction_source_nontechnical',
            );
        }

        if ($classification !== KsefTechnicalCorrectionEligibility::Eligible) {
            throw new KsefApiException(
                'Nie można jednoznacznie potwierdzić, że dokument kwalifikuje się do korekty technicznej KSeF.',
                'ksef_technical_correction_source_unconfirmed',
            );
        }
    }

    public function assertArtifact(
        KsefOfflineTechnicalCorrection $artifact,
        ?Invoice $invoice = null,
        ?KsefOfflineIssuance $issuance = null,
        ?KsefInvoiceSubmission $source = null,
    ): void {
        $invoice ??= Invoice::query()->find($artifact->invoice_id);
        $issuance ??= KsefOfflineIssuance::query()->find($artifact->offline_issuance_id);
        $source ??= KsefInvoiceSubmission::query()->find($artifact->rejected_submission_id);

        if (! $invoice instanceof Invoice
            || ! $issuance instanceof KsefOfflineIssuance
            || ! $source instanceof KsefInvoiceSubmission) {
            throw $this->artifactInvalid();
        }

        $this->assertSource($invoice, $issuance, $source);

        try {
            $payload = $artifact->payload_xml;
        } catch (DecryptException) {
            throw $this->artifactInvalid();
        }

        if (! is_string($payload)
            || $payload === ''
            || $artifact->invoice_id !== $invoice->getKey()
            || $artifact->offline_issuance_id !== $issuance->getKey()
            || $artifact->rejected_submission_id !== $source->getKey()
            || $artifact->environment !== $issuance->environment
            || ! hash_equals((string) $artifact->context_nip, (string) $issuance->context_identifier_value)
            || ! hash_equals((string) $artifact->seller_nip, (string) $issuance->seller_nip)
            || $artifact->schema_id !== KsefFa3SchemaValidator::SCHEMA_ID
            || $artifact->generated_at === null
            || strlen($payload) !== $artifact->invoice_size
            || ! hash_equals((string) $artifact->invoice_hash, $this->hash($payload))
            || ! hash_equals((string) $artifact->hash_of_corrected_invoice, (string) $issuance->invoice_hash)
            || ! hash_equals((string) $artifact->hash_of_corrected_invoice, (string) $source->invoice_hash)
            || hash_equals((string) $artifact->invoice_hash, (string) $artifact->hash_of_corrected_invoice)) {
            throw $this->artifactInvalid();
        }

        try {
            $this->schema->validate($payload);
            $issueDate = $this->issueDates->read($payload);
            $documentNumber = $this->documentNumber($payload);
        } catch (InvoiceDomainException|KsefApiException) {
            throw $this->artifactInvalid();
        }

        if ($invoice->issue_date?->toDateString() !== $issueDate
            || ! is_string($invoice->number)
            || ! hash_equals($invoice->number, $documentNumber)) {
            throw $this->artifactInvalid();
        }
    }

    public function linkedArtifact(
        KsefInvoiceSubmission $submission,
        ?string $expectedPayload = null,
    ): KsefOfflineTechnicalCorrection {
        if ($submission->offline_technical_correction_id === null) {
            throw $this->artifactInvalid();
        }

        $artifact = KsefOfflineTechnicalCorrection::query()
            ->find($submission->offline_technical_correction_id);
        if ($artifact === null) {
            throw $this->artifactInvalid();
        }

        $this->assertArtifact($artifact);
        $payload = $submission->payload_xml;

        if (! is_string($payload)
            || $payload === ''
            || $submission->invoice_id !== $artifact->invoice_id
            || $submission->offline_issuance_id !== $artifact->offline_issuance_id
            || $submission->environment !== $artifact->environment
            || ! hash_equals((string) $submission->context_nip, (string) $artifact->context_nip)
            || ! hash_equals((string) $submission->seller_nip, (string) $artifact->seller_nip)
            || ! hash_equals((string) $submission->schema_id, (string) $artifact->schema_id)
            || ! hash_equals($payload, (string) $artifact->payload_xml)
            || ! hash_equals((string) $submission->invoice_hash, (string) $artifact->invoice_hash)
            || $submission->invoice_size !== $artifact->invoice_size
            || $submission->generated_at?->getTimestamp() !== $artifact->generated_at?->getTimestamp()
            || ($expectedPayload !== null && ! hash_equals($expectedPayload, $payload))) {
            throw $this->artifactInvalid();
        }

        return $artifact;
    }

    public function assertNoAcceptedSibling(
        Invoice $invoice,
        KsefOfflineIssuance $issuance,
        bool $lock = false,
    ): void {
        $accepted = KsefInvoiceSubmission::query()
            ->where('invoice_id', $invoice->getKey())
            ->where('environment', $issuance->environment->value)
            ->where('status', KsefInvoiceSubmissionStatus::Accepted->value)
            ->when($lock, fn ($query) => $query->lockForUpdate())
            ->exists();

        if ($accepted) {
            throw new KsefApiException(
                'Faktura ma już zaakceptowaną transmisję KSeF w tym środowisku.',
                'ksef_technical_correction_already_accepted',
            );
        }
    }

    private function documentNumber(string $xml): string
    {
        $document = new DOMDocument;
        $document->resolveExternals = false;
        $document->substituteEntities = false;
        $previous = libxml_use_internal_errors(true);

        try {
            $loaded = $document->loadXML($xml, LIBXML_NONET | LIBXML_NOBLANKS);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        if (! $loaded) {
            throw $this->artifactInvalid();
        }

        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('fa', KsefFa3XmlBuilder::NAMESPACE);
        $nodes = $xpath->query('/fa:Faktura/fa:Fa/fa:P_2');
        $value = $nodes !== false && $nodes->length === 1
            ? trim($nodes->item(0)?->textContent ?? '')
            : '';

        if ($value === '') {
            throw $this->artifactInvalid();
        }

        return $value;
    }

    private function hash(string $payload): string
    {
        return base64_encode(hash('sha256', $payload, true));
    }

    private function sourceInvalid(): KsefApiException
    {
        return new KsefApiException(
            'Źródłowa odrzucona Faktura Offline jest niekompletna lub niespójna.',
            'ksef_technical_correction_source_integrity_invalid',
        );
    }

    private function artifactInvalid(): KsefApiException
    {
        return new KsefApiException(
            'Zamrożona korekta techniczna KSeF jest niekompletna lub niespójna.',
            'ksef_technical_correction_integrity_invalid',
        );
    }
}
