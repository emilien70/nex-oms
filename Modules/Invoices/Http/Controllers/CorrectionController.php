<?php

namespace Modules\Invoices\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\OrderReturn;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Modules\Invoices\Enums\CorrectionReason;
use Modules\Invoices\Exceptions\InvoiceDomainException;
use Modules\Invoices\Http\Requests\CorrectionDraftRequest;
use Modules\Invoices\Http\Requests\FinalizeCorrectionRequest;
use Modules\Invoices\Http\Requests\UpdateCorrectionRequest;
use Modules\Invoices\Models\Invoice;
use Modules\Invoices\Models\InvoiceSeries;
use Modules\Invoices\Services\CorrectionService;
use Modules\Invoices\Services\CorrectionSourceStateService;
use Modules\Invoices\Services\CorrectionViewModelFactory;
use Modules\Invoices\Services\InvoiceOperationContextFactory;
use Modules\Invoices\Services\ManualCorrectionFinalizationPolicy;
use Modules\Invoices\Services\ManualCorrectionFinalizationService;
use Modules\Invoices\Services\OrderReturnCorrectionService;
use Modules\Invoices\Support\InvoiceReturnContext;
use Modules\Ksef\Enums\KsefEnvironment;
use Modules\Ksef\Services\KsefDocumentViewData;

class CorrectionController extends Controller
{
    public function create(
        Request $request,
        Invoice $invoice,
        CorrectionSourceStateService $sourceState,
        CorrectionViewModelFactory $viewModels,
        OrderReturnCorrectionService $returnCorrections,
    ): View|RedirectResponse {
        $returnContext = InvoiceReturnContext::fromRequest($request);

        try {
            $request->validate(['order_return_id' => ['nullable', 'integer', 'min:1']]);
            if ($request->filled('order_return_id')) {
                $existing = $returnCorrections->existing($invoice, $request->integer('order_return_id'));
                if ($existing !== null) {
                    return redirect()->route('invoices.corrections.edit', ['correction' => $existing, ...$returnContext->parameters()]);
                }
            }
            $currentCorrection = $sourceState->currentCorrection($invoice);

            if ($currentCorrection !== null) {
                return redirect()->route('invoices.corrections.edit', [
                    'correction' => $currentCorrection,
                    'return_correction_conflict' => $request->filled('order_return_id') ? $request->integer('order_return_id') : null,
                    ...$returnContext->parameters(),
                ])->with('warning', $request->filled('order_return_id')
                    ? 'Dla tego zamówienia trwa edycja innej Korekty. Dokończ ją przed utworzeniem dokumentu do zwrotu.' : null);
            }

            $view = $viewModels->make($invoice, $request->filled('series_id') ? $request->integer('series_id') : null);
            $returnCorrection = $request->filled('order_return_id')
                ? $returnCorrections->prepare($invoice, $request->integer('order_return_id'), $sourceState->effectiveItems($invoice)) : null;
            if ($returnCorrection !== null) {
                $view['defaults']['reason'] = CorrectionReason::GoodsReturn->value;
                $view['defaultChangeItems'] = true;
            }

            return view('invoices.corrections.create', [
                ...$view,
                'returnCorrection' => $returnCorrection,
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
            if (in_array($exception->errorCode(), ['correction_already_exists', 'return_correction_exists'], true)
                && isset($exception->metadata()['correction_id'])) {
                return redirect()->route('invoices.corrections.edit', [
                    'correction' => $exception->metadata()['correction_id'],
                    ...$returnContext->parameters(),
                ]);
            }

            return back()->withInput()->withErrors(['correction' => $exception->getMessage()]);
        }
    }

    public function edit(Request $request, Invoice $correction, CorrectionViewModelFactory $viewModels, KsefDocumentViewData $ksefViews, OrderReturnCorrectionService $returnCorrections, ManualCorrectionFinalizationPolicy $manualFinalization): View
    {
        $returnContext = InvoiceReturnContext::fromRequest($request);
        $manualFinalizationReview = $manualFinalization->review($correction);

        try {
            return view('invoices.corrections.create', [
                ...$viewModels->makeForEdit($correction),
                ...$ksefViews->make($correction),
                'returnCorrection' => $returnCorrections->forEdit($correction),
                'returnCorrectionConflict' => $request->integer('return_correction_conflict') > 0
                    && $correction->order_return_id !== $request->integer('return_correction_conflict')
                    && OrderReturn::query()->whereKey($request->integer('return_correction_conflict'))->where('order_id', $correction->order_id)->exists(),
                'returnContext' => $returnContext,
                'manualFinalizationEnvironment' => $manualFinalizationReview['environment'],
                'manualFinalization' => $manualFinalizationReview,
            ]);
        } catch (InvoiceDomainException $exception) {
            abort(422, $exception->getMessage());
        }
    }

    public function finalize(FinalizeCorrectionRequest $request, Invoice $correction, ManualCorrectionFinalizationService $service): RedirectResponse
    {
        $url = route('invoices.corrections.edit', [
            'correction' => $correction,
            ...InvoiceReturnContext::fromRequest($request)->parameters(),
        ]);

        try {
            $service->finalize(
                $correction,
                $request->integer('expected_lock_version'),
                KsefEnvironment::from($request->validated('expected_ksef_environment')),
                $request->boolean('confirm_outside_ksef'),
                $request->integer('expected_source_invoice_id'),
                $request->integer('expected_source_invoice_lock_version'),
                $request->boolean('confirm_source_outside_ksef'),
            );

            return redirect($url)->with('success', 'Korekta została zamknięta. Można teraz wystawić kolejną korektę do tej samej faktury.');
        } catch (InvoiceDomainException $exception) {
            return redirect($url)->withErrors(['correction' => $exception->getMessage()]);
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
