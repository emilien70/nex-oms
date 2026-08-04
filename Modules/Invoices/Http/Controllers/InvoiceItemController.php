<?php

namespace Modules\Invoices\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Modules\Invoices\Exceptions\InvoiceDomainException;
use Modules\Invoices\Http\Requests\InvoiceExpectedLockVersionRequest;
use Modules\Invoices\Http\Requests\InvoiceItemRequest;
use Modules\Invoices\Models\Invoice;
use Modules\Invoices\Models\InvoiceItem;
use Modules\Invoices\Services\InvoiceEditAjaxResponder;
use Modules\Invoices\Services\InvoiceEditService;
use Throwable;

class InvoiceItemController extends Controller
{
    public function store(InvoiceItemRequest $request, Invoice $invoice, InvoiceEditService $service, InvoiceEditAjaxResponder $responder): JsonResponse
    {
        return $this->respond($invoice, $request->integer('expected_lock_version'), fn () => $service->addItem($invoice, $request->validated()), $responder);
    }

    public function update(InvoiceItemRequest $request, Invoice $invoice, InvoiceItem $invoiceItem, InvoiceEditService $service, InvoiceEditAjaxResponder $responder): JsonResponse
    {
        return $this->respond($invoice, $request->integer('expected_lock_version'), fn () => $service->updateItem($invoice, $invoiceItem, $request->validated()), $responder);
    }

    public function destroy(InvoiceExpectedLockVersionRequest $request, Invoice $invoice, InvoiceItem $invoiceItem, InvoiceEditService $service, InvoiceEditAjaxResponder $responder): JsonResponse
    {
        return $this->respond($invoice, $request->integer('expected_lock_version'), fn () => $service->deleteItem($invoice, $invoiceItem, $request->integer('expected_lock_version')), $responder);
    }

    private function respond(Invoice $invoice, int $expectedLockVersion, callable $operation, InvoiceEditAjaxResponder $responder): JsonResponse
    {
        try {
            return $responder->success($operation(), ['items', 'totals', 'nbp-summary'], $expectedLockVersion);
        } catch (InvoiceDomainException $exception) {
            return $responder->domainError($exception);
        } catch (ModelNotFoundException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            return $responder->unexpected($exception, $invoice);
        }
    }
}
