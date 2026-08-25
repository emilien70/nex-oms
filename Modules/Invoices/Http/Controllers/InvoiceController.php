<?php

namespace Modules\Invoices\Http\Controllers;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Modules\Invoices\Enums\InvoiceDocumentStatus;
use Modules\Invoices\Enums\InvoiceDocumentType;
use Modules\Invoices\Http\Requests\InvoiceIndexRequest;
use Modules\Invoices\Models\Invoice;
use Modules\Invoices\Models\InvoiceSeries;
use Modules\Invoices\Services\CorrectionSeriesResolver;
use Modules\Invoices\Services\InvoiceMoneyFormatter;
use Modules\Invoices\Support\InvoiceReturnContext;
use Modules\Ksef\Models\KsefInvoiceSubmission;
use Modules\Ksef\Models\KsefSeriesSetting;
use Modules\Ksef\Models\KsefSetting;
use Modules\Ksef\Services\KsefOperationalEnvironmentPolicy;

class InvoiceController
{
    public function __construct(
        private readonly CorrectionSeriesResolver $correctionSeries,
        private readonly InvoiceMoneyFormatter $moneyFormatter,
        private readonly KsefOperationalEnvironmentPolicy $ksefEnvironments,
    ) {}

    public function index(InvoiceIndexRequest $request): View
    {
        return $this->documentList($request, InvoiceDocumentType::Invoice);
    }

    public function proformas(InvoiceIndexRequest $request): View
    {
        return $this->documentList($request, InvoiceDocumentType::Proforma);
    }

    public function corrections(InvoiceIndexRequest $request): View
    {
        return $this->documentList($request, InvoiceDocumentType::Correction);
    }

    private function documentList(InvoiceIndexRequest $request, InvoiceDocumentType $documentType): View
    {
        $filters = $request->validated();
        $isInvoiceList = $documentType === InvoiceDocumentType::Invoice;
        $isProformaList = $documentType === InvoiceDocumentType::Proforma;
        $isCorrectionList = $documentType === InvoiceDocumentType::Correction;
        $relations = [
            'series:id,name',
            'order:id',
            'corrections' => fn ($query) => $query
                ->where('status', InvoiceDocumentStatus::Issued->value)
                ->orderByDesc('issued_at')
                ->orderByDesc('id'),
        ];

        $query = Invoice::query()
            ->with($relations)
            ->where('document_type', $documentType->value)
            ->where('status', InvoiceDocumentStatus::Issued->value);

        $this->applyFilters($query, $filters);
        $this->applySorting($query, $filters);

        $perPage = (int) ($filters['per_page'] ?? 25);
        $invoices = $query->paginate($perPage)->withQueryString();
        $ksefListData = $isInvoiceList
            ? $this->ksefListData($invoices->getCollection())
            : $this->emptyKsefListData();
        $series = InvoiceSeries::query()
            ->where('document_type', $documentType->value)
            ->orderByDesc('is_system')
            ->orderBy('name')
            ->get(['id', 'name']);

        $firstIssueDate = Invoice::query()
            ->where('document_type', $documentType->value)
            ->where('status', InvoiceDocumentStatus::Issued->value)
            ->min('issue_date');
        $lastIssueDate = Invoice::query()
            ->where('document_type', $documentType->value)
            ->where('status', InvoiceDocumentStatus::Issued->value)
            ->max('issue_date');
        $currentYear = now()->year;
        $firstYear = $firstIssueDate ? CarbonImmutable::parse($firstIssueDate)->year : $currentYear;
        $lastYear = $lastIssueDate ? CarbonImmutable::parse($lastIssueDate)->year : $currentYear;
        $years = range(max($lastYear, $currentYear), min($firstYear, $currentYear));

        $currencies = Invoice::query()
            ->where('document_type', $documentType->value)
            ->where('status', InvoiceDocumentStatus::Issued->value)
            ->whereNotNull('currency')
            ->distinct()
            ->orderBy('currency')
            ->pluck('currency');

        $returnTo = match ($documentType) {
            InvoiceDocumentType::Invoice => InvoiceReturnContext::INVOICES,
            InvoiceDocumentType::Proforma => InvoiceReturnContext::PROFORMAS,
            InvoiceDocumentType::Correction => InvoiceReturnContext::CORRECTIONS,
        };

        return view('invoices.index', [
            'invoices' => $invoices,
            'series' => $series,
            'filters' => $filters,
            'years' => $years,
            'currencies' => $currencies,
            'months' => [
                1 => 'Styczeń', 2 => 'Luty', 3 => 'Marzec', 4 => 'Kwiecień',
                5 => 'Maj', 6 => 'Czerwiec', 7 => 'Lipiec', 8 => 'Sierpień',
                9 => 'Wrzesień', 10 => 'Październik', 11 => 'Listopad', 12 => 'Grudzień',
            ],
            'perPage' => $perPage,
            'perPageOptions' => [25, 50, 75, 100, 150, 200, 300, 500, 1000],
            'isInvoiceList' => $isInvoiceList,
            'isProformaList' => $isProformaList,
            'isCorrectionList' => $isCorrectionList,
            'pageTitle' => match ($documentType) {
                InvoiceDocumentType::Invoice => 'Faktury',
                InvoiceDocumentType::Proforma => 'Faktury pro forma',
                InvoiceDocumentType::Correction => 'Korekty',
            },
            'listRouteName' => match ($documentType) {
                InvoiceDocumentType::Invoice => 'invoices.index',
                InvoiceDocumentType::Proforma => 'invoices.proformas.index',
                InvoiceDocumentType::Correction => 'invoices.corrections.index',
            },
            'bulkPdfRouteName' => match ($documentType) {
                InvoiceDocumentType::Invoice => 'invoices.bulk-pdf',
                InvoiceDocumentType::Proforma => 'invoices.proformas.bulk-pdf',
                InvoiceDocumentType::Correction => 'invoices.corrections.bulk-pdf',
            },
            'bulkDeleteRouteName' => match ($documentType) {
                InvoiceDocumentType::Invoice => 'invoices.bulk-delete',
                InvoiceDocumentType::Proforma => 'invoices.proformas.bulk-delete',
                InvoiceDocumentType::Correction => 'invoices.corrections.bulk-delete',
            },
            'documentName' => match ($documentType) {
                InvoiceDocumentType::Invoice => 'fakturę',
                InvoiceDocumentType::Proforma => 'Pro formę',
                InvoiceDocumentType::Correction => 'Korektę',
            },
            'documentNamePlural' => match ($documentType) {
                InvoiceDocumentType::Invoice => 'faktur',
                InvoiceDocumentType::Proforma => 'Pro form',
                InvoiceDocumentType::Correction => 'Korekt',
            },
            'fullNumberLabel' => match ($documentType) {
                InvoiceDocumentType::Invoice => 'Pełny numer faktury',
                InvoiceDocumentType::Proforma => 'Pełny numer Pro formy',
                InvoiceDocumentType::Correction => 'Pełny numer Korekty',
            },
            'numberSortLabel' => match ($documentType) {
                InvoiceDocumentType::Invoice => 'Numer faktury',
                InvoiceDocumentType::Proforma => 'Numer Pro formy',
                InvoiceDocumentType::Correction => 'Numer Korekty',
            },
            'documentEditRouteName' => match ($documentType) {
                InvoiceDocumentType::Invoice => 'invoices.edit',
                InvoiceDocumentType::Correction => 'invoices.corrections.edit',
                InvoiceDocumentType::Proforma => null,
            },
            'returnTo' => $returnTo,
            'returnContext' => InvoiceReturnContext::forList($request, $returnTo),
            'moneyFormatter' => $this->moneyFormatter,
            'showSalesRegisterAction' => $documentType !== InvoiceDocumentType::Proforma,
            'correctionSeries' => $isInvoiceList
                ? $this->correctionSeries->active()
                : collect(),
            ...$ksefListData,
        ]);
    }

    /**
     * @param  Collection<int, Invoice>  $invoices
     * @return array<string, mixed>
     */
    private function ksefListData(Collection $invoices): array
    {
        $settings = KsefSetting::query()
            ->where('singleton_key', KsefSetting::SINGLETON_KEY)
            ->first();
        $enabledSeriesIds = KsefSeriesSetting::query()
            ->where('is_enabled', true)
            ->pluck('invoice_series_id')
            ->map(fn (int|string $id): int => (int) $id)
            ->all();
        $submissions = collect();

        if ($settings !== null && $invoices->isNotEmpty()) {
            $invoiceIds = $invoices->modelKeys();
            $latestIds = KsefInvoiceSubmission::query()
                ->selectRaw('MAX(id)')
                ->whereIntegerInRaw('invoice_id', $invoiceIds)
                ->where('environment', $settings->environment->value)
                ->groupBy('invoice_id');

            $submissions = KsefInvoiceSubmission::query()
                ->whereIn('id', $latestIds)
                ->get([
                    'id',
                    'invoice_id',
                    'environment',
                    'status',
                    'acquisition_date',
                    'ksef_number',
                    'ksef_status_code',
                    'safe_error_message',
                ])
                ->keyBy('invoice_id');
        }

        return [
            'currentKsefSubmissions' => $submissions,
            'ksefEnabledSeriesIds' => $enabledSeriesIds,
            'ksefListEnvironment' => $settings?->environment,
            'ksefListSendConfigured' => $settings !== null
                && $settings->is_active
                && config('ksef.invoice_submission_enabled') === true
                && $this->ksefEnvironments->allows($settings->environment),
        ];
    }

    /** @return array<string, mixed> */
    private function emptyKsefListData(): array
    {
        return [
            'currentKsefSubmissions' => collect(),
            'ksefEnabledSeriesIds' => [],
            'ksefListEnvironment' => null,
            'ksefListSendConfigured' => false,
        ];
    }

    /** @param array<string, mixed> $filters */
    private function applyFilters(Builder $query, array $filters): void
    {
        $query
            ->when(isset($filters['series_id']), fn (Builder $query) => $query->where('invoice_series_id', $filters['series_id']))
            ->when(isset($filters['number']), fn (Builder $query) => $query->where('sequence_number', $filters['number']))
            ->when(isset($filters['month']), fn (Builder $query) => $query->whereMonth('issue_date', $filters['month']))
            ->when(isset($filters['year']), fn (Builder $query) => $query->whereYear('issue_date', $filters['year']))
            ->when(isset($filters['full_number']), fn (Builder $query) => $query->where('number', 'like', $this->like($filters['full_number'])))
            ->when(isset($filters['buyer']), function (Builder $query) use ($filters): void {
                $value = $this->like($filters['buyer']);
                $query->where(function (Builder $query) use ($value): void {
                    $query->where('buyer_name_snapshot', 'like', $value)
                        ->orWhere('buyer_snapshot->name', 'like', $value)
                        ->orWhere('buyer_snapshot->company_name', 'like', $value);
                });
            })
            ->when(isset($filters['company']), fn (Builder $query) => $query->where('buyer_snapshot->company_name', 'like', $this->like($filters['company'])))
            ->when(isset($filters['tax_id']), fn (Builder $query) => $query->where('buyer_tax_id_snapshot', 'like', $this->like($filters['tax_id'])))
            ->when(isset($filters['order_id']), fn (Builder $query) => $query->where('order_id', $filters['order_id']))
            ->when(isset($filters['total_from']), fn (Builder $query) => $query->where('total_gross', '>=', $filters['total_from']))
            ->when(isset($filters['total_to']), fn (Builder $query) => $query->where('total_gross', '<=', $filters['total_to']))
            ->when(isset($filters['issue_from']), fn (Builder $query) => $query->whereDate('issue_date', '>=', $filters['issue_from']))
            ->when(isset($filters['issue_to']), fn (Builder $query) => $query->whereDate('issue_date', '<=', $filters['issue_to']))
            ->when(isset($filters['sale_from']), fn (Builder $query) => $query->whereDate('sale_date', '>=', $filters['sale_from']))
            ->when(isset($filters['sale_to']), fn (Builder $query) => $query->whereDate('sale_date', '<=', $filters['sale_to']))
            ->when(isset($filters['source']), fn (Builder $query) => $query->whereHas('order', fn (Builder $order) => $order->where('source', $filters['source'])))
            ->when(isset($filters['currency']), fn (Builder $query) => $query->where('currency', $filters['currency']));
    }

    /** @param array<string, mixed> $filters */
    private function applySorting(Builder $query, array $filters): void
    {
        $direction = $filters['direction'] ?? 'desc';

        match ($filters['sort'] ?? 'number') {
            'number' => $query->orderBy('sequence_number', $direction),
            'order' => $query->orderBy('order_id', $direction),
            'buyer' => $query->orderBy('buyer_name_snapshot', $direction),
            'gross' => $query->orderBy('total_gross', $direction),
            default => $query->orderBy('issue_date', $direction),
        };

        $query->orderBy('id', $direction);
    }

    private function like(string $value): string
    {
        return '%'.addcslashes($value, '%_\\').'%';
    }
}
