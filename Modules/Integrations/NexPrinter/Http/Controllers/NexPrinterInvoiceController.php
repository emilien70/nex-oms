<?php

namespace Modules\Integrations\NexPrinter\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Modules\Integrations\NexPrinter\Exceptions\NexPrinterException;
use Modules\Integrations\NexPrinter\Models\NexPrinter;
use Modules\Integrations\NexPrinter\Services\NexPrintJobService;
use Modules\Invoices\Exceptions\InvoiceDomainException;
use Modules\Invoices\Models\Invoice;

class NexPrinterInvoiceController extends Controller
{
    public function store(
        Request $request,
        Invoice $invoice,
        NexPrintJobService $jobs,
    ): JsonResponse|RedirectResponse {
        $validated = $request->validate([
            'idempotency_key' => ['required', 'uuid'],
            'printer_id' => ['required', 'integer', 'exists:nex_printers,id'],
        ]);

        try {
            $job = $jobs->queueInvoice(
                $invoice,
                NexPrinter::query()->findOrFail($validated['printer_id']),
                $validated['idempotency_key'],
            );
        } catch (NexPrinterException|InvoiceDomainException $exception) {
            if ($request->expectsJson()) {
                return response()->json([
                    'message' => $exception->getMessage(),
                    'error' => ['code' => $exception->errorCode()],
                ], $exception->errorCode() === 'idempotency_conflict' ? 409 : 422);
            }

            return back()->withErrors(['nex_printer' => $exception->getMessage()]);
        }

        $message = 'Zlecenie wydruku zostało dodane.';

        if ($request->expectsJson()) {
            return response()->json([
                'message' => $message,
                'job' => [
                    'id' => $job->public_id,
                    'status' => $job->status->value,
                    'document_number' => $job->document_number,
                ],
                'order' => $invoice->order_id === null ? null : [
                    'label' => 'Zamówienie '.$invoice->order_reference_snapshot,
                    'url' => route('orders.show', $invoice->order_id),
                ],
            ], $job->wasRecentlyCreated ? 201 : 200);
        }

        return back()->with(
            'success',
            $message,
        );
    }
}
