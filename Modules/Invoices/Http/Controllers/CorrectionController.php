<?php

namespace Modules\Invoices\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Modules\Invoices\Exceptions\InvoiceDomainException;
use Modules\Invoices\Http\Requests\CorrectionDraftRequest;
use Modules\Invoices\Http\Requests\UpdateCorrectionRequest;
use Modules\Invoices\Models\Invoice;
use Modules\Invoices\Models\InvoiceSeries;
use Modules\Invoices\Services\CorrectionService;
use Modules\Invoices\Services\CorrectionSourceStateService;
use Modules\Invoices\Services\CorrectionViewModelFactory;
use Modules\Invoices\Services\InvoiceOperationContextFactory;
use Modules\Invoices\Support\InvoiceReturnContext;
use Modules\Ksef\Services\KsefDocumentViewData;

class CorrectionController extends Controller
{
    public function create(
        Request $request,
        Invoice $invoice,
        CorrectionSourceStateService $sourceState,
        CorrectionViewModelFactory $viewModels,
    ): View|RedirectResponse {
        $returnContext = InvoiceReturnContext::fromRequest($request);

        try {
            $currentCorrection = $sourceState->currentCorrection($invoice);

            if ($currentCorrection !== null) {
                return redirect()->route('invoices.corrections.edit', [
                    'correction' => $currentCorrection,
                    ...$returnContext->parameters(),
                ]);
            }

            return view('invoices.corrections.create', [
                ...$viewModels->make(
                    $invoice,
                    $request->filled('series_id') ? $request->integer('series_id') : null,
                ),
                'returnContext' => $returnContext,
            ]);
        } catch (InvoiceDomainException $exception) {
            abort(422, $exception->getMessage());
        }
    }

    public function store(
        CorrectionDraftRequest $request,
        Invoice $invoice,
        CorrectionService $service,
        InvoiceOperationContextFactory $contexts,
    ): RedirectResponse {
        $returnContext = InvoiceReturnContext::fromRequest($request);

        try {
            $series = InvoiceSeries::query()->findOrFail($request->integer('correction_series_id'));
            $correction = $service->issue(
                $invoice,
                $series,
                $request->integer('expected_source_document_id'),
                $request->integer('expected_source_lock_version'),
                $request->validated(),
                $contexts->manual($request),
            );

            return redirect()->route('invoices.corrections.edit', [
                'correction' => $correction,
                ...$returnContext->parameters(),
            ]);
        } catch (InvoiceDomainException $exception) {
            if ($exception->errorCode() === 'correction_already_exists'
                && isset($exception->metadata()['correction_id'])) {
                return redirect()->route('invoices.corrections.edit', [
                    'correction' => $exception->metadata()['correction_id'],
                    ...$returnContext->parameters(),
                ]);
            }

            return back()->withInput()->withErrors(['correction' => $exception->getMessage()]);
        }
    }

    public function edit(Request $request, Invoice $correction, CorrectionViewModelFactory $viewModels, KsefDocumentViewData $ksefViews): View
    {
        $returnContext = InvoiceReturnContext::fromRequest($request);

        try {
            return view('invoices.corrections.create', [
                ...$viewModels->makeForEdit($correction),
                ...$ksefViews->make($correction),
                'returnContext' => $returnContext,
            ]);
        } catch (InvoiceDomainException $exception) {
            abort(422, $exception->getMessage());
        }
    }

    public function update(
        UpdateCorrectionRequest $request,
        Invoice $correction,
        CorrectionService $service,
    ): RedirectResponse {
        $returnContext = InvoiceReturnContext::fromRequest($request);

        try {
            $updated = $service->update($correction, $request->validated());

            return redirect()->route('invoices.corrections.edit', [
                'correction' => $updated,
                ...$returnContext->parameters(),
            ]);
        } catch (InvoiceDomainException $exception) {
            return back()->withInput()->withErrors(['correction' => $exception->getMessage()]);
        }
    }
}
