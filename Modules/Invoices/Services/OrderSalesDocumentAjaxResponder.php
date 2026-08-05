<?php

namespace Modules\Invoices\Services;

use App\Models\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Modules\Invoices\Exceptions\InvoiceDomainException;
use Modules\Invoices\Models\Invoice;
use Throwable;

class OrderSalesDocumentAjaxResponder
{
    public function __construct(
        private readonly OrderSalesDocumentActionsView $actionsView,
    ) {}

    public function success(Order $order, Invoice $invoice, int $status): JsonResponse
    {
        return response()->json([
            'html' => $this->actionsView->render($order),
            'document' => [
                'id' => $invoice->getKey(),
                'type' => $invoice->document_type->value,
                'number' => $invoice->number,
                'pdf_url' => route('invoices.pdf', $invoice),
            ],
        ], $status);
    }

    public function deleted(Order $order): JsonResponse
    {
        return response()->json([
            'html' => $this->actionsView->render($order),
            'redirect_url' => route('orders.show', $order),
        ]);
    }

    public function domainError(InvoiceDomainException $exception): JsonResponse
    {
        $conflictCodes = [
            'invoice_already_exists',
            'invoice_document_slot_conflict',
            'invoice_document_slot_inconsistent',
            'proforma_locked_by_invoice',
            'proforma_refresh_conflict',
            'invoice_delete_conflict',
            'invoice_delete_inconsistent_document',
            'invoice_delete_numbering_inconsistent',
        ];

        return response()->json([
            'message' => $exception->getMessage(),
            'code' => $exception->errorCode(),
        ], in_array($exception->errorCode(), $conflictCodes, true) ? 409 : 422);
    }

    public function unexpected(Throwable $exception, ?Order $order, string $type): JsonResponse
    {
        Log::error('Nieoczekiwany błąd operacji na dokumencie sprzedaży.', [
            'order_id' => $order?->getKey(),
            'document_type' => $type,
            'exception' => $exception,
        ]);

        return response()->json([
            'message' => 'Nie udało się wykonać operacji na dokumencie. Spróbuj ponownie.',
            'code' => 'invoice_operation_failed',
        ], 500);
    }
}
