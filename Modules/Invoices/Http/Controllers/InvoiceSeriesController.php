<?php

namespace Modules\Invoices\Http\Controllers;

use App\Http\Controllers\Controller;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Modules\Invoices\Enums\InvoiceDocumentType;
use Modules\Invoices\Enums\InvoiceSeriesResetPeriod;
use Modules\Invoices\Http\Requests\StoreInvoiceSeriesRequest;
use Modules\Invoices\Http\Requests\UpdateInvoiceSeriesActiveRequest;
use Modules\Invoices\Http\Requests\UpdateInvoiceSeriesRequest;
use Modules\Invoices\Models\InvoiceSeries;
use Modules\Invoices\Services\InvoiceSeriesManagementService;

class InvoiceSeriesController extends Controller
{
    public function __construct(
        private readonly InvoiceSeriesManagementService $seriesManagement,
    ) {}

    public function index(Request $request): View
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

        $reopenForm = $this->reopenFormData($request);

        return view('invoices.series.index', compact('series', 'reopenForm'));
    }

    public function form(Request $request): View
    {
        $validated = $request->validate(
            ['document_type' => ['required', Rule::enum(InvoiceDocumentType::class)]],
            [
                'document_type.required' => 'Wybierz typ dokumentu.',
                'document_type.enum' => 'Wybrany typ dokumentu jest nieprawidłowy.',
            ],
        );
        $documentType = InvoiceDocumentType::from($validated['document_type']);

        return view(
            'invoices.series.partials._form',
            $this->formViewData($documentType),
        );
    }

    public function store(StoreInvoiceSeriesRequest $request): RedirectResponse
    {
        $this->seriesManagement->create($request->validated());

        return redirect()
            ->route('invoices.series.index')
            ->with('success', 'Seria numeracji została utworzona.');
    }

    public function edit(InvoiceSeries $series): View
    {
        return view(
            'invoices.series.partials._form',
            $this->formViewData($series->document_type, $series),
        );
    }

    public function update(
        UpdateInvoiceSeriesRequest $request,
        InvoiceSeries $series,
    ): RedirectResponse {
        try {
            $this->seriesManagement->update($series, $request->validated());
        } catch (DomainException $exception) {
            return $this->domainError($exception);
        }

        return redirect()
            ->route('invoices.series.index')
            ->with('success', 'Seria numeracji została zaktualizowana.');
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

    /**
     * @return array<string, mixed>|null
     */
    private function reopenFormData(Request $request): ?array
    {
        $mode = $request->old('form_mode');

        if (! in_array($mode, ['create', 'edit'], true)) {
            return null;
        }

        $series = null;
        if ($mode === 'edit') {
            $series = InvoiceSeries::query()->find((int) $request->old('editing_series_id'));

            if ($series === null) {
                return null;
            }
        }

        $documentType = InvoiceDocumentType::tryFrom((string) $request->old('document_type'));

        return [
            'mode' => $mode,
            'series' => $series,
            'action' => $series === null
                ? route('invoices.series.store')
                : route('invoices.series.update', $series),
            'method' => $series === null ? 'POST' : 'PATCH',
            'viewData' => $documentType === null
                ? null
                : $this->formViewData($documentType, $series, true),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function formViewData(
        InvoiceDocumentType $documentType,
        ?InvoiceSeries $series = null,
        bool $showValidationErrors = false,
    ): array {
        return [
            'documentType' => $documentType,
            'documentTypes' => InvoiceDocumentType::cases(),
            'resetPeriods' => InvoiceSeriesResetPeriod::cases(),
            'series' => $series,
            'showValidationErrors' => $showValidationErrors,
            'useOldInput' => $showValidationErrors,
            'values' => [
                'document_type' => $series?->document_type->value ?? $documentType->value,
                'name' => $series?->name ?? '',
                'number_format' => $series?->number_format ?? $documentType->defaultNumberFormat(),
                'reset_period' => $series?->reset_period->value ?? InvoiceSeriesResetPeriod::Yearly->value,
                'fiscal_year_start_month' => $series?->fiscal_year_start_month ?? 1,
                'default_currency' => $series?->default_currency ?? 'PLN',
                'is_active' => $series?->is_active ?? true,
            ],
        ];
    }
}
