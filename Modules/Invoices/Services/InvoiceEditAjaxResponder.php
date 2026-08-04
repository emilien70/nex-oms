<?php

namespace Modules\Invoices\Services;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Modules\Invoices\Exceptions\InvoiceDomainException;
use Modules\Invoices\Models\Invoice;
use Throwable;

class InvoiceEditAjaxResponder
{
    public function __construct(private readonly InvoiceEditViewModelFactory $viewModels) {}

    /** @param list<string> $fragments */
    public function success(Invoice $invoice, array $fragments, int $previousLockVersion): JsonResponse
    {
        $invoice = $invoice->refresh()->load(['order.items', 'items', 'series']);
        $data = $this->viewModels->make($invoice);
        $html = [];
        foreach ($fragments as $fragment) {
            $html[$fragment] = view('invoices.edit.partials.'.$fragment, $data)->render();
        }

        return response()->json([
            'status' => $invoice->lock_version === $previousLockVersion ? 'unchanged' : 'updated',
            'lock_version' => $invoice->lock_version,
            'fragments' => $html,
        ]);
    }

    public function domainError(InvoiceDomainException $exception): JsonResponse
    {
        return response()->json([
            'message' => $exception->getMessage(),
            'code' => $exception->errorCode(),
        ], $exception->errorCode() === 'invoice_edit_conflict' ? 409 : 422);
    }

    public function unexpected(Throwable $exception, Invoice $invoice): JsonResponse
    {
        Log::error('Nieoczekiwany błąd edycji Faktury VAT.', [
            'invoice_id' => $invoice->getKey(),
            'exception' => $exception,
        ]);

        return response()->json([
            'message' => 'Nie udało się zapisać zmian Faktury. Spróbuj ponownie.',
            'code' => 'invoice_edit_failed',
        ], 500);
    }
}
