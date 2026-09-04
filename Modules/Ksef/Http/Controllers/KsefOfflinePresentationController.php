<?php

namespace Modules\Ksef\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Modules\Invoices\Models\Invoice;
use Modules\Ksef\Exceptions\KsefApiException;
use Modules\Ksef\Models\KsefInvoiceSubmission;
use Modules\Ksef\Models\KsefOfflineIssuance;
use Modules\Ksef\Services\KsefAcceptedOfflineInvoicePdfService;
use Modules\Ksef\Services\KsefOfflineInvoicePdfService;
use Modules\Ksef\Services\KsefTransactionConfirmationPdfService;
use Throwable;

final class KsefOfflinePresentationController extends Controller
{
    public function invoice(
        Invoice $invoice,
        KsefOfflineIssuance $issuance,
        KsefOfflineInvoicePdfService $pdfs,
    ): Response {
        $this->assertOwnership($invoice, $issuance);

        try {
            return $this->pdfResponse($pdfs->document($issuance));
        } catch (KsefApiException $exception) {
            return $this->errorResponse($exception);
        } catch (Throwable $exception) {
            return $this->unexpectedErrorResponse($invoice, $issuance, $exception);
        }
    }

    public function transactionConfirmation(
        Invoice $invoice,
        KsefOfflineIssuance $issuance,
        KsefTransactionConfirmationPdfService $pdfs,
    ): Response {
        $this->assertOwnership($invoice, $issuance);

        try {
            return $this->pdfResponse($pdfs->document($issuance));
        } catch (KsefApiException $exception) {
            return $this->errorResponse($exception);
        } catch (Throwable $exception) {
            return $this->unexpectedErrorResponse($invoice, $issuance, $exception);
        }
    }

    public function acceptedInvoice(
        Invoice $invoice,
        KsefOfflineIssuance $issuance,
        KsefInvoiceSubmission $submission,
        KsefAcceptedOfflineInvoicePdfService $pdfs,
    ): Response {
        $this->assertOwnership($invoice, $issuance);
        abort_unless($submission->invoice_id === $invoice->getKey(), 404);
        abort_unless($submission->offline_issuance_id === $issuance->getKey(), 404);

        try {
            return $this->pdfResponse($pdfs->document($invoice, $issuance, $submission));
        } catch (KsefApiException $exception) {
            return $this->errorResponse($exception);
        } catch (Throwable $exception) {
            return $this->unexpectedErrorResponse($invoice, $issuance, $exception);
        }
    }

    private function assertOwnership(Invoice $invoice, KsefOfflineIssuance $issuance): void
    {
        abort_unless($issuance->invoice_id === $invoice->getKey(), 404);
    }

    /** @param array{contents: string, filename: string} $document */
    private function pdfResponse(array $document): Response
    {
        return response($document['contents'], 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$document['filename'].'"',
            'Cache-Control' => 'private, no-store, max-age=0',
            'Pragma' => 'no-cache',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function errorResponse(KsefApiException $exception): Response
    {
        $status = in_array($exception->safeCode, [
            'ksef_offline_invoice_delivery_not_allowed',
            'ksef_transaction_confirmation_not_allowed',
            'ksef_offline_preacceptance_delivery_closed',
            'ksef_accepted_offline_presentation_not_allowed',
        ], true) ? 403 : 422;

        return response($exception->getMessage(), $status, [
            'Content-Type' => 'text/plain; charset=UTF-8',
            'Cache-Control' => 'private, no-store, max-age=0',
            'Pragma' => 'no-cache',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function unexpectedErrorResponse(
        Invoice $invoice,
        KsefOfflineIssuance $issuance,
        Throwable $exception,
    ): Response {
        Log::error('Nieoczekiwany błąd prezentacji dokumentu Offline.', [
            'invoice_id' => $invoice->getKey(),
            'issuance_id' => $issuance->getKey(),
            'environment' => $issuance->environment->value,
            'exception_class' => $exception::class,
        ]);

        return response(
            'Nie udało się bezpiecznie przygotować dokumentu Offline.',
            500,
            [
                'Content-Type' => 'text/plain; charset=UTF-8',
                'Cache-Control' => 'private, no-store, max-age=0',
                'Pragma' => 'no-cache',
                'X-Content-Type-Options' => 'nosniff',
            ],
        );
    }
}
