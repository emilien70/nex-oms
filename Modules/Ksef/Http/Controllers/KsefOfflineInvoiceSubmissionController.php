<?php

namespace Modules\Ksef\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Log;
use Modules\Invoices\Models\Invoice;
use Modules\Ksef\Exceptions\KsefApiException;
use Modules\Ksef\Models\KsefOfflineIssuance;
use Modules\Ksef\Services\KsefInvoiceStatusFollowUpService;
use Modules\Ksef\Services\KsefOfflineInvoiceSubmissionService;
use Modules\Ksef\Services\KsefUserErrorPresenter;
use Throwable;

final class KsefOfflineInvoiceSubmissionController extends Controller
{
    public function __construct(
        private readonly KsefUserErrorPresenter $userErrors,
    ) {}

    public function __invoke(
        Invoice $invoice,
        KsefOfflineIssuance $issuance,
        KsefOfflineInvoiceSubmissionService $offlineSubmissions,
        KsefInvoiceStatusFollowUpService $statusFollowUp,
    ): RedirectResponse {
        abort_unless($issuance->invoice_id === $invoice->getKey(), 404);

        try {
            $submission = $offlineSubmissions->submitAttempt($invoice, $issuance);

            try {
                $statusFollowUp->refresh($invoice, $submission);
            } catch (KsefApiException) {
                // Transmission is complete; scheduled follow-up keeps the submitted state.
            }

            return back()->with(
                'success',
                'Zamrożona Faktura Offline została przekazana do KSeF '
                    .strtoupper($submission->environment->value).'.',
            );
        } catch (KsefApiException $exception) {
            $error = $this->userErrors->present(
                $exception,
                KsefUserErrorPresenter::OPERATION_SUBMIT_INVOICE,
            );

            return back()
                ->withErrors(['ksef' => $error['message']])
                ->with('ksef_error', $error);
        } catch (Throwable $exception) {
            Log::error('Nieoczekiwany błąd transmisji Faktury Offline.', [
                'invoice_id' => $invoice->getKey(),
                'issuance_id' => $issuance->getKey(),
                'exception_class' => $exception::class,
            ]);

            return back()->withErrors([
                'ksef' => 'Nie udało się bezpiecznie przekazać Faktury Offline do KSeF.',
            ]);
        }
    }
}
