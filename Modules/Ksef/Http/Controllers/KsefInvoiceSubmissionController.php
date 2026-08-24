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
use Modules\Ksef\Services\KsefInvoiceUpoService;
use Modules\Ksef\Services\KsefManualInvoiceSubmissionService;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class KsefInvoiceSubmissionController extends Controller
{
    public function store(
        Invoice $invoice,
        KsefManualInvoiceSubmissionService $manualSubmissions,
    ): RedirectResponse {
        try {
            $manualSubmissions->submit($invoice);

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

    public function reconcile(
        Invoice $invoice,
        KsefInvoiceSubmission $submission,
        KsefInvoiceSubmissionService $submissions,
    ): RedirectResponse {
        abort_unless($submission->invoice_id === $invoice->getKey(), 404);

        try {
            $submissions->reconcile($submission);

            return back()->with('success', 'Wynik transmisji KSeF został sprawdzony.');
        } catch (KsefApiException $exception) {
            return back()->withErrors(['ksef' => $exception->getMessage()]);
        } catch (Throwable $exception) {
            $this->logUnexpected($invoice, 'reconcile', $exception);

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

    public function fetchUpo(
        Invoice $invoice,
        KsefInvoiceSubmission $submission,
        KsefInvoiceUpoService $upos,
    ): RedirectResponse {
        abort_unless($submission->invoice_id === $invoice->getKey(), 404);

        try {
            $upos->fetch($invoice, $submission);

            return back()->with('success', 'UPO zostało pobrane i bezpiecznie zapisane.');
        } catch (KsefApiException $exception) {
            return back()->withErrors(['ksef' => $exception->getMessage()]);
        } catch (Throwable $exception) {
            $this->logUnexpected($invoice, 'fetch_upo', $exception, $submission);

            return back()->withErrors(['ksef' => 'Nie udało się pobrać UPO z KSeF.']);
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
        $xml = $upo->payload_xml;
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
