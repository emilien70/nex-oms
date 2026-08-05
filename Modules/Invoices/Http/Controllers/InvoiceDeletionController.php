<?php

namespace Modules\Invoices\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Log;
use Modules\Invoices\Exceptions\InvoiceDomainException;
use Modules\Invoices\Http\Requests\InvoiceExpectedLockVersionRequest;
use Modules\Invoices\Models\Invoice;
use Modules\Invoices\Services\InvoiceDeletionService;
use Modules\Invoices\Services\InvoiceOperationContextFactory;
use Modules\Invoices\Services\OrderSalesDocumentAjaxResponder;
use Throwable;

class InvoiceDeletionController extends Controller
{
    public function __invoke(
        InvoiceExpectedLockVersionRequest $request,
        Invoice $invoice,
        InvoiceDeletionService $deletion,
        InvoiceOperationContextFactory $contextFactory,
        OrderSalesDocumentAjaxResponder $responder,
    ): JsonResponse|RedirectResponse {
        $order = $invoice->order;
        $isProforma = $invoice->isProforma();

        try {
            $order = $deletion->delete(
                $invoice,
                $request->integer('expected_lock_version'),
                $contextFactory->manual($request),
            );

            if ($request->expectsJson()) {
                return $responder->deleted($order);
            }

            if ($request->validated('return_to') === 'invoices') {
                return redirect()
                    ->route('invoices.index')
                    ->with('success', 'Faktura została usunięta.');
            }

            if ($request->validated('return_to') === 'proformas') {
                return redirect()
                    ->route('invoices.proformas.index')
                    ->with('success', 'Pro forma została usunięta.');
            }

            return redirect()->route('orders.show', $order);
        } catch (InvoiceDomainException $exception) {
            if ($request->expectsJson()) {
                return $responder->domainError($exception);
            }

            return back()->withErrors(['invoice' => $exception->getMessage()]);
        } catch (Throwable $exception) {
            if ($request->expectsJson()) {
                return $responder->unexpected($exception, $order, 'invoice_delete');
            }

            Log::error('Nieoczekiwany błąd usuwania dokumentu sprzedaży.', [
                'invoice_id' => $invoice->getKey(),
                'order_id' => $order?->getKey(),
                'document_type' => $invoice->document_type->value,
                'exception' => $exception,
            ]);

            return back()->withErrors([
                'invoice' => $isProforma
                    ? 'Nie udało się usunąć Pro formy. Spróbuj ponownie.'
                    : 'Nie udało się usunąć Faktury. Spróbuj ponownie.',
            ]);
        }
    }
}
