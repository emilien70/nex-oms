<?php

namespace Modules\Invoices\Http\Controllers;

use App\Http\Controllers\Controller;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Modules\Invoices\Enums\CorrectionIssuerSource;
use Modules\Invoices\Enums\CorrectionPaymentMethodSource;
use Modules\Invoices\Enums\CorrectionSaleDateSource;
use Modules\Invoices\Enums\InvoiceDocumentType;
use Modules\Invoices\Enums\InvoicePaymentDueMode;
use Modules\Invoices\Enums\InvoicePaymentMethodSource;
use Modules\Invoices\Enums\InvoicePrimaryLanguage;
use Modules\Invoices\Enums\InvoicePrintTemplate;
use Modules\Invoices\Enums\InvoiceSaleDateSource;
use Modules\Invoices\Enums\InvoiceSecondaryLanguage;
use Modules\Invoices\Enums\InvoiceSeriesResetPeriod;
use Modules\Invoices\Enums\InvoiceShippingVatMode;
use Modules\Invoices\Enums\InvoiceUnitPriceMode;
use Modules\Invoices\Enums\InvoiceVatRateSource;
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
        try {
            $this->seriesManagement->create($request->validated());
        } catch (DomainException $exception) {
            return $this->domainError($exception);
        }

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
        $correctionSeries = InvoiceSeries::query()
            ->where('document_type', InvoiceDocumentType::Correction->value)
            ->where(function ($query) use ($series): void {
                $query->where('is_active', true);

                if ($series?->default_correction_series_id !== null) {
                    $query->orWhere('id', $series->default_correction_series_id);
                }
            })
            ->orderByDesc('is_system')
            ->orderBy('name')
            ->orderBy('id')
            ->get();

        $isNewProforma = $series === null && $documentType === InvoiceDocumentType::Proforma;

        return [
            'documentType' => $documentType,
            'documentTypes' => InvoiceDocumentType::cases(),
            'resetPeriods' => InvoiceSeriesResetPeriod::cases(),
            'vatRateSources' => InvoiceVatRateSource::cases(),
            'shippingVatModes' => InvoiceShippingVatMode::cases(),
            'paymentMethodSources' => InvoicePaymentMethodSource::cases(),
            'saleDateSources' => InvoiceSaleDateSource::cases(),
            'paymentDueModes' => InvoicePaymentDueMode::cases(),
            'unitPriceModes' => InvoiceUnitPriceMode::cases(),
            'printTemplates' => InvoicePrintTemplate::cases(),
            'primaryLanguages' => InvoicePrimaryLanguage::cases(),
            'secondaryLanguages' => InvoiceSecondaryLanguage::cases(),
            'correctionSaleDateSources' => CorrectionSaleDateSource::cases(),
            'correctionIssuerSources' => CorrectionIssuerSource::cases(),
            'correctionPaymentMethodSources' => CorrectionPaymentMethodSource::cases(),
            'correctionSeries' => $correctionSeries,
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
                'seller_name' => $series?->seller_name,
                'seller_tax_id' => $series?->seller_tax_id,
                'seller_regon' => $series?->seller_regon,
                'seller_bdo' => $series?->seller_bdo,
                'seller_street' => $series?->seller_street,
                'seller_building_number' => $series?->seller_building_number,
                'seller_apartment_number' => $series?->seller_apartment_number,
                'seller_postal_code' => $series?->seller_postal_code,
                'seller_city' => $series?->seller_city,
                'seller_province' => $series?->seller_province,
                'seller_country_code' => $series?->seller_country_code ?? 'PL',
                'seller_email' => $series?->seller_email,
                'seller_phone' => $series?->seller_phone,
                'seller_bank_name' => $series?->seller_bank_name,
                'seller_bank_account' => $series?->seller_bank_account,
                'seller_bank_swift' => $series?->seller_bank_swift,
                'place_of_issue' => $series?->place_of_issue,
                'issuer_name' => $series?->issuer_name,
                'default_correction_series_id' => $series?->default_correction_series_id,
                'vat_rate_source' => $series?->vat_rate_source?->value ?? InvoiceVatRateSource::OrderItem->value,
                'default_vat_rate' => $series?->default_vat_rate,
                'include_shipping' => $series?->include_shipping ?? ! $isNewProforma,
                'shipping_vat_mode' => $series?->shipping_vat_mode?->value ?? InvoiceShippingVatMode::HighestItem->value,
                'default_shipping_vat_rate' => $series?->default_shipping_vat_rate,
                'skip_zero_price_items' => $series?->skip_zero_price_items ?? false,
                'payment_method_source' => $series?->payment_method_source?->value ?? ($isNewProforma
                    ? InvoicePaymentMethodSource::None->value
                    : InvoicePaymentMethodSource::Order->value),
                'fixed_payment_method' => $series?->fixed_payment_method,
                'sale_date_source' => $series?->sale_date_source?->value ?? InvoiceSaleDateSource::PaymentOrIssue->value,
                'payment_due_mode' => $series?->payment_due_mode?->value ?? InvoicePaymentDueMode::None->value,
                'payment_due_days' => $series?->payment_due_days,
                'unit_price_mode' => $series?->unit_price_mode?->value ?? ($isNewProforma
                    ? InvoiceUnitPriceMode::Net->value
                    : InvoiceUnitPriceMode::Gross->value),
                'show_vat_column' => $series?->show_vat_column ?? true,
                'show_order_number' => $series?->show_order_number ?? false,
                'show_buyer_signature' => $series?->show_buyer_signature ?? false,
                'show_original_copy' => $series?->show_original_copy ?? false,
                'print_template' => $series?->print_template?->value ?? InvoicePrintTemplate::Standard->value,
                'primary_language' => $series?->primary_language?->value ?? InvoicePrimaryLanguage::BuyerCountry->value,
                'secondary_language' => $series?->secondary_language?->value,
                'document_title' => $series?->document_title ?? match ($documentType) {
                    InvoiceDocumentType::Invoice => 'Faktura VAT',
                    InvoiceDocumentType::Correction => 'Faktura korygująca',
                    InvoiceDocumentType::Proforma => 'Faktura pro forma',
                },
                'copies_count' => $series?->copies_count ?? 1,
                'additional_information_template' => $series?->additional_information_template,
                'logo_path' => $series?->logo_path,
                'remove_logo' => false,
                'default_correction_reason' => $series?->default_correction_reason,
                'correction_sale_date_source' => $series?->correction_sale_date_source?->value
                    ?? CorrectionSaleDateSource::SourceInvoice->value,
                'correction_issuer_source' => $series?->correction_issuer_source?->value
                    ?? CorrectionIssuerSource::SourceInvoice->value,
                'correction_payment_method_source' => $series?->correction_payment_method_source?->value
                    ?? CorrectionPaymentMethodSource::SourceInvoice->value,
                'show_correction_item_sequence' => $series?->show_correction_item_sequence ?? false,
                'show_return_id_in_header' => $series?->show_return_id_in_header ?? false,
                'show_payment_identifier' => $series?->show_payment_identifier ?? false,
            ],
        ];
    }
}
