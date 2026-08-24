<?php

namespace Modules\Ksef\Services;

use Illuminate\Support\Facades\DB;
use Modules\Invoices\Enums\InvoiceDocumentType;
use Modules\Invoices\Exceptions\InvoiceDomainException;
use Modules\Invoices\Models\Invoice;
use Modules\Ksef\Enums\KsefEnvironment;
use Modules\Ksef\Exceptions\KsefApiException;
use Modules\Ksef\Models\KsefSeriesSetting;
use Modules\Ksef\Models\KsefSetting;
use Modules\Ksef\ValueObjects\KsefMonthlyInvoiceExportResult;
use Throwable;

class KsefMonthlyInvoiceExportService
{
    public function __construct(
        private readonly KsefSettingsService $settings,
        private readonly KsefOperationalEnvironmentPolicy $environments,
        private readonly KsefMonthlyExportPeriod $periods,
        private readonly KsefManualInvoiceSubmissionService $manualSubmissions,
    ) {}

    public function export(string $month): KsefMonthlyInvoiceExportResult
    {
        if (! $this->periods->allows($month)) {
            throw new KsefApiException(
                'Wybrany miesiąc jest poza dozwolonym zakresem eksportu.',
                'ksef_monthly_export_period_invalid',
            );
        }

        $environment = $this->snapshotEnvironment();
        [$startDate, $endDate] = $this->periods->dateBounds($month);
        $invoiceIds = $this->eligibleInvoiceIds($environment, $startDate, $endDate);
        $submitted = 0;
        $failed = 0;

        foreach ($invoiceIds as $invoiceId) {
            try {
                $this->manualSubmissions->submitFirstAttempt(
                    Invoice::query()->findOrFail($invoiceId),
                    $environment,
                );
                $submitted++;
            } catch (KsefApiException $exception) {
                if ($exception->safeCode === 'ksef_submission_first_attempt_already_exists') {
                    continue;
                }

                $failed++;

                if ($this->isGlobalFailure($exception)) {
                    return $this->result(
                        $environment,
                        $month,
                        count($invoiceIds),
                        $submitted,
                        $failed,
                        true,
                        $exception->getMessage(),
                    );
                }
            } catch (InvoiceDomainException) {
                $failed++;
            } catch (Throwable) {
                $failed++;

                return $this->result(
                    $environment,
                    $month,
                    count($invoiceIds),
                    $submitted,
                    $failed,
                    true,
                    'Wystąpił nieoczekiwany błąd podczas eksportu.',
                );
            }
        }

        return $this->result(
            $environment,
            $month,
            count($invoiceIds),
            $submitted,
            $failed,
            false,
        );
    }

    private function snapshotEnvironment(): KsefEnvironment
    {
        $this->assertTransportEnabled();
        $this->settings->get();

        return DB::transaction(function (): KsefEnvironment {
            $settings = KsefSetting::query()
                ->where('singleton_key', KsefSetting::SINGLETON_KEY)
                ->lockForUpdate()
                ->firstOrFail();

            if (! $settings->is_active) {
                throw new KsefApiException(
                    'Integracja KSeF nie jest aktywna.',
                    'ksef_submission_configuration_inactive',
                );
            }

            $this->environments->assertAllowed($settings->environment);

            return $settings->environment;
        }, 3);
    }

    /** @return list<int> */
    private function eligibleInvoiceIds(
        KsefEnvironment $environment,
        string $startDate,
        string $endDate,
    ): array {
        return Invoice::query()
            ->where('document_type', InvoiceDocumentType::Invoice->value)
            ->whereNotNull('finalized_at')
            ->whereBetween('issue_date', [$startDate, $endDate])
            ->whereIn(
                'invoice_series_id',
                KsefSeriesSetting::query()
                    ->select('invoice_series_id')
                    ->where('is_enabled', true),
            )
            ->whereDoesntHave(
                'ksefSubmissions',
                fn ($query) => $query->where('environment', $environment->value),
            )
            ->orderBy('issue_date')
            ->orderBy('id')
            ->pluck('id')
            ->map(fn (int|string $id): int => (int) $id)
            ->all();
    }

    private function isGlobalFailure(KsefApiException $exception): bool
    {
        if ($exception->httpStatus !== null
            && (in_array($exception->httpStatus, [401, 403, 410, 429], true)
                || $exception->httpStatus >= 500)) {
            return true;
        }

        return $exception->isCredentialOrContextFailure()
            || in_array($exception->safeCode, [
                'api_token_missing',
                'auth_poll_timeout',
                'auth_response_incomplete',
                'auth_status_malformed',
                'auth_token_already_redeemed',
                'base_url_missing',
                'certificate_material_missing',
                'challenge_timestamp_invalid',
                'configuration_changed',
                'context_nip_missing',
                'ksef_invoice_encryption_failed',
                'ksef_invoice_send_response_incomplete',
                'ksef_submission_environment_changed',
                'ksef_operational_environment_blocked',
                'ksef_public_key_invalid',
                'ksef_public_key_not_rsa',
                'ksef_public_key_unavailable',
                'ksef_session_encryption_failed',
                'ksef_session_response_incomplete',
                'ksef_submission_configuration_inactive',
                'ksef_submission_context_changed',
                'ksef_submission_disabled',
                'ksef_submission_pre_send_failed',
                'malformed_response',
                'network_error',
                'public_key_unavailable',
                'rate_limited',
                'refresh_response_incomplete',
                'refresh_validity_invalid',
                'token_validity_invalid',
                'token_validity_missing',
            ], true);
    }

    private function assertTransportEnabled(): void
    {
        if (config('ksef.invoice_submission_enabled') !== true) {
            throw new KsefApiException(
                'Wysyłka Faktur do KSeF jest wyłączona na poziomie wdrożenia.',
                'ksef_submission_disabled',
            );
        }
    }

    private function result(
        KsefEnvironment $environment,
        string $month,
        int $eligible,
        int $submitted,
        int $failed,
        bool $stoppedEarly,
        ?string $safeFailureSummary = null,
    ): KsefMonthlyInvoiceExportResult {
        return new KsefMonthlyInvoiceExportResult(
            $environment,
            $month,
            $eligible,
            $submitted,
            $failed,
            $stoppedEarly,
            $safeFailureSummary,
        );
    }
}
