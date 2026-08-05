<?php

namespace Modules\Invoices\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\View\View;
use Modules\Invoices\Exceptions\InvoiceDomainException;
use Modules\Invoices\Http\Requests\UpdateInvoiceBuyerRequest;
use Modules\Invoices\Http\Requests\UpdateInvoiceDetailsRequest;
use Modules\Invoices\Http\Requests\UpdateInvoiceRecipientRequest;
use Modules\Invoices\Models\Invoice;
use Modules\Invoices\Services\InvoiceEditabilityPolicy;
use Modules\Invoices\Services\InvoiceEditAjaxResponder;
use Modules\Invoices\Services\InvoiceEditCurrencyConversionService;
use Modules\Invoices\Services\InvoiceEditService;
use Modules\Invoices\Services\InvoiceEditViewModelFactory;
use Throwable;

class InvoiceEditController extends Controller
{
    public function edit(
        Invoice $invoice,
        InvoiceEditabilityPolicy $policy,
        InvoiceEditCurrencyConversionService $currency,
        InvoiceEditViewModelFactory $viewModels,
    ): View {
        try {
            $policy->assertEditable($invoice);
            $currency->assertSnapshotUsableForAnyEdit($invoice);
        } catch (InvoiceDomainException $exception) {
            if ($exception->errorCode() === 'invoice_edit_blocked_by_correction') {
                $correction = Invoice::query()->findOrFail($exception->metadata()['correction_id']);

                return view('invoices.edit-blocked-by-correction', [
                    'invoice' => $invoice,
                    'correction' => $correction,
                ]);
            }

            abort(422, $exception->getMessage());
        }

        return view('invoices.edit', $viewModels->make($invoice));
    }

    public function updateBuyer(UpdateInvoiceBuyerRequest $request, Invoice $invoice, InvoiceEditService $service, InvoiceEditAjaxResponder $responder): JsonResponse
    {
        return $this->respond($invoice, $request->integer('expected_lock_version'), ['buyer'], fn () => $service->updateBuyer($invoice, $request->validated()), $responder);
    }

    public function updateRecipient(UpdateInvoiceRecipientRequest $request, Invoice $invoice, InvoiceEditService $service, InvoiceEditAjaxResponder $responder): JsonResponse
    {
        return $this->respond($invoice, $request->integer('expected_lock_version'), ['recipient'], fn () => $service->updateRecipient($invoice, $request->validated()), $responder);
    }

    public function updateDetails(UpdateInvoiceDetailsRequest $request, Invoice $invoice, InvoiceEditService $service, InvoiceEditAjaxResponder $responder): JsonResponse
    {
        return $this->respond($invoice, $request->integer('expected_lock_version'), ['details', 'totals', 'nbp-summary'], fn () => $service->updateDetails($invoice, $request->validated()), $responder);
    }

    /** @param list<string> $fragments */
    private function respond(Invoice $invoice, int $expectedLockVersion, array $fragments, callable $operation, InvoiceEditAjaxResponder $responder): JsonResponse
    {
        try {
            return $responder->success($operation(), $fragments, $expectedLockVersion);
        } catch (InvoiceDomainException $exception) {
            return $responder->domainError($exception);
        } catch (Throwable $exception) {
            return $responder->unexpected($exception, $invoice);
        }
    }
}
