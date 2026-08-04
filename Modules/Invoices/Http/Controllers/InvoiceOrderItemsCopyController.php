<?php

namespace Modules\Invoices\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Modules\Invoices\Exceptions\InvoiceDomainException;
use Modules\Invoices\Http\Requests\InvoiceExpectedLockVersionRequest;
use Modules\Invoices\Models\Invoice;
use Modules\Invoices\Services\InvoiceEditAjaxResponder;
use Modules\Invoices\Services\InvoiceEditService;
use Throwable;

class InvoiceOrderItemsCopyController extends Controller
{
    public function store(InvoiceExpectedLockVersionRequest $request, Invoice $invoice, InvoiceEditService $service, InvoiceEditAjaxResponder $responder): JsonResponse
    {
        try {
            $expected = $request->integer('expected_lock_version');
            $updated = $service->copyItemsFromOrder($invoice, $expected);

            return $responder->success($updated, ['items', 'totals', 'nbp-summary'], $expected);
        } catch (InvoiceDomainException $exception) {
            return $responder->domainError($exception);
        } catch (Throwable $exception) {
            return $responder->unexpected($exception, $invoice);
        }
    }
}
