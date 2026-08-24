<?php

namespace Modules\Invoices\Http\Controllers;

use App\Http\Controllers\Controller;
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
use Modules\Ksef\Models\KsefInvoiceSubmission;
use Modules\Ksef\Models\KsefSeriesSetting;
use Modules\Ksef\Models\KsefSetting;
use Modules\Ksef\Services\KsefInvoiceSubmissionLifecyclePolicy;
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
                    ...$this->ksefViewData($invoice, $ksefLifecycle),
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

        return [
            'ksefSettings' => $settings,
            'ksefSubmissions' => $submissions,
            'latestKsefSubmission' => $submissions->first(),
            'currentKsefSubmission' => $currentSubmission,
            'ksefCanCreateAttempt' => $settings !== null
                && $lifecycle->allowsNewAttempt($currentEnvironmentSubmissions),
            'ksefSeriesEnabled' => KsefSeriesSetting::query()
                ->where('invoice_series_id', $invoice->invoice_series_id)
                ->where('is_enabled', true)
                ->exists(),
            'ksefSubmissionGateEnabled' => config('ksef.invoice_submission_enabled') === true,
        ];
    }
}
