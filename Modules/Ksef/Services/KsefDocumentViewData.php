<?php

namespace Modules\Ksef\Services;

use Carbon\CarbonImmutable;
use Modules\Invoices\Models\Invoice;
use Modules\Ksef\Enums\KsefInvoiceProvenanceType;
use Modules\Ksef\Enums\KsefOfflineIssuanceProcedure;
use Modules\Ksef\Exceptions\KsefApiException;
use Modules\Ksef\Models\KsefInvoiceProvenance;
use Modules\Ksef\Models\KsefInvoiceSubmission;
use Modules\Ksef\Models\KsefOfflineCertificateSelection;
use Modules\Ksef\Models\KsefOfflineIssuance;
use Modules\Ksef\Models\KsefSeriesSetting;
use Modules\Ksef\Models\KsefSetting;

final class KsefDocumentViewData
{
    public function __construct(
        private readonly KsefInvoiceSubmissionLifecyclePolicy $lifecycle,
        private readonly KsefOperationalEnvironmentPolicy $environments,
        private readonly KsefOfflineCertificateReadinessService $offlineCertificateReadiness,
        private readonly KsefFa3BuyerIdentityResolver $buyerIdentity,
        private readonly KsefOfflineDeliveryPolicy $offlineDelivery,
        private readonly KsefOfflineProcedureEligibilityService $offlineProcedureEligibility,
    ) {}

    /** @return array<string, mixed> */
    public function make(Invoice $invoice): array
    {
        $lifecycle = $this->lifecycle;
        $environments = $this->environments;
        $offlineCertificateReadiness = $this->offlineCertificateReadiness;
        $buyerIdentity = $this->buyerIdentity;
        $offlineDelivery = $this->offlineDelivery;
        $offlineProcedureEligibility = $this->offlineProcedureEligibility;
        $settings = KsefSetting::query()
            ->where('singleton_key', KsefSetting::SINGLETON_KEY)
            ->first();
        $submissions = $invoice->ksefSubmissions()
            ->with('upo')
            ->orderByDesc('id')
            ->get();
        $currentEnvironmentSubmissions = $settings === null
            ? collect()
            : $submissions->filter(
                fn (KsefInvoiceSubmission $submission): bool => $submission->environment === $settings->environment,
            );
        $currentSubmission = $currentEnvironmentSubmissions->first();
        $offlineIssuances = KsefOfflineIssuance::query()
            ->where('invoice_id', $invoice->getKey())
            ->orderByDesc('id')
            ->get();
        $offlineIssuance = $settings === null
            ? null
            : $offlineIssuances->first(
                fn (KsefOfflineIssuance $issuance): bool => $issuance->environment === $settings->environment,
            );
        $seriesEnabled = KsefSeriesSetting::query()
            ->where('invoice_series_id', $invoice->invoice_series_id)
            ->where('is_enabled', true)
            ->exists();
        $preferredSelection = $settings === null
            ? null
            : KsefOfflineCertificateSelection::query()
                ->with('certificate')
                ->where('environment', $settings->environment->value)
                ->first();
        $preferredCertificate = $preferredSelection?->certificate;
        $preferredCertificateReady = $settings !== null
            && $preferredCertificate !== null
            && $preferredCertificate->environment === $settings->environment
            && $offlineCertificateReadiness->isReady($preferredCertificate);
        $outsideKsef = $settings !== null
            && KsefInvoiceProvenance::query()
                ->where('invoice_id', $invoice->getKey())
                ->where('environment', $settings->environment->value)
                ->where('provenance', KsefInvoiceProvenanceType::OutsideKsef->value)
                ->exists();
        $sellerNip = $buyerIdentity->normalizePolishNip(
            data_get($invoice->seller_snapshot, 'tax_id'),
        );
        $contextMatchesSeller = $settings !== null
            && is_string($settings->context_nip)
            && $sellerNip !== null
            && hash_equals($sellerNip, $settings->context_nip);
        $offlineDeliveryType = null;
        $offlineDeliveryError = null;

        if ($offlineIssuance !== null) {
            try {
                $offlineDeliveryType = $offlineDelivery->primaryDocument($offlineIssuance);
            } catch (KsefApiException $exception) {
                $offlineDeliveryError = $exception->getMessage();
            }
        }

        $offlineIssuanceRows = $offlineIssuances->map(function (KsefOfflineIssuance $issuance) use (
            $submissions,
            $offlineDelivery,
            $environments,
            $settings,
        ): array {
            $deliveryType = null;
            $deliveryError = null;

            try {
                $deliveryType = $offlineDelivery->primaryDocument($issuance);
            } catch (KsefApiException $exception) {
                $deliveryError = $exception->getMessage();
            }

            return [
                'issuance' => $issuance,
                'submission' => $submissions->first(
                    fn (KsefInvoiceSubmission $submission): bool => $submission->offline_issuance_id === $issuance->getKey(),
                ),
                'delivery_type' => $deliveryType,
                'delivery_error' => $deliveryError,
                'environment_allowed' => $environments->allows($issuance->environment),
                'context_current' => $settings !== null
                    && is_string($settings->context_nip)
                    && hash_equals((string) $issuance->context_identifier_value, $settings->context_nip),
            ];
        });
        $issuedAt = CarbonImmutable::now('UTC');
        $canIssueOffline = $settings !== null
            && ($invoice->isInvoice() || $invoice->isCorrection())
            && $invoice->isIssued()
            && ($invoice->isFinalized() || $invoice->isCorrection())
            && $invoice->issue_date?->toDateString() === $issuedAt->setTimezone('Europe/Warsaw')->toDateString()
            && $settings->is_active
            && $environments->allows($settings->environment)
            && $seriesEnabled
            && $offlineIssuance === null
            && $currentEnvironmentSubmissions->isEmpty()
            && ! $outsideKsef
            && $contextMatchesSeller
            && $preferredCertificateReady;
        $plannedEligibility = $settings === null
            ? null
            : $offlineProcedureEligibility->snapshot(
                KsefOfflineIssuanceProcedure::PlannedUnavailability,
                $settings->environment,
                $issuedAt,
            );
        $failureEligibility = $settings === null
            ? null
            : $offlineProcedureEligibility->snapshot(
                KsefOfflineIssuanceProcedure::Failure,
                $settings->environment,
                $issuedAt,
            );

        $correctionBlockReason = $invoice->isCorrection() && $offlineIssuance === null
            ? app(KsefOfflineIssuanceService::class)->correctionBlockReason($invoice, KsefOfflineIssuanceProcedure::Offline24)
            : null;
        $canIssueOffline = $canIssueOffline && $correctionBlockReason === null;

        return [
            'ksefSettings' => $settings,
            'ksefSubmissions' => $submissions,
            'latestKsefSubmission' => $submissions->first(),
            'currentKsefSubmission' => $currentSubmission,
            'currentKsefOfflineIssuance' => $offlineIssuance,
            'ksefOfflineIssuanceRows' => $offlineIssuanceRows,
            'ksefOfflineDeliveryDocumentType' => $offlineDeliveryType,
            'ksefOfflineDeliveryError' => $offlineDeliveryError,
            'ksefCanCreateAttempt' => $settings !== null
                && $offlineIssuance === null
                && $lifecycle->allowsNewAttempt($currentEnvironmentSubmissions),
            'ksefCanIssueOffline24' => $canIssueOffline,
            'ksefOfflineCorrectionBlockReason' => $correctionBlockReason,
            'ksefCanIssuePlannedUnavailability' => $canIssueOffline && $plannedEligibility?->eligible === true,
            'ksefCanIssueFailure' => $canIssueOffline && $failureEligibility?->eligible === true,
            'ksefOfflineProcedureActionsVisible' => $canIssueOffline || ($invoice->isCorrection() && $offlineIssuance === null),
            'ksefPlannedUnavailabilityEligibility' => $plannedEligibility,
            'ksefFailureEligibility' => $failureEligibility,
            'ksefSeriesEnabled' => $seriesEnabled,
            'ksefSubmissionGateEnabled' => config('ksef.invoice_submission_enabled') === true,
            'ksefOperationalEnvironmentAllowed' => $settings !== null
                && $environments->allows($settings->environment),
        ];
    }
}
