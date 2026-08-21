<?php

namespace Modules\Ksef\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Log;
use Modules\Invoices\Exceptions\InvoiceDomainException;
use Modules\Invoices\Models\Invoice;
use Modules\Ksef\Exceptions\KsefApiException;
use Modules\Ksef\Models\KsefInvoiceSubmission;
use Modules\Ksef\Services\KsefInvoiceSubmissionService;
use Modules\Ksef\Services\KsefManualInvoiceSubmissionService;
use Throwable;

class KsefInvoiceSubmissionController extends Controller
{
    public function store(
        Invoice $invoice,
        KsefManualInvoiceSubmissionService $manualSubmissions,
    ): RedirectResponse {
        try {
            $manualSubmissions->submitFirst($invoice);

            return back()->with(
                'success',
                'Faktura została przekazana do KSeF TEST. Sprawdź status, aby potwierdzić przyjęcie.',
            );
        } catch (KsefApiException|InvoiceDomainException $exception) {
            return back()->withErrors(['ksef' => $exception->getMessage()]);
        } catch (Throwable $exception) {
            $this->logUnexpected($invoice, 'send', $exception);

            return back()->withErrors(['ksef' => 'Nie udało się wykonać operacji KSeF.']);
        }
    }

    public function refresh(
        Invoice $invoice,
        KsefInvoiceSubmission $submission,
        KsefInvoiceSubmissionService $submissions,
    ): RedirectResponse {
        abort_unless($submission->invoice_id === $invoice->getKey(), 404);

        try {
            $submissions->refreshStatus($submission);

            return back()->with('success', 'Status KSeF został odświeżony.');
        } catch (KsefApiException $exception) {
            return back()->withErrors(['ksef' => $exception->getMessage()]);
        } catch (Throwable $exception) {
            $this->logUnexpected($invoice, 'refresh', $exception);

            return back()->withErrors(['ksef' => 'Nie udało się wykonać operacji KSeF.']);
        }
    }

    private function logUnexpected(Invoice $invoice, string $operation, Throwable $exception): void
    {
        Log::error('Nieoczekiwany błąd manualnej operacji KSeF.', [
            'invoice_id' => $invoice->getKey(),
            'operation' => $operation,
            'exception_class' => $exception::class,
        ]);
    }
}
