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
use Modules\Invoices\Support\InvoiceReturnContext;
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
        $isCorrection = $invoice->isCorrection();
        $returnContext = InvoiceReturnContext::fromRequest($request);
        $returningFromEditor = $request->exists('return_query');

        try {
            $order = $deletion->delete(
                $invoice,
                $request->integer('expected_lock_version'),
                $contextFactory->manual($request),
            );

            if ($request->expectsJson()) {
                return $responder->deleted($order);
            }

            $response = redirect($returnContext->url($order));

            if ($returningFromEditor || ! $returnContext->isList()) {
                return $response;
            }

            return $response->with('success', match ($returnContext->returnTo()) {
                InvoiceReturnContext::PROFORMAS => 'Pro forma została usunięta.',
                InvoiceReturnContext::CORRECTIONS => 'Korekta została usunięta.',
                default => 'Faktura została usunięta.',
            });
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
                'invoice' => match (true) {
                    $isProforma => 'Nie udało się usunąć Pro formy. Spróbuj ponownie.',
                    $isCorrection => 'Nie udało się usunąć Korekty. Spróbuj ponownie.',
                    default => 'Nie udało się usunąć Faktury. Spróbuj ponownie.',
                },
            ]);
        }
    }
}
