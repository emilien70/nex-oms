<?php

namespace Modules\Invoices\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use Modules\Invoices\Enums\InvoiceDocumentType;
use Modules\Invoices\Exceptions\InvoiceDomainException;
use Modules\Invoices\Http\Requests\CorrectionBulkPdfRequest;
use Modules\Invoices\Http\Requests\InvoiceBulkPdfRequest;
use Modules\Invoices\Http\Requests\ProformaBulkPdfRequest;
use Modules\Invoices\Services\InvoiceBulkPdfService;

class InvoiceBulkPdfController extends Controller
{
    public function __invoke(
        InvoiceBulkPdfRequest $request,
        InvoiceBulkPdfService $pdfs,
    ): Response|RedirectResponse {
        return $this->response($request, $pdfs, InvoiceDocumentType::Invoice, 'faktury-zbiorcze.pdf');
    }

    public function proformas(
        ProformaBulkPdfRequest $request,
        InvoiceBulkPdfService $pdfs,
    ): Response|RedirectResponse {
        return $this->response($request, $pdfs, InvoiceDocumentType::Proforma, 'proformy-zbiorcze.pdf');
    }

    public function corrections(
        CorrectionBulkPdfRequest $request,
        InvoiceBulkPdfService $pdfs,
    ): Response|RedirectResponse {
        return $this->response($request, $pdfs, InvoiceDocumentType::Correction, 'korekty-zbiorcze.pdf');
    }

    private function response(
        InvoiceBulkPdfRequest $request,
        InvoiceBulkPdfService $pdfs,
        InvoiceDocumentType $documentType,
        string $filename,
    ): Response|RedirectResponse {
        try {
            $contents = $pdfs->contents($request->validated('invoice_ids'), $documentType);
        } catch (InvoiceDomainException $exception) {
            return back()->withErrors(['invoice_ids' => $exception->getMessage()]);
        }

        return response($contents, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$filename.'"',
            'Cache-Control' => 'private, no-store, max-age=0',
            'Pragma' => 'no-cache',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
