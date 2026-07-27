<?php

namespace Modules\Invoices\Http\Controllers;

use App\Http\Controllers\Controller;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;
use Modules\Invoices\Enums\InvoiceDocumentType;
use Modules\Invoices\Http\Requests\UpdateInvoiceSeriesActiveRequest;
use Modules\Invoices\Models\InvoiceSeries;
use Modules\Invoices\Services\InvoiceSeriesManagementService;

class InvoiceSeriesController extends Controller
{
    public function __construct(
        private readonly InvoiceSeriesManagementService $seriesManagement,
    ) {}

    public function index(): View
    {
        $series = InvoiceSeries::query()
            ->withCount('seriesUsingAsDefaultCorrection')
            ->orderByDesc('is_system')
            ->orderByRaw(
                'CASE document_type WHEN ? THEN 0 WHEN ? THEN 1 WHEN ? THEN 2 ELSE 3 END',
                [
                    InvoiceDocumentType::Invoice->value,
                    InvoiceDocumentType::Correction->value,
                    InvoiceDocumentType::Proforma->value,
                ],
            )
            ->orderBy('name')
            ->orderBy('id')
            ->paginate(10)
            ->withQueryString();

        return view('invoices.series.index', compact('series'));
    }

    public function updateActive(
        UpdateInvoiceSeriesActiveRequest $request,
        InvoiceSeries $series,
    ): RedirectResponse {
        $request->validated();
        $active = $request->boolean('is_active');

        try {
            $this->seriesManagement->setActive($series, $active);
        } catch (DomainException $exception) {
            return $this->domainError($exception);
        }

        return redirect()
            ->route('invoices.series.index')
            ->with(
                'success',
                $active
                    ? 'Seria numeracji została aktywowana.'
                    : 'Seria numeracji została ukryta.',
            );
    }

    public function destroy(InvoiceSeries $series): RedirectResponse
    {
        try {
            $this->seriesManagement->delete($series);
        } catch (DomainException $exception) {
            return $this->domainError($exception);
        }

        return redirect()
            ->route('invoices.series.index')
            ->with('success', 'Seria numeracji została usunięta.');
    }

    private function domainError(DomainException $exception): RedirectResponse
    {
        return redirect()
            ->route('invoices.series.index')
            ->withErrors(['series' => $exception->getMessage()]);
    }
}
