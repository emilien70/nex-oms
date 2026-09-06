<?php

namespace Modules\Ksef\Services;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Modules\Invoices\Models\Invoice;
use Modules\Ksef\Enums\KsefInvoiceSubmissionStatus;
use Modules\Ksef\Exceptions\KsefApiException;
use Modules\Ksef\Models\KsefInvoiceSubmission;
use Modules\Ksef\Models\KsefOfflineIssuance;
use Modules\Ksef\Models\KsefOfflineTechnicalCorrection;
use Modules\Ksef\Models\KsefSetting;

final class KsefOfflineTechnicalCorrectionSubmissionService
{
    public function __construct(
        private readonly KsefInvoiceSubmissionService $submissions,
        private readonly KsefOfflineTechnicalCorrectionIntegrityService $integrity,
        private readonly KsefOperationalEnvironmentPolicy $environments,
    ) {}

    public function submitAttempt(
        Invoice $invoice,
        KsefOfflineTechnicalCorrection $artifact,
    ): KsefInvoiceSubmission {
        $submission = $this->prepare($invoice, $artifact);

        return $this->submissions->submitTechnicalCorrection($submission);
    }

    public function prepare(
        Invoice $invoice,
        KsefOfflineTechnicalCorrection $artifact,
    ): KsefInvoiceSubmission {
        $this->assertTransportEnabled();

        try {
            return DB::transaction(function () use ($invoice, $artifact): KsefInvoiceSubmission {
                $managedInvoice = Invoice::query()->lockForUpdate()->findOrFail($invoice->getKey());
                $managedArtifact = KsefOfflineTechnicalCorrection::query()
                    ->lockForUpdate()
                    ->findOrFail($artifact->getKey());
                $issuance = KsefOfflineIssuance::query()
                    ->lockForUpdate()
                    ->findOrFail($managedArtifact->offline_issuance_id);
                $source = KsefInvoiceSubmission::query()
                    ->lockForUpdate()
                    ->findOrFail($managedArtifact->rejected_submission_id);

                $this->integrity->assertArtifact($managedArtifact, $managedInvoice, $issuance, $source);
                $this->integrity->assertNoAcceptedSibling($managedInvoice, $issuance, lock: true);
                $this->assertCurrentConfiguration($issuance);
                $this->environments->assertAllowed($managedArtifact->environment);

                if (KsefInvoiceSubmission::query()
                    ->where('offline_technical_correction_id', $managedArtifact->getKey())
                    ->lockForUpdate()
                    ->exists()) {
                    throw $this->attemptBlocked();
                }

                $attemptNumber = ((int) KsefInvoiceSubmission::query()
                    ->where('invoice_id', $managedInvoice->getKey())
                    ->where('environment', $managedArtifact->environment->value)
                    ->max('attempt_number')) + 1;

                return KsefInvoiceSubmission::query()->create([
                    'invoice_id' => $managedArtifact->invoice_id,
                    'offline_issuance_id' => $managedArtifact->offline_issuance_id,
                    'offline_technical_correction_id' => $managedArtifact->getKey(),
                    'environment' => $managedArtifact->environment,
                    'context_nip' => $managedArtifact->context_nip,
                    'seller_nip' => $managedArtifact->seller_nip,
                    'attempt_number' => $attemptNumber,
                    'status' => KsefInvoiceSubmissionStatus::Preparing,
                    'schema_id' => $managedArtifact->schema_id,
                    'generated_at' => $managedArtifact->generated_at,
                    'payload_xml' => $managedArtifact->payload_xml,
                    'invoice_hash' => $managedArtifact->invoice_hash,
                    'invoice_size' => $managedArtifact->invoice_size,
                ]);
            }, 3);
        } catch (QueryException $exception) {
            if (KsefInvoiceSubmission::query()
                ->where('offline_technical_correction_id', $artifact->getKey())
                ->exists()) {
                throw $this->attemptBlocked();
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

    private function attemptBlocked(): KsefApiException
    {
        return new KsefApiException(
            'Korekta techniczna posiada już próbę transmisji. Jej wyniku nie wolno zastępować ponownym POST-em.',
            'ksef_technical_correction_submission_attempt_blocked',
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
}
