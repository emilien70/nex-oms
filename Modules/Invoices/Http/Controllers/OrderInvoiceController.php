<?php

namespace Modules\Invoices\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Order;
use Illuminate\Http\JsonResponse;
use Modules\Invoices\Exceptions\InvoiceDomainException;
use Modules\Invoices\Http\Requests\IssueOrderDocumentRequest;
use Modules\Invoices\Models\InvoiceSeries;
use Modules\Invoices\Services\InvoiceIssuingService;
use Modules\Invoices\Services\InvoiceOperationContextFactory;
use Modules\Invoices\Services\OrderSalesDocumentAjaxResponder;
use Throwable;

class OrderInvoiceController extends Controller
{
    public function store(
        IssueOrderDocumentRequest $request,
        Order $order,
        InvoiceIssuingService $issuing,
        InvoiceOperationContextFactory $contextFactory,
        OrderSalesDocumentAjaxResponder $responder,
    ): JsonResponse {
        try {
            $series = InvoiceSeries::query()->findOrFail($request->integer('invoice_series_id'));
            $invoice = $issuing->issue($order, $series, $contextFactory->manual($request));

            return $responder->success($order, $invoice, 201);
        } catch (InvoiceDomainException $exception) {
            return $responder->domainError($exception);
        } catch (Throwable $exception) {
            return $responder->unexpected($exception, $order, 'invoice');
        }
    }
}
