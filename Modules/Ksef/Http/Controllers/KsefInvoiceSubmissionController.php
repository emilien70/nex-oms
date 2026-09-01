<?php

namespace Modules\Ksef\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Modules\Invoices\Exceptions\InvoiceDomainException;
use Modules\Invoices\Models\Invoice;
use Modules\Invoices\Support\InvoiceReturnContext;
use Modules\Ksef\Exceptions\KsefApiException;
use Modules\Ksef\Models\KsefInvoiceSubmission;
use Modules\Ksef\Services\KsefInvoiceSourceService;
use Modules\Ksef\Services\KsefInvoiceStatusFollowUpService;
use Modules\Ksef\Services\KsefInvoiceUpoService;
use Modules\Ksef\Services\KsefManualCorrectionSubmissionService;
use Modules\Ksef\Services\KsefManualInvoiceSubmissionService;
use Modules\Ksef\Services\KsefSettingsService;
use Modules\Ksef\Services\KsefUserErrorPresenter;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class KsefInvoiceSubmissionController extends Controller
{
    public function __construct(
        private readonly KsefUserErrorPresenter $userErrors,
    ) {}

    public function firstAttempt(
        Request $request,
        Invoice $invoice,
        KsefManualInvoiceSubmissionService $manualInvoices,
        KsefManualCorrectionSubmissionService $manualCorrections,
        KsefSettingsService $settings,
        KsefInvoiceStatusFollowUpService $statusFollowUp,
    ): RedirectResponse {
        $returnContext = InvoiceReturnContext::fromRequest(
            $request,
            $this->defaultReturnContext($invoice),
        );
        $redirectUrl = $returnContext->url((int) ($invoice->order_id ?? 0));
        $operation = $this->submitOperation($invoice);

        try {
            $settingsSnapshot = $settings->getExisting();
            $submission = match (true) {
                $invoice->isInvoice() => $manualInvoices->submitFirstAttempt(
                    $invoice,
                    $settingsSnapshot->environment,
                    $settingsSnapshot->context_nip,
                ),
                $invoice->isCorrection() => $manualCorrections->submitFirstAttempt(
                    $invoice,
                    $settingsSnapshot->environment,
                    $settingsSnapshot->context_nip,
                ),
                default => throw $this->unsupportedDocument(),
            };
            $this->refreshStatusOnce($invoice, $submission, $statusFollowUp);

            return redirect($redirectUrl);
        } catch (KsefApiException|InvoiceDomainException $exception) {
            return $this->errorRedirect(
                redirect($redirectUrl),
                $exception,
                $operation,
            );
        } catch (Throwable $exception) {
            $this->logUnexpected($invoice, 'first_attempt', $exception);

            return $this->errorRedirect(
                redirect($redirectUrl),
                $exception,
                $operation,
            );
        }
    }

    public function store(
        Request $request,
        Invoice $invoice,
        KsefManualInvoiceSubmissionService $manualInvoices,
        KsefManualCorrectionSubmissionService $manualCorrections,
        KsefSettingsService $settings,
        KsefInvoiceStatusFollowUpService $statusFollowUp,
    ): RedirectResponse {
        $returnContext = InvoiceReturnContext::fromRequest(
            $request,
            $this->defaultReturnContext($invoice),
        );
        $redirectUrl = $returnContext->url((int) ($invoice->order_id ?? 0));
        $operation = $this->submitOperation($invoice);
        $response = $invoice->isCorrection() ? redirect($redirectUrl) : back();

        try {
            $settingsSnapshot = $settings->getExisting();
            $submission = match (true) {
                $invoice->isInvoice() => $manualInvoices->submit($invoice),
                $invoice->isCorrection() => $manualCorrections->submit(
                    $invoice,
                    $settingsSnapshot->environment,
                    $settingsSnapshot->context_nip,
                ),
                default => throw $this->unsupportedDocument(),
            };
            $this->refreshStatusOnce($invoice, $submission, $statusFollowUp);
            $environment = strtoupper($submission->environment->value);
            $documentName = $invoice->isCorrection() ? 'Korekta' : 'Faktura';

            return $response->with(
                'success',
                "{$documentName} została przekazana do KSeF {$environment}. Sprawdź status, aby potwierdzić przyjęcie.",
            );
        } catch (KsefApiException|InvoiceDomainException $exception) {
            return $this->errorRedirect(
                $response,
                $exception,
                $operation,
            );
        } catch (Throwable $exception) {
            $this->logUnexpected($invoice, 'send', $exception);

            return $this->errorRedirect(
                $response,
                $exception,
                $operation,
            );
        }
    }

    public function reconcile(
        Invoice $invoice,
        KsefInvoiceSubmission $submission,
        KsefInvoiceStatusFollowUpService $statusFollowUp,
    ): RedirectResponse {
        abort_unless($submission->invoice_id === $invoice->getKey(), 404);

        try {
            $statusFollowUp->reconcile($invoice, $submission);

            return back()->with('success', 'Wynik transmisji KSeF został sprawdzony.');
        } catch (KsefApiException $exception) {
            return $this->errorRedirect(
                back(),
                $exception,
                KsefUserErrorPresenter::OPERATION_RECONCILE,
            );
        } catch (Throwable $exception) {
            $this->logUnexpected($invoice, 'reconcile', $exception);

            return $this->errorRedirect(
                back(),
                $exception,
                KsefUserErrorPresenter::OPERATION_RECONCILE,
            );
        }
    }

    public function refresh(
        Request $request,
        Invoice $invoice,
        KsefInvoiceSubmission $submission,
        KsefInvoiceStatusFollowUpService $statusFollowUp,
    ): RedirectResponse {
        abort_unless($submission->invoice_id === $invoice->getKey(), 404);

        try {
            $statusFollowUp->refresh($invoice, $submission);

            if ($request->input('return_to') === InvoiceReturnContext::INVOICES) {
                return back();
            }

            return back()->with('success', 'Status KSeF został odświeżony.');
        } catch (KsefApiException $exception) {
            return $this->errorRedirect(
                back(),
                $exception,
                KsefUserErrorPresenter::OPERATION_REFRESH,
            );
        } catch (Throwable $exception) {
            $this->logUnexpected($invoice, 'refresh', $exception);

            return $this->errorRedirect(
                back(),
                $exception,
                KsefUserErrorPresenter::OPERATION_REFRESH,
            );
        }
    }

    public function fetchUpo(
        Request $request,
        Invoice $invoice,
        KsefInvoiceSubmission $submission,
        KsefInvoiceStatusFollowUpService $statusFollowUp,
    ): JsonResponse|RedirectResponse|StreamedResponse {
        abort_unless($submission->invoice_id === $invoice->getKey(), 404);

        try {
            $upo = $statusFollowUp->fetchUpo($invoice, $submission);

            if ($request->boolean('download')) {
                return $this->upoDownloadResponse($submission, $upo->payload_xml);
            }

            return back()->with('success', 'UPO zostało pobrane i bezpiecznie zapisane.');
        } catch (KsefApiException $exception) {
            if ($request->ajax()) {
                return response()->json(['message' => $exception->getMessage()], 422);
            }

            return $this->errorRedirect(
                back(),
                $exception,
                KsefUserErrorPresenter::OPERATION_FETCH_UPO,
            );
        } catch (Throwable $exception) {
            $this->logUnexpected($invoice, 'fetch_upo', $exception, $submission);

            if ($request->ajax()) {
                return response()->json(['message' => 'Nie udało się pobrać UPO z KSeF.'], 500);
            }

            return $this->errorRedirect(
                back(),
                $exception,
                KsefUserErrorPresenter::OPERATION_FETCH_UPO,
            );
        }
    }

    public function downloadUpo(
        Invoice $invoice,
        KsefInvoiceSubmission $submission,
        KsefInvoiceUpoService $upos,
    ): StreamedResponse {
        abort_unless($submission->invoice_id === $invoice->getKey(), 404);

        $upo = $upos->stored($invoice, $submission);
        abort_if($upo === null, 404);

        return $this->upoDownloadResponse($submission, $upo->payload_xml);
    }

    public function downloadInvoiceSource(
        Invoice $invoice,
        KsefInvoiceSubmission $submission,
        KsefInvoiceSourceService $invoices,
    ): Response|JsonResponse {
        abort_unless($submission->invoice_id === $invoice->getKey(), 404);

        try {
            $source = $invoices->fetch($invoice, $submission);

            return response($source->body, 200, [
                'Cache-Control' => 'no-store, private',
                'Content-Type' => 'application/xml; charset=UTF-8',
                'X-Content-Type-Options' => 'nosniff',
            ]);
        } catch (KsefApiException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        } catch (Throwable $exception) {
            $this->logUnexpected($invoice, 'download_invoice_source', $exception, $submission);

            return response()->json([
                'message' => 'Nie udało się pobrać Faktury z KSeF.',
            ], 500);
        }
    }

    private function upoDownloadResponse(
        KsefInvoiceSubmission $submission,
        string $xml,
    ): StreamedResponse {
        $number = preg_replace('/[^A-Za-z0-9_-]+/', '_', (string) $submission->ksef_number);
        $filename = 'UPO_'.trim((string) $number, '_').'.xml';

        return response()->streamDownload(
            static function () use ($xml): void {
                echo $xml;
            },
            $filename,
            ['Content-Type' => 'application/xml'],
        );
    }

    private function refreshStatusOnce(
        Invoice $invoice,
        KsefInvoiceSubmission $submission,
        KsefInvoiceStatusFollowUpService $statusFollowUp,
    ): void {
        try {
            $statusFollowUp->refresh($invoice, $submission);
        } catch (KsefApiException) {
            // The invoice was already sent; status and UPO follow-up preserve their completed state.
        } catch (Throwable $exception) {
            $this->logUnexpected($invoice, 'post_send_status', $exception, $submission);
        }
    }

    private function defaultReturnContext(Invoice $document): string
    {
        return $document->isCorrection()
            ? InvoiceReturnContext::CORRECTIONS
            : InvoiceReturnContext::INVOICES;
    }

    private function submitOperation(Invoice $document): string
    {
        return $document->isCorrection()
            ? KsefUserErrorPresenter::OPERATION_SUBMIT_CORRECTION
            : KsefUserErrorPresenter::OPERATION_SUBMIT_INVOICE;
    }

    private function unsupportedDocument(): KsefApiException
    {
        return new KsefApiException(
            'Do KSeF można przekazać wyłącznie Fakturę VAT albo Korektę.',
            'ksef_submission_document_type_invalid',
        );
    }

    private function errorRedirect(
        RedirectResponse $response,
        Throwable $exception,
        string $operation,
    ): RedirectResponse {
        $error = $this->userErrors->present($exception, $operation);

        return $response
            ->withErrors(['ksef' => $error['message']])
            ->with('ksef_error', $error);
    }

    private function logUnexpected(
        Invoice $invoice,
        string $operation,
        Throwable $exception,
        ?KsefInvoiceSubmission $submission = null,
    ): void {
        Log::error('Nieoczekiwany błąd manualnej operacji KSeF.', [
            'invoice_id' => $invoice->getKey(),
            'submission_id' => $submission?->getKey(),
            'operation' => $operation,
            'exception_class' => $exception::class,
        ]);
    }
}
