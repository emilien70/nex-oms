<?php

namespace Modules\Invoices\Http\Controllers;

use App\Http\Controllers\Controller;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Modules\Invoices\Exceptions\InvoiceDomainException;
use Modules\Invoices\Http\Requests\UpdateInvoiceBuyerRequest;
use Modules\Invoices\Http\Requests\UpdateInvoiceDetailsRequest;
use Modules\Invoices\Http\Requests\UpdateInvoiceRecipientRequest;
use Modules\Invoices\Models\Invoice;
use Modules\Invoices\Services\CorrectionSeriesResolver;
use Modules\Invoices\Services\CorrectionSourceStateService;
use Modules\Invoices\Services\InvoiceEditabilityPolicy;
use Modules\Invoices\Services\InvoiceEditAjaxResponder;
use Modules\Invoices\Services\InvoiceEditCurrencyConversionService;
use Modules\Invoices\Services\InvoiceEditService;
use Modules\Invoices\Services\InvoiceEditViewModelFactory;
use Modules\Invoices\Support\InvoiceReturnContext;
use Modules\Ksef\Enums\KsefInvoiceProvenanceType;
use Modules\Ksef\Exceptions\KsefApiException;
use Modules\Ksef\Models\KsefInvoiceProvenance;
use Modules\Ksef\Models\KsefInvoiceSubmission;
use Modules\Ksef\Models\KsefOfflineCertificateSelection;
use Modules\Ksef\Models\KsefOfflineIssuance;
use Modules\Ksef\Models\KsefSeriesSetting;
use Modules\Ksef\Models\KsefSetting;
use Modules\Ksef\Services\KsefFa3BuyerIdentityResolver;
use Modules\Ksef\Services\KsefInvoiceSubmissionLifecyclePolicy;
use Modules\Ksef\Services\KsefOfflineCertificateReadinessService;
use Modules\Ksef\Services\KsefOfflineDeliveryPolicy;
use Modules\Ksef\Services\KsefOperationalEnvironmentPolicy;
use Throwable;

class InvoiceEditController extends Controller
{
    public function edit(
        Request $request,
        Invoice $invoice,
        InvoiceEditabilityPolicy $policy,
        InvoiceEditCurrencyConversionService $currency,
        InvoiceEditViewModelFactory $viewModels,
        CorrectionSourceStateService $sourceState,
        CorrectionSeriesResolver $correctionSeries,
        KsefInvoiceSubmissionLifecyclePolicy $ksefLifecycle,
        KsefOperationalEnvironmentPolicy $ksefEnvironments,
        KsefOfflineCertificateReadinessService $offlineCertificateReadiness,
        KsefFa3BuyerIdentityResolver $buyerIdentity,
        KsefOfflineDeliveryPolicy $offlineDelivery,
    ): View {
        $returnContext = InvoiceReturnContext::fromRequest($request);

        try {
            $policy->assertEditable($invoice);
            $currency->assertSnapshotUsableForAnyEdit($invoice);
        } catch (InvoiceDomainException $exception) {
            if (in_array($exception->errorCode(), [
                'invoice_edit_blocked_by_correction',
                'invoice_finalized',
            ], true)) {
                $chain = $sourceState->chain($invoice);

                return view('invoices.edit-blocked-by-correction', [
                    ...$this->ksefViewData(
                        $invoice,
                        $ksefLifecycle,
                        $ksefEnvironments,
                        $offlineCertificateReadiness,
                        $buyerIdentity,
                        $offlineDelivery,
                    ),
                    'invoice' => $invoice,
                    'currentCorrection' => $chain->currentCorrection,
                    'latestFinalizedCorrection' => $chain->finalizedTail,
                    'correctionSeries' => $correctionSeries->active(),
                    'returnContext' => $returnContext,
                ]);
            }

            abort(422, $exception->getMessage());
        }

        return view('invoices.edit', [
            ...$viewModels->make($invoice),
            'returnContext' => $returnContext,
        ]);
    }

    public function updateBuyer(UpdateInvoiceBuyerRequest $request, Invoice $invoice, InvoiceEditService $service, InvoiceEditAjaxResponder $responder): JsonResponse
    {
        return $this->respond($invoice, $request->integer('expected_lock_version'), ['buyer'], fn () => $service->updateBuyer($invoice, $request->validated()), $responder);
    }

    public function updateRecipient(UpdateInvoiceRecipientRequest $request, Invoice $invoice, InvoiceEditService $service, InvoiceEditAjaxResponder $responder): JsonResponse
    {
        return $this->respond($invoice, $request->integer('expected_lock_version'), ['recipient'], fn () => $service->updateRecipient($invoice, $request->validated()), $responder);
    }

    public function updateDetails(UpdateInvoiceDetailsRequest $request, Invoice $invoice, InvoiceEditService $service, InvoiceEditAjaxResponder $responder): JsonResponse
    {
        return $this->respond($invoice, $request->integer('expected_lock_version'), ['details', 'totals', 'nbp-summary'], fn () => $service->updateDetails($invoice, $request->validated()), $responder);
    }

    /** @param list<string> $fragments */
    private function respond(Invoice $invoice, int $expectedLockVersion, array $fragments, callable $operation, InvoiceEditAjaxResponder $responder): JsonResponse
    {
        try {
            return $responder->success($operation(), $fragments, $expectedLockVersion);
        } catch (InvoiceDomainException $exception) {
            return $responder->domainError($exception);
        } catch (Throwable $exception) {
            return $responder->unexpected($exception, $invoice);
        }
    }

    /** @return array<string, mixed> */
    private function ksefViewData(
        Invoice $invoice,
        KsefInvoiceSubmissionLifecyclePolicy $lifecycle,
        KsefOperationalEnvironmentPolicy $environments,
        KsefOfflineCertificateReadinessService $offlineCertificateReadiness,
        KsefFa3BuyerIdentityResolver $buyerIdentity,
        KsefOfflineDeliveryPolicy $offlineDelivery,
    ): array {
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
            'ksefCanIssueOffline24' => $settings !== null
                && $invoice->isInvoice()
                && $invoice->isIssued()
                && $invoice->isFinalized()
                && $invoice->issue_date?->toDateString() === CarbonImmutable::now('Europe/Warsaw')->toDateString()
                && $settings->is_active
                && $environments->allows($settings->environment)
                && $seriesEnabled
                && $offlineIssuance === null
                && $currentEnvironmentSubmissions->isEmpty()
                && ! $outsideKsef
                && $contextMatchesSeller
                && $preferredCertificateReady,
            'ksefSeriesEnabled' => $seriesEnabled,
            'ksefSubmissionGateEnabled' => config('ksef.invoice_submission_enabled') === true,
            'ksefOperationalEnvironmentAllowed' => $settings !== null
                && $environments->allows($settings->environment),
        ];
    }
}
