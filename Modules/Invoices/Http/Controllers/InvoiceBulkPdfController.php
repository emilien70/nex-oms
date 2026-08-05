<?php

namespace Modules\Invoices\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use Modules\Invoices\Exceptions\InvoiceDomainException;
use Modules\Invoices\Http\Requests\InvoiceBulkPdfRequest;
use Modules\Invoices\Services\InvoiceBulkPdfService;

class InvoiceBulkPdfController extends Controller
{
    public function __invoke(
        InvoiceBulkPdfRequest $request,
        InvoiceBulkPdfService $pdfs,
    ): Response|RedirectResponse {
        try {
            $contents = $pdfs->contents($request->validated('invoice_ids'));
        } catch (InvoiceDomainException $exception) {
            return back()->withErrors(['invoice_ids' => $exception->getMessage()]);
        }

        return response($contents, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="faktury-zbiorcze.pdf"',
            'Cache-Control' => 'private, no-store, max-age=0',
            'Pragma' => 'no-cache',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
