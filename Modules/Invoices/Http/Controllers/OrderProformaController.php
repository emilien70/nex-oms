<?php

namespace Modules\Invoices\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Order;
use Illuminate\Http\JsonResponse;
use Modules\Invoices\Enums\ProformaOperationStatus;
use Modules\Invoices\Exceptions\InvoiceDomainException;
use Modules\Invoices\Http\Requests\IssueOrderDocumentRequest;
use Modules\Invoices\Models\InvoiceSeries;
use Modules\Invoices\Services\InvoiceOperationContextFactory;
use Modules\Invoices\Services\OrderSalesDocumentAjaxResponder;
use Modules\Invoices\Services\ProformaService;
use Throwable;

class OrderProformaController extends Controller
{
    public function store(
        IssueOrderDocumentRequest $request,
        Order $order,
        ProformaService $proformas,
        InvoiceOperationContextFactory $contextFactory,
        OrderSalesDocumentAjaxResponder $responder,
    ): JsonResponse {
        try {
            $series = InvoiceSeries::query()->findOrFail($request->integer('invoice_series_id'));
            $result = $proformas->createOrRefresh($order, $series, $contextFactory->manual($request));

            return $responder->success(
                $order,
                $result->invoice,
                $result->status === ProformaOperationStatus::Created ? 201 : 200,
            );
        } catch (InvoiceDomainException $exception) {
            return $responder->domainError($exception);
        } catch (Throwable $exception) {
            return $responder->unexpected($exception, $order, 'proforma');
        }
    }
}
