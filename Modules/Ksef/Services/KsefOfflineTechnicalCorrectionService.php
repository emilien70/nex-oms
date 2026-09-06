<?php

namespace Modules\Ksef\Services;

use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Modules\Invoices\Models\Invoice;
use Modules\Ksef\Enums\KsefFa3EligibilityMode;
use Modules\Ksef\Exceptions\KsefApiException;
use Modules\Ksef\Models\KsefInvoiceSubmission;
use Modules\Ksef\Models\KsefOfflineIssuance;
use Modules\Ksef\Models\KsefOfflineTechnicalCorrection;
use Modules\Ksef\Models\KsefSetting;
use Modules\Ksef\Services\Fa3\KsefFa3DocumentGenerator;

final class KsefOfflineTechnicalCorrectionService
{
    public function __construct(
        private readonly KsefFa3DocumentGenerator $generator,
        private readonly KsefOfflineTechnicalCorrectionIntegrityService $integrity,
        private readonly KsefOperationalEnvironmentPolicy $environments,
    ) {}

    public function prepare(
        Invoice $invoice,
        KsefOfflineIssuance $issuance,
        KsefInvoiceSubmission $rejectedSubmission,
    ): KsefOfflineTechnicalCorrection {
        $this->assertTransportEnabled();

        try {
            return DB::transaction(function () use ($invoice, $issuance, $rejectedSubmission): KsefOfflineTechnicalCorrection {
                $managedInvoice = Invoice::query()->lockForUpdate()->findOrFail($invoice->getKey());
                $managedIssuance = KsefOfflineIssuance::query()->lockForUpdate()->findOrFail($issuance->getKey());
                $managedSource = KsefInvoiceSubmission::query()->lockForUpdate()->findOrFail($rejectedSubmission->getKey());

                KsefOfflineTechnicalCorrection::query()
                    ->where('rejected_submission_id', $managedSource->getKey())
                    ->lockForUpdate()
                    ->get(['id']);
                $this->integrity->assertSource($managedInvoice, $managedIssuance, $managedSource);
                $this->integrity->assertNoAcceptedSibling($managedInvoice, $managedIssuance, lock: true);
                $this->assertCurrentConfiguration($managedIssuance);
                $this->environments->assertAllowed($managedIssuance->environment);

                if (KsefOfflineTechnicalCorrection::query()
                    ->where('offline_issuance_id', $managedIssuance->getKey())
                    ->exists()) {
                    throw $this->alreadyPrepared();
                }

                $generatedAt = CarbonImmutable::now('UTC');
                $generated = $this->generator->generate(
                    $managedInvoice,
                    $generatedAt,
                    KsefFa3EligibilityMode::Authoritative,
                );
                $hash = $this->hash($generated->xml);

                if (hash_equals($hash, (string) $managedIssuance->invoice_hash)) {
                    throw new KsefApiException(
                        'Ponownie wygenerowany dokument nie różni się od odrzuconego payloadu.',
                        'ksef_technical_correction_payload_unchanged',
                    );
                }

                $artifact = KsefOfflineTechnicalCorrection::query()->create([
                    'invoice_id' => $managedInvoice->getKey(),
                    'offline_issuance_id' => $managedIssuance->getKey(),
                    'rejected_submission_id' => $managedSource->getKey(),
                    'environment' => $managedIssuance->environment,
                    'context_nip' => $managedIssuance->context_identifier_value,
                    'seller_nip' => $managedIssuance->seller_nip,
                    'schema_id' => $generated->schemaId,
                    'generated_at' => CarbonImmutable::parse($generated->generatedAt)->utc(),
                    'payload_xml' => $generated->xml,
                    'invoice_hash' => $hash,
                    'invoice_size' => strlen($generated->xml),
                    'hash_of_corrected_invoice' => $managedIssuance->invoice_hash,
                ]);

                $this->integrity->assertArtifact(
                    $artifact,
                    $managedInvoice,
                    $managedIssuance,
                    $managedSource,
                );

                return $artifact;
            }, 3);
        } catch (QueryException $exception) {
            if (KsefOfflineTechnicalCorrection::query()
                ->where('offline_issuance_id', $issuance->getKey())
                ->exists()) {
                throw $this->alreadyPrepared();
            }

            throw $exception;
        }
    }

    private function assertCurrentConfiguration(KsefOfflineIssuance $issuance): void
    {
        $settings = KsefSetting::query()
            ->where('singleton_key', KsefSetting::SINGLETON_KEY)
            ->lockForUpdate()
            ->first();

        if ($settings === null || ! $settings->is_active) {
            throw new KsefApiException(
                'Integracja KSeF nie jest aktywna.',
                'ksef_submission_configuration_inactive',
            );
        }

        if ($settings->environment !== $issuance->environment
            || ! is_string($settings->context_nip)
            || ! hash_equals((string) $issuance->context_identifier_value, $settings->context_nip)) {
            throw new KsefApiException(
                'Aktywne środowisko i kontekst NIP muszą odpowiadać źródłowej Fakturze Offline.',
                'ksef_technical_correction_configuration_changed',
            );
        }
    }

    private function alreadyPrepared(): KsefApiException
    {
        return new KsefApiException(
            'Korekta techniczna dla tej odrzuconej Faktury Offline została już przygotowana.',
            'ksef_technical_correction_already_prepared',
        );
    }

    private function assertTransportEnabled(): void
    {
        if (config('ksef.invoice_submission_enabled') !== true) {
            throw new KsefApiException(
                'Transport Faktur do KSeF jest wyłączony w konfiguracji wdrożenia.',
                'ksef_submission_disabled',
            );
        }
    }

    private function hash(string $payload): string
    {
        return base64_encode(hash('sha256', $payload, true));
    }
}
