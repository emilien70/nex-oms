<?php

namespace Modules\Invoices\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Response;
use Modules\Invoices\Exceptions\InvoiceDomainException;
use Modules\Invoices\Models\Invoice;
use Modules\Invoices\Services\InvoicePdfFilenameGenerator;
use Modules\Invoices\Services\InvoicePdfService;

class InvoicePdfController extends Controller
{
    public function show(
        Invoice $invoice,
        InvoicePdfService $pdfs,
        InvoicePdfFilenameGenerator $filenames,
    ): Response {
        try {
            $contents = $pdfs->contents($invoice);
        } catch (InvoiceDomainException $exception) {
            $status = $exception->errorCode() === 'invoice_pdf_not_available' ? 404 : 422;

            return response($exception->getMessage(), $status, [
                'Content-Type' => 'text/plain; charset=UTF-8',
                'Cache-Control' => 'private, no-store',
            ]);
        }

        return response($contents, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$filenames->downloadName($invoice).'"',
            'Cache-Control' => 'private, no-store, max-age=0',
            'Pragma' => 'no-cache',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
