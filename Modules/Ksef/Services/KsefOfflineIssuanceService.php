<?php

namespace Modules\Ksef\Services;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Modules\Invoices\Enums\InvoiceDocumentStatus;
use Modules\Invoices\Enums\InvoiceDocumentType;
use Modules\Invoices\Models\Invoice;
use Modules\Ksef\Enums\KsefContextIdentifierType;
use Modules\Ksef\Enums\KsefFa3EligibilityMode;
use Modules\Ksef\Enums\KsefInvoiceProvenanceType;
use Modules\Ksef\Enums\KsefOfflineIssuanceProcedure;
use Modules\Ksef\Exceptions\KsefApiException;
use Modules\Ksef\Models\KsefInvoiceProvenance;
use Modules\Ksef\Models\KsefInvoiceSubmission;
use Modules\Ksef\Models\KsefOfflineCertificate;
use Modules\Ksef\Models\KsefOfflineCertificateSelection;
use Modules\Ksef\Models\KsefOfflineIssuance;
use Modules\Ksef\Models\KsefSeriesSetting;
use Modules\Ksef\Models\KsefSetting;
use Modules\Ksef\Services\Fa3\KsefFa3DocumentGenerator;
use Modules\Ksef\Services\Fa3\KsefFa3IssueDateReader;
use Modules\Ksef\ValueObjects\KsefContextIdentifier;

final class KsefOfflineIssuanceService
{
    public function __construct(
        private readonly KsefFa3DocumentGenerator $generator,
        private readonly KsefFa3IssueDateReader $issueDates,
        private readonly KsefFa3BuyerIdentityResolver $buyerIdentity,
        private readonly KsefOperationalEnvironmentPolicy $environments,
        private readonly KsefOfflineCertificateReadinessService $certificateReadiness,
        private readonly KsefInvoiceVerificationLinkBuilder $invoiceLinks,
        private readonly KsefOfflineCertificateVerificationLinkBuilder $certificateLinks,
    ) {}

    public function issueOffline24(Invoice $invoice): KsefOfflineIssuance
    {
        $issuedAt = CarbonImmutable::now('UTC');
        $snapshot = $this->captureSnapshot($invoice, $issuedAt);
        $generated = $this->generator->generate(
            $snapshot['invoice'],
            $issuedAt,
            KsefFa3EligibilityMode::Authoritative,
        );
        $issueDate = $this->issueDates->read($generated->xml);

        if ($issueDate !== $issuedAt->setTimezone('Europe/Warsaw')->toDateString()) {
            throw new KsefApiException(
                'Data wystawienia P_1 dokumentu Offline24 musi być dzisiejszą datą w Polsce.',
                'ksef_offline24_issue_date_not_today',
            );
        }

        $invoiceHash = base64_encode(hash('sha256', $generated->xml, true));
        $invoiceVerificationUrl = $this->invoiceLinks->buildFor(
            $snapshot['environment'],
            $snapshot['seller_nip'],
            CarbonImmutable::createFromFormat('!Y-m-d', $issueDate, 'Europe/Warsaw'),
            $invoiceHash,
        );

        if ($invoiceVerificationUrl === null) {
            throw new KsefApiException(
                'Nie udało się utworzyć KODU I dla Faktury Offline24.',
                'ksef_offline24_invoice_verification_link_invalid',
            );
        }

        try {
            $certificateVerificationUrl = $this->certificateLinks->build(
                $snapshot['environment'],
                $snapshot['context'],
                $snapshot['seller_nip'],
                $snapshot['certificate']->certificate_serial_number,
                $invoiceHash,
                (string) $snapshot['certificate']->private_key_pem,
            )->url;
        } catch (InvalidArgumentException) {
            throw new KsefApiException(
                'Nie udało się bezpiecznie utworzyć KODU II dla Faktury Offline24.',
                'ksef_offline24_certificate_verification_link_invalid',
            );
        }

        $attributes = [
            'invoice_id' => $snapshot['invoice']->getKey(),
            'environment' => $snapshot['environment'],
            'procedure' => KsefOfflineIssuanceProcedure::Offline24,
            'issue_date' => $issueDate,
            'issued_at' => $issuedAt,
            'seller_nip' => $snapshot['seller_nip'],
            'context_identifier_type' => $snapshot['context']->type,
            'context_identifier_value' => $snapshot['context']->value,
            'schema_id' => $generated->schemaId,
            'payload_xml' => $generated->xml,
            'invoice_hash' => $invoiceHash,
            'invoice_size' => strlen($generated->xml),
            'offline_certificate_id' => $snapshot['certificate']->getKey(),
            'certificate_serial_number' => $snapshot['certificate']->certificate_serial_number,
            'certificate_fingerprint_sha256' => $snapshot['certificate']->fingerprint_sha256,
            'certificate_valid_from' => $snapshot['certificate']->valid_from,
            'certificate_valid_until' => $snapshot['certificate']->valid_until,
            'certificate_remote_status' => $snapshot['certificate']->remote_status,
            'certificate_remote_valid_from' => $snapshot['certificate']->remote_valid_from,
            'certificate_remote_valid_until' => $snapshot['certificate']->remote_valid_until,
            'certificate_remote_verified_at' => $snapshot['certificate']->remote_verified_at,
            'invoice_verification_url' => $invoiceVerificationUrl,
            'certificate_verification_url' => $certificateVerificationUrl,
        ];

        try {
            return DB::transaction(function () use ($snapshot, $issuedAt, $attributes): KsefOfflineIssuance {
                $this->assertSnapshotStillCurrent($snapshot, $issuedAt);

                return KsefOfflineIssuance::query()->create($attributes);
            }, 3);
        } catch (QueryException $exception) {
            if (KsefOfflineIssuance::query()
                ->where('invoice_id', $snapshot['invoice']->getKey())
                ->where('environment', $snapshot['environment']->value)
                ->exists()) {
                throw $this->alreadyIssued();
            }

            throw $exception;
        }
    }

    /** @return array<string, mixed> */
    private function captureSnapshot(Invoice $invoice, CarbonImmutable $issuedAt): array
    {
        $managed = Invoice::query()->with('items')->findOrFail($invoice->getKey());
        $this->assertInvoiceEligible($managed);

        $settings = KsefSetting::query()
            ->where('singleton_key', KsefSetting::SINGLETON_KEY)
            ->first();
        $this->assertSettingsActive($settings);
        $environment = $settings->environment;
        $this->environments->assertAllowed($environment);
        $context = $this->contextIdentifier($settings->context_nip);
        $sellerNip = $this->sellerNip($managed);
        $this->assertOwnContext($context, $sellerNip);

        $seriesSetting = KsefSeriesSetting::query()
            ->where('invoice_series_id', $managed->invoice_series_id)
            ->first();
        $this->assertSeriesEnabled($seriesSetting);
        $this->assertNoConflictingHistory($managed, $environment->value);

        $selection = KsefOfflineCertificateSelection::query()
            ->with('certificate')
            ->where('environment', $environment->value)
            ->first();
        $certificate = $this->selectedCertificate($selection, $environment->value);
        $this->assertCertificateReady($certificate, $issuedAt);

        return [
            'invoice' => $managed,
            'invoice_fingerprint' => $this->invoiceFingerprint($managed),
            'settings_fingerprint' => $this->modelFingerprint($settings),
            'series_fingerprint' => $this->modelFingerprint($seriesSetting),
            'selection_fingerprint' => $this->modelFingerprint($selection),
            'certificate_fingerprint' => $this->modelFingerprint($certificate),
            'certificate_material_fingerprint' => $this->certificateMaterialFingerprint($certificate),
            'environment' => $environment,
            'context' => $context,
            'seller_nip' => $sellerNip,
            'certificate' => $certificate,
        ];
    }

    /** @param array<string, mixed> $snapshot */
    private function assertSnapshotStillCurrent(array $snapshot, CarbonImmutable $issuedAt): void
    {
        $invoice = Invoice::query()
            ->with('items')
            ->lockForUpdate()
            ->findOrFail($snapshot['invoice']->getKey());
        $settings = KsefSetting::query()
            ->where('singleton_key', KsefSetting::SINGLETON_KEY)
            ->lockForUpdate()
            ->first();
        $seriesSetting = KsefSeriesSetting::query()
            ->where('invoice_series_id', $invoice->invoice_series_id)
            ->lockForUpdate()
            ->first();
        $selection = KsefOfflineCertificateSelection::query()
            ->where('environment', $snapshot['environment']->value)
            ->lockForUpdate()
            ->first();
        $certificate = $selection === null
            ? null
            : KsefOfflineCertificate::query()
                ->whereKey($selection->offline_certificate_id)
                ->lockForUpdate()
                ->first();

        if ($settings === null
            || $seriesSetting === null
            || $selection === null
            || $certificate === null
            || ! hash_equals($snapshot['invoice_fingerprint'], $this->invoiceFingerprint($invoice))
            || ! hash_equals($snapshot['settings_fingerprint'], $this->modelFingerprint($settings))
            || ! hash_equals($snapshot['series_fingerprint'], $this->modelFingerprint($seriesSetting))
            || ! hash_equals($snapshot['selection_fingerprint'], $this->modelFingerprint($selection))
            || ! hash_equals($snapshot['certificate_fingerprint'], $this->modelFingerprint($certificate))
            || ! hash_equals(
                $snapshot['certificate_material_fingerprint'],
                $this->certificateMaterialFingerprint($certificate),
            )) {
            throw $this->configurationChanged();
        }

        $this->assertInvoiceEligible($invoice);
        $this->assertSettingsActive($settings);
        $this->environments->assertAllowed($settings->environment);
        $context = $this->contextIdentifier($settings->context_nip);
        $sellerNip = $this->sellerNip($invoice);
        $this->assertOwnContext($context, $sellerNip);
        $this->assertSeriesEnabled($seriesSetting);
        $this->assertCertificateReady($certificate, $issuedAt);
        $this->assertNoConflictingHistory($invoice, $settings->environment->value, lock: true);
    }

    private function assertInvoiceEligible(Invoice $invoice): void
    {
        if ($invoice->document_type !== InvoiceDocumentType::Invoice
            || $invoice->status !== InvoiceDocumentStatus::Issued
            || ! $invoice->isFinalized()) {
            throw new KsefApiException(
                'Offline24 można wystawić wyłącznie dla wystawionej i zamkniętej Faktury VAT.',
                'ksef_offline24_document_not_eligible',
            );
        }
    }

    private function assertSettingsActive(?KsefSetting $settings): void
    {
        if ($settings === null || ! $settings->is_active) {
            throw new KsefApiException(
                'Integracja KSeF nie jest aktywna.',
                'ksef_offline24_configuration_inactive',
            );
        }
    }

    private function contextIdentifier(mixed $value): KsefContextIdentifier
    {
        try {
            return KsefContextIdentifier::make(
                KsefContextIdentifierType::Nip,
                is_string($value) ? $value : '',
            );
        } catch (InvalidArgumentException) {
            throw new KsefApiException(
                'Konfiguracja nie zawiera prawidłowego NIP-u kontekstu KSeF.',
                'ksef_offline24_context_missing',
            );
        }
    }

    private function sellerNip(Invoice $invoice): string
    {
        $sellerNip = $this->buyerIdentity->normalizePolishNip(
            data_get($invoice->seller_snapshot, 'tax_id'),
        );

        if ($sellerNip === null) {
            throw new KsefApiException(
                'Snapshot Faktury nie zawiera prawidłowego NIP-u sprzedawcy.',
                'ksef_offline24_seller_identity_missing',
            );
        }

        return $sellerNip;
    }

    private function assertOwnContext(KsefContextIdentifier $context, string $sellerNip): void
    {
        if (! hash_equals($sellerNip, $context->value)) {
            throw new KsefApiException(
                'Offline24 w tej wersji wymaga własnego kontekstu NIP sprzedawcy.',
                'ksef_offline24_delegated_context_not_supported',
            );
        }
    }

    private function assertSeriesEnabled(?KsefSeriesSetting $seriesSetting): void
    {
        if ($seriesSetting === null || ! $seriesSetting->is_enabled) {
            throw new KsefApiException(
                'Seria numeracji Faktury nie jest włączona do KSeF.',
                'ksef_offline24_series_disabled',
            );
        }
    }

    private function selectedCertificate(
        ?KsefOfflineCertificateSelection $selection,
        string $environment,
    ): KsefOfflineCertificate {
        $certificate = $selection?->certificate;

        if ($selection === null || $selection->environment->value !== $environment || $certificate === null) {
            throw new KsefApiException(
                'Nie wybrano certyfikatu Offline dla aktywnego środowiska KSeF.',
                'ksef_offline24_preferred_certificate_missing',
            );
        }

        if ($certificate->environment->value !== $environment) {
            throw new KsefApiException(
                'Wybrany certyfikat Offline należy do innego środowiska KSeF.',
                'ksef_offline24_certificate_environment_mismatch',
            );
        }

        return $certificate;
    }

    private function assertCertificateReady(
        KsefOfflineCertificate $certificate,
        CarbonImmutable $issuedAt,
    ): void {
        if (! $this->certificateReadiness->isReady($certificate, $issuedAt)) {
            throw new KsefApiException(
                'Wybrany certyfikat Offline nie jest gotowy do wystawienia Faktury Offline24.',
                'ksef_offline24_certificate_not_ready',
            );
        }
    }

    private function assertNoConflictingHistory(
        Invoice $invoice,
        string $environment,
        bool $lock = false,
    ): void {
        $issuances = KsefOfflineIssuance::query()
            ->where('invoice_id', $invoice->getKey())
            ->where('environment', $environment);
        $submissions = KsefInvoiceSubmission::query()
            ->where('invoice_id', $invoice->getKey())
            ->where('environment', $environment);
        $outside = KsefInvoiceProvenance::query()
            ->where('invoice_id', $invoice->getKey())
            ->where('environment', $environment)
            ->where('provenance', KsefInvoiceProvenanceType::OutsideKsef->value);

        if ($lock) {
            $issuances->lockForUpdate();
            $submissions->lockForUpdate();
            $outside->lockForUpdate();
        }

        if ($issuances->exists()) {
            throw $this->alreadyIssued();
        }

        if ($submissions->exists()) {
            throw new KsefApiException(
                'Faktura posiada już historię przekazywania do KSeF w aktywnym środowisku.',
                'ksef_offline24_submission_history_exists',
            );
        }

        if ($outside->exists()) {
            throw new KsefApiException(
                'Faktura została oznaczona jako wystawiona poza KSeF w aktywnym środowisku.',
                'ksef_offline24_outside_ksef_provenance_exists',
            );
        }
    }

    private function alreadyIssued(): KsefApiException
    {
        return new KsefApiException(
            'Faktura została już wystawiona w trybie Offline24 w aktywnym środowisku.',
            'ksef_offline24_already_issued',
        );
    }

    private function configurationChanged(): KsefApiException
    {
        return new KsefApiException(
            'Konfiguracja lub Faktura zmieniła się podczas wystawiania Offline24. Spróbuj ponownie.',
            'ksef_offline24_configuration_changed',
        );
    }

    private function invoiceFingerprint(Invoice $invoice): string
    {
        $items = $invoice->items()
            ->orderBy('id')
            ->get()
            ->map(fn (Model $item): array => $this->sortedAttributes($item))
            ->all();

        return $this->fingerprint([
            'invoice' => $this->sortedAttributes($invoice),
            'items' => $items,
        ]);
    }

    private function modelFingerprint(Model $model): string
    {
        return $this->fingerprint($this->sortedAttributes($model));
    }

    /** @return array<string, mixed> */
    private function sortedAttributes(Model $model): array
    {
        $attributes = $model->getRawOriginal();
        ksort($attributes);

        return $attributes;
    }

    /** @param array<string, mixed> $values */
    private function fingerprint(array $values): string
    {
        return hash('sha256', json_encode($values, JSON_THROW_ON_ERROR));
    }

    private function certificateMaterialFingerprint(KsefOfflineCertificate $certificate): string
    {
        return hash('sha256', implode("\0", [
            (string) $certificate->certificate_pem,
            (string) $certificate->private_key_pem,
        ]));
    }
}
