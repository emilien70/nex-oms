<?php

namespace Modules\Ksef\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Log;
use Modules\Invoices\Models\Invoice;
use Modules\Ksef\Exceptions\KsefApiException;
use Modules\Ksef\Models\KsefInvoiceSubmission;
use Modules\Ksef\Models\KsefOfflineIssuance;
use Modules\Ksef\Models\KsefOfflineTechnicalCorrection;
use Modules\Ksef\Services\KsefInvoiceStatusFollowUpService;
use Modules\Ksef\Services\KsefOfflineTechnicalCorrectionService;
use Modules\Ksef\Services\KsefOfflineTechnicalCorrectionSubmissionService;
use Modules\Ksef\Services\KsefUserErrorPresenter;
use Throwable;

final class KsefOfflineTechnicalCorrectionController extends Controller
{
    public function __construct(
        private readonly KsefUserErrorPresenter $userErrors,
    ) {}

    public function prepare(
        Invoice $invoice,
        KsefOfflineIssuance $issuance,
        KsefInvoiceSubmission $submission,
        KsefOfflineTechnicalCorrectionService $technicalCorrections,
    ): RedirectResponse {
        abort_unless(
            $issuance->invoice_id === $invoice->getKey()
            && $submission->invoice_id === $invoice->getKey()
            && $submission->offline_issuance_id === $issuance->getKey(),
            404,
        );

        try {
            $technicalCorrections->prepare($invoice, $issuance, $submission);

            return back()->with('success', 'Korekta techniczna KSeF została przygotowana lokalnie.');
        } catch (KsefApiException $exception) {
            return $this->domainError($exception);
        } catch (Throwable $exception) {
            Log::error('Nieoczekiwany błąd przygotowania korekty technicznej KSeF.', [
                'invoice_id' => $invoice->getKey(),
                'issuance_id' => $issuance->getKey(),
                'source_submission_id' => $submission->getKey(),
                'exception_class' => $exception::class,
            ]);

            return back()->withErrors([
                'ksef' => 'Nie udało się bezpiecznie przygotować korekty technicznej KSeF.',
            ]);
        }
    }

    public function submit(
        Invoice $invoice,
        KsefOfflineTechnicalCorrection $technicalCorrection,
        KsefOfflineTechnicalCorrectionSubmissionService $technicalSubmissions,
        KsefInvoiceStatusFollowUpService $statusFollowUp,
    ): RedirectResponse {
        abort_unless($technicalCorrection->invoice_id === $invoice->getKey(), 404);

        try {
            $submission = $technicalSubmissions->submitAttempt($invoice, $technicalCorrection);

            try {
                $statusFollowUp->refresh($invoice, $submission);
            } catch (KsefApiException) {
                // Transmission is complete; scheduled follow-up keeps the submitted state.
            }

            return back()->with(
                'success',
                'Korekta techniczna została przekazana do KSeF '
                    .strtoupper($submission->environment->value).'.',
            );
        } catch (KsefApiException $exception) {
            return $this->domainError($exception);
        } catch (Throwable $exception) {
            Log::error('Nieoczekiwany błąd transmisji korekty technicznej KSeF.', [
                'invoice_id' => $invoice->getKey(),
                'technical_correction_id' => $technicalCorrection->getKey(),
                'exception_class' => $exception::class,
            ]);

            return back()->withErrors([
                'ksef' => 'Nie udało się bezpiecznie przekazać korekty technicznej do KSeF.',
            ]);
        }
    }

    private function domainError(KsefApiException $exception): RedirectResponse
    {
        $error = $this->userErrors->present(
            $exception,
            KsefUserErrorPresenter::OPERATION_SUBMIT_INVOICE,
        );

        return back()
            ->withErrors(['ksef' => $error['message']])
            ->with('ksef_error', $error);
    }
}
