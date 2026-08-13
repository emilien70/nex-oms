@extends('layouts.app')

@section('title', $pageTitle.' - NEX-OMS')

@section('content')
    @php
        $filterValue = fn (string $key, mixed $default = '') => $filters[$key] ?? $default;
        $advancedKeys = [
            'full_number', 'buyer', 'company', 'tax_id', 'order_id', 'total_from', 'total_to',
            'issue_from', 'issue_to', 'sale_from', 'sale_to', 'source', 'currency',
        ];
        $advancedOpen = collect($advancedKeys)->contains(
            fn (string $key): bool => isset($filters[$key]) && $filters[$key] !== ''
        );
        $sortUrl = fn (string $sort, string $direction) => request()->fullUrlWithQuery([
            'sort' => $sort,
            'direction' => $direction,
            'page' => 1,
        ]);
        $listRoute = route($listRouteName);
        $bulkPdfRoute = route($bulkPdfRouteName);
        $bulkDeleteRoute = route($bulkDeleteRouteName);
    @endphp

    <style>
        .invoices-page {
            background: #f4f6f8;
            margin: -1.5rem;
            min-height: 100vh;
            padding: 16px;
        }

        .invoices-card {
            background: #fff;
            border: 1px solid #dfe4ea;
            border-radius: 7px;
            box-shadow: 0 1px 3px rgba(15, 23, 42, .08);
            overflow: visible;
        }

        .invoice-filters {
            border-bottom: 1px solid #dfe4ea;
        }

        .invoice-quick-filters {
            align-items: end;
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            min-height: 58px;
            padding: 10px 14px 12px;
        }

        .invoice-filter-field {
            min-width: 112px;
            position: relative;
        }

        .invoice-filter-field.is-series {
            min-width: 180px;
        }

        .invoice-filter-field > label {
            background: #fff;
            color: #4e565f;
            font-size: 11px;
            left: 8px;
            line-height: 1;
            padding: 0 3px;
            position: absolute;
            top: -5px;
            z-index: 1;
        }

        .invoice-filter-field .form-control,
        .invoice-filter-field .form-select {
            border-color: #cfd6df;
            border-radius: 4px;
            color: #4e565f;
            font-size: 12px;
            height: 38px;
        }

        .invoice-advanced-toggle {
            align-items: center;
            border-color: #d2d9e2;
            border-radius: 20px;
            color: #4e565f;
            display: inline-flex;
            font-size: 12px;
            gap: 7px;
            margin-left: auto;
            min-height: 38px;
            padding: 0 14px;
        }

        .invoice-advanced-toggle:hover,
        .invoice-advanced-toggle[aria-expanded="true"] {
            background: #f3f8ff;
            border-color: #b8d8ff;
            color: #0875d1;
        }

        .invoice-advanced-panel {
            border-top: 1px solid #eef1f4;
            padding: 12px 14px;
        }

        .invoice-advanced-grid {
            display: grid;
            gap: 10px 8px;
            grid-template-columns: repeat(6, minmax(150px, 1fr));
        }

        .invoice-range {
            display: grid;
            gap: 6px;
            grid-template-columns: 1fr 1fr;
        }

        .invoice-filter-actions {
            display: flex;
            gap: 8px;
            justify-content: flex-end;
            margin-top: 12px;
        }

        .invoice-filter-actions .btn {
            border-radius: 18px;
            font-size: 12px;
            min-width: 108px;
        }

        .invoices-table {
            color: #4e565f;
            font-size: 12px;
            margin: 0;
            table-layout: fixed;
        }

        .invoices-table > :not(caption) > * > * {
            border-bottom-color: #d1d5dc;
            padding: 8px 10px;
            vertical-align: middle;
        }

        .invoices-table thead th {
            background: #fff;
            color: #4e565f;
            font-size: 11px;
            font-weight: 600;
            height: 54px;
            text-transform: uppercase;
        }

        .invoice-row-number {
            color: #111827;
            font-weight: 700;
            text-decoration: none;
        }

        .invoice-row-number:hover {
            color: #0875d1;
        }

        .invoice-order-link {
            color: #0875d1;
            text-decoration: none;
        }

        .invoice-order-link:hover {
            text-decoration: underline;
        }

        .invoice-money,
        .invoice-date {
            text-align: right;
            white-space: nowrap;
        }

        .invoice-action-group {
            align-items: center;
            display: flex;
            gap: 5px;
            justify-content: flex-end;
        }

        .invoice-icon-button {
            align-items: center;
            background: #fff;
            border: 1px solid #edf0f3;
            border-radius: 50%;
            color: #53606d;
            display: inline-flex;
            height: 30px;
            justify-content: center;
            padding: 0;
            text-decoration: none;
            width: 30px;
        }

        .invoice-icon-button:hover {
            background: #f4f8fc;
            border-color: #d7dee7;
            color: #0875d1;
        }

        .invoice-icon-button.is-delete:hover {
            background: #dc3545;
            border-color: #dc3545;
            color: #fff;
        }

        .invoice-correction-button {
            align-items: center;
            background: #fff;
            border: 1px solid #cfd6df;
            border-radius: 4px;
            color: #4e565f;
            cursor: pointer;
            display: inline-flex;
            font-size: 10px;
            justify-content: center;
            padding: 5px 12px;
            text-decoration: none;
        }

        .invoice-correction-button:hover,
        .invoice-correction-button:focus {
            background: #f1f7fd;
            border-color: #9fc7ed;
            color: #0875d1;
        }

        .invoice-correction-button:disabled {
            background: #fff;
            color: #94a3b8;
            cursor: default;
        }

        .invoice-list-footer {
            align-items: center;
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            min-height: 52px;
            padding: 8px 12px;
        }

        .invoice-list-footer .btn {
            font-size: 11px;
        }

        .invoice-bulk-actions > .btn,
        .invoice-bulk-actions > .btn-group > .btn {
            align-items: center;
            background: #fff;
            border-color: #cfd6df;
            color: #26313d;
            display: inline-flex;
            gap: 4px;
            min-height: 30px;
            padding: 4px 8px;
            white-space: nowrap;
        }

        .invoice-bulk-actions > .btn:hover,
        .invoice-bulk-actions > .btn-group > .btn:hover,
        .invoice-bulk-actions > .btn-group > .btn[aria-expanded="true"] {
            background: #f4f7fa;
            border-color: #b9c3cf;
            color: #0875d1;
        }

        .invoice-bulk-actions > .btn:disabled,
        .invoice-bulk-actions > .btn-group > .btn:disabled {
            background: #fff;
            color: #7b8490;
            opacity: .62;
        }

        .invoice-sort-menu {
            min-width: 290px;
            padding: 8px;
        }

        .invoice-sort-heading {
            color: #111827;
            font-size: 10px;
            font-weight: 700;
            padding: 5px 10px;
            text-transform: uppercase;
        }

        .invoice-sort-menu .dropdown-item {
            border-radius: 2px;
            color: #4e565f;
            font-size: 12px;
            padding: 7px 12px;
        }

        .invoice-sort-menu .dropdown-item.active {
            background: #eef6ff;
            color: #0875d1;
        }

        .invoice-empty {
            color: #64748b;
            padding: 44px 20px !important;
            text-align: center;
        }

        @media (max-width: 1199.98px) {
            .invoice-advanced-grid {
                grid-template-columns: repeat(3, minmax(160px, 1fr));
            }
        }

        @media (max-width: 767.98px) {
            .invoices-page {
                margin: -1rem;
                padding: 10px;
            }

            .invoice-advanced-grid {
                grid-template-columns: 1fr;
            }

            .invoice-advanced-toggle {
                margin-left: 0;
            }

            .invoice-list-footer .nex-pagination-toolbar {
                flex-basis: 100%;
                margin-top: 6px;
            }
        }
    </style>

    <div class="invoices-page">
        @include('invoices._navigation')

        @if ($errors->any())
            <div class="alert alert-danger py-2 px-3 mb-2" role="alert">
                {{ $errors->first() }}
            </div>
        @endif

        @if (session('success'))
            <div class="alert alert-success py-2 px-3 mb-2" role="status">
                {{ session('success') }}
            </div>
        @endif

        <section class="invoices-card">
            <form class="invoice-filters" method="GET" action="{{ $listRoute }}">
                <div class="invoice-quick-filters">
                    <div class="invoice-filter-field is-series">
                        <label for="invoice_series_id">Seria numeracji</label>
                        <select id="invoice_series_id" class="form-select form-select-sm" name="series_id" data-auto-submit-filter>
                            <option value="">Dowolna</option>
                            @foreach ($series as $item)
                                <option value="{{ $item->id }}" @selected((string) $filterValue('series_id') === (string) $item->id)>{{ $item->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="invoice-filter-field">
                        <label for="invoice_number">Numer</label>
                        <input id="invoice_number" class="form-control form-control-sm" type="number" min="1" name="number" value="{{ $filterValue('number') }}">
                    </div>
                    <div class="invoice-filter-field">
                        <label for="invoice_month">Miesiąc</label>
                        <select id="invoice_month" class="form-select form-select-sm" name="month" data-auto-submit-filter>
                            <option value="">Dowolny</option>
                            @foreach ($months as $number => $name)
                                <option value="{{ $number }}" @selected((string) $filterValue('month') === (string) $number)>{{ $name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="invoice-filter-field">
                        <label for="invoice_year">Rok</label>
                        <select id="invoice_year" class="form-select form-select-sm" name="year" data-auto-submit-filter>
                            <option value="">Dowolny</option>
                            @foreach ($years as $year)
                                <option value="{{ $year }}" @selected((string) $filterValue('year') === (string) $year)>{{ $year }}</option>
                            @endforeach
                        </select>
                    </div>
                    <button
                        class="btn btn-sm btn-outline-secondary invoice-advanced-toggle"
                        type="button"
                        data-bs-toggle="collapse"
                        data-bs-target="#invoiceAdvancedFilters"
                        aria-expanded="{{ $advancedOpen ? 'true' : 'false' }}"
                        aria-controls="invoiceAdvancedFilters"
                    >
                        <i class="bi bi-filter-circle" aria-hidden="true"></i>
                        Wyszukiwanie zaawansowane
                        <i class="bi bi-chevron-down" aria-hidden="true"></i>
                    </button>
                </div>

                <div id="invoiceAdvancedFilters" class="collapse {{ $advancedOpen ? 'show' : '' }}">
                    <div class="invoice-advanced-panel">
                        <div class="invoice-advanced-grid">
                            <div class="invoice-filter-field"><label for="invoice_full_number">{{ $fullNumberLabel }}</label><input id="invoice_full_number" class="form-control form-control-sm" name="full_number" value="{{ $filterValue('full_number') }}"></div>
                            <div class="invoice-filter-field"><label for="invoice_buyer">Nabywca</label><input id="invoice_buyer" class="form-control form-control-sm" name="buyer" value="{{ $filterValue('buyer') }}"></div>
                            <div class="invoice-filter-field"><label for="invoice_company">Firma</label><input id="invoice_company" class="form-control form-control-sm" name="company" value="{{ $filterValue('company') }}"></div>
                            <div class="invoice-filter-field"><label for="invoice_tax_id">NIP</label><input id="invoice_tax_id" class="form-control form-control-sm" name="tax_id" value="{{ $filterValue('tax_id') }}"></div>
                            <div class="invoice-filter-field"><label for="invoice_order_id">ID zamówienia</label><input id="invoice_order_id" class="form-control form-control-sm" type="number" min="1" name="order_id" value="{{ $filterValue('order_id') }}"></div>
                            <div class="invoice-filter-field"><label>Łączna cena</label><div class="invoice-range"><input class="form-control form-control-sm" type="number" step="0.01" @if (! $isCorrectionList) min="0" @endif name="total_from" value="{{ $filterValue('total_from') }}" aria-label="Łączna cena od"><input class="form-control form-control-sm" type="number" step="0.01" @if (! $isCorrectionList) min="0" @endif name="total_to" value="{{ $filterValue('total_to') }}" aria-label="Łączna cena do"></div></div>
                            <div class="invoice-filter-field"><label>Data wystawienia</label><div class="invoice-range"><input class="form-control form-control-sm" type="date" name="issue_from" value="{{ $filterValue('issue_from') }}" aria-label="Data wystawienia od"><input class="form-control form-control-sm" type="date" name="issue_to" value="{{ $filterValue('issue_to') }}" aria-label="Data wystawienia do"></div></div>
                            <div class="invoice-filter-field"><label>Data sprzedaży</label><div class="invoice-range"><input class="form-control form-control-sm" type="date" name="sale_from" value="{{ $filterValue('sale_from') }}" aria-label="Data sprzedaży od"><input class="form-control form-control-sm" type="date" name="sale_to" value="{{ $filterValue('sale_to') }}" aria-label="Data sprzedaży do"></div></div>
                            <div class="invoice-filter-field"><label for="invoice_source">Źródło zamówienia</label><select id="invoice_source" class="form-select form-select-sm" name="source"><option value="">Dowolne</option><option value="manual" @selected($filterValue('source') === 'manual')>Ręczne</option><option value="allegro" @selected($filterValue('source') === 'allegro')>Allegro</option><option value="prestashop" @selected($filterValue('source') === 'prestashop')>PrestaShop</option></select></div>
                            <div class="invoice-filter-field"><label for="invoice_currency">Waluta</label><select id="invoice_currency" class="form-select form-select-sm" name="currency"><option value="">Dowolna</option>@foreach ($currencies as $currency)<option value="{{ $currency }}" @selected($filterValue('currency') === $currency)>{{ $currency }}</option>@endforeach</select></div>
                        </div>
                        <div class="invoice-filter-actions">
                            <a class="btn btn-sm btn-outline-secondary" href="{{ $listRoute }}">Wyczyść filtry</a>
                            <button class="btn btn-sm btn-primary" type="submit">Ustaw filtry</button>
                        </div>
                    </div>
                </div>

                <input type="hidden" name="sort" value="{{ $filterValue('sort', 'number') }}">
                <input type="hidden" name="direction" value="{{ $filterValue('direction', 'desc') }}">
                <input type="hidden" name="per_page" value="{{ $perPage }}">
            </form>

            <form id="bulkInvoicePrintForm" method="POST" action="{{ $bulkPdfRoute }}" target="_blank">
                @csrf
                <input type="hidden" name="selection" value="[]" data-bulk-print-selection>
            </form>

            <form id="bulkInvoiceDeleteForm" method="POST" action="{{ $bulkDeleteRoute }}">
                @csrf
                @method('DELETE')
                <input type="hidden" name="selection" value="{}" data-bulk-delete-selection>
            </form>

            <div class="table-responsive">
                <table class="table invoices-table align-middle">
                    <colgroup>
                        <col style="width: 42px;">
                        <col style="width: 150px;">
                        <col style="width: 120px;">
                        <col>
                        <col style="width: 135px;">
                        <col style="width: 105px;">
                        @if ($isInvoiceList)
                            <col style="width: 105px;">
                        @endif
                        <col style="width: 128px;">
                    </colgroup>
                    <thead>
                        <tr>
                            <th><input class="form-check-input" type="checkbox" data-invoice-select-all aria-label="Zaznacz wszystkie {{ $documentNamePlural }} na stronie"></th>
                            <th>Numer</th>
                            <th>Zamówienie</th>
                            <th>Nabywca</th>
                            <th class="text-end">Suma brutto</th>
                            <th class="text-end">Data</th>
                            @if ($isInvoiceList)
                                <th class="text-center">Korekta</th>
                            @endif
                            <th class="text-end">Akcje</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($invoices as $invoice)
                            @php
                                $buyer = $invoice->buyer_snapshot ?? [];
                                $buyerName = $buyer['company_name'] ?? $buyer['name'] ?? $invoice->buyer_name_snapshot ?? '—';
                                $orderNumber = $invoice->order_id ?? $invoice->order_reference_snapshot;
                                $deleteBlockedMessage = match (true) {
                                    $invoice->isFinalized() => $invoice->isCorrection()
                                        ? 'Korekta została zamknięta i nie może zostać usunięta.'
                                        : 'Dokument został zamknięty i nie może zostać usunięty.',
                                    $isProformaList && ($invoice->proforma_superseded_at !== null || $invoice->superseded_by_invoice_id !== null) => 'Do Pro Forma została już wystawiona Faktura VAT.',
                                    default => null,
                                };
                                $currentCorrection = $isInvoiceList
                                    ? $invoice->corrections->first(fn ($correction) => ! $correction->isFinalized())
                                    : null;
                                $documentEditRouteParameters = match (true) {
                                    $isCorrectionList => ['correction' => $invoice, ...$returnContext->parameters()],
                                    $isInvoiceList => ['invoice' => $invoice, ...$returnContext->parameters()],
                                    default => $invoice,
                                };
                            @endphp
                            <tr>
                                <td><input class="form-check-input" type="checkbox" data-invoice-checkbox data-invoice-id="{{ $invoice->id }}" data-lock-version="{{ $invoice->lock_version }}" @if ($deleteBlockedMessage) data-delete-blocked-message="{{ $deleteBlockedMessage }}" @endif aria-label="Zaznacz {{ $documentName }} {{ $invoice->number }}"></td>
                                <td><a class="invoice-row-number" href="{{ route('invoices.pdf', $invoice) }}" target="_blank" rel="noopener" title="Otwórz PDF {{ $documentName }}">{{ $invoice->number }}</a></td>
                                <td>
                                    @if ($invoice->order)
                                        <a class="invoice-order-link" href="{{ route('orders.show', $invoice->order) }}">{{ $orderNumber }}</a>
                                    @else
                                        {{ $orderNumber ?: '—' }}
                                    @endif
                                </td>
                                <td>{{ $buyerName }}</td>
                                <td class="invoice-money">{{ $moneyFormatter->format($invoice->total_gross) }} {{ $invoice->currency }}</td>
                                <td class="invoice-date">{{ $invoice->issue_date?->format('d.m.Y') ?? '—' }}</td>
                                @if ($isInvoiceList)
                                    <td class="text-center">
                                        @if ($currentCorrection)
                                            <a class="invoice-correction-button" href="{{ route('invoices.corrections.edit', ['correction' => $currentCorrection, ...$returnContext->parameters()]) }}" title="Edytuj Korektę {{ $currentCorrection->number }}">KOREKTA</a>
                                        @elseif ($correctionSeries->isEmpty())
                                            <button class="invoice-correction-button" type="button" disabled title="Brak aktywnej serii numeracji dla Korekt">KOREKTA</button>
                                        @elseif ($correctionSeries->count() === 1)
                                            <a class="invoice-correction-button" href="{{ route('invoices.corrections.create', ['invoice' => $invoice, 'series_id' => $correctionSeries->first()->id, ...$returnContext->parameters()]) }}">KOREKTA</a>
                                        @else
                                            <button class="invoice-correction-button" type="button" data-bs-toggle="modal" data-bs-target="#invoiceListCorrectionSeriesModal" data-correction-url="{{ route('invoices.corrections.create', $invoice) }}">KOREKTA</button>
                                        @endif
                                    </td>
                                @endif
                                <td>
                                    <div class="invoice-action-group">
                                        <a class="invoice-icon-button" href="{{ route('invoices.pdf', $invoice) }}" target="_blank" rel="noopener" title="Drukuj {{ $documentName }}" aria-label="Drukuj {{ $documentName }} {{ $invoice->number }}"><i class="bi bi-printer" aria-hidden="true"></i></a>
                                        @if ($documentEditRouteName !== null)
                                            <a class="invoice-icon-button" href="{{ route($documentEditRouteName, $documentEditRouteParameters) }}" title="Edytuj {{ $documentName }}" aria-label="Edytuj {{ $documentName }} {{ $invoice->number }}"><i class="bi bi-pencil" aria-hidden="true"></i></a>
                                        @endif
                                        <form method="POST" action="{{ route('invoices.destroy', $invoice) }}" data-invoice-delete-form data-confirm-message="Czy na pewno chcesz usunąć {{ $documentName }} {{ $invoice->number }}?" @if ($deleteBlockedMessage) data-delete-blocked-message="{{ $deleteBlockedMessage }}" @endif>
                                            @csrf
                                            @method('DELETE')
                                            <input type="hidden" name="expected_lock_version" value="{{ $invoice->lock_version }}">
                                            <input type="hidden" name="return_to" value="{{ $returnContext->returnTo() }}">
                                            <input type="hidden" name="return_query" value="{{ $returnContext->query() }}">
                                            <button class="invoice-icon-button is-delete" type="submit" title="Usuń {{ $documentName }}" aria-label="Usuń {{ $documentName }} {{ $invoice->number }}"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr><td class="invoice-empty" colspan="{{ $isInvoiceList ? 8 : 7 }}">Nie znaleziono {{ $documentNamePlural }} spełniających wybrane kryteria.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <footer class="invoice-list-footer">
                <div class="btn-group invoice-bulk-actions" role="group" aria-label="Operacje zbiorcze {{ $documentNamePlural }}">
                    <button class="btn btn-sm btn-outline-secondary" type="button" data-select-all-button>
                        <i class="bi bi-check-square" aria-hidden="true"></i>
                        ZAZNACZ WSZYSTKO
                    </button>
                    <button class="btn btn-sm btn-outline-secondary" type="submit" form="bulkInvoicePrintForm" data-bulk-print disabled>
                        <i class="bi bi-printer" aria-hidden="true"></i>
                        DRUKUJ ZAZNACZONE
                    </button>
                    @if ($showSalesRegisterAction)
                        <button
                            class="btn btn-sm btn-outline-secondary"
                            type="button"
                            disabled
                            title="Rejestr sprzedaży nie jest jeszcze dostępny"
                            aria-label="Rejestr sprzedaży nie jest jeszcze dostępny"
                        >
                            <i class="bi bi-file-earmark-text" aria-hidden="true"></i>
                            REJESTR SPRZEDAŻY
                            <i class="bi bi-chevron-down" aria-hidden="true"></i>
                        </button>
                    @endif
                    <button
                        class="btn btn-sm btn-outline-secondary"
                        type="submit"
                        form="bulkInvoiceDeleteForm"
                        data-bulk-delete
                        disabled
                    >
                        <i class="bi bi-trash" aria-hidden="true"></i>
                        USUŃ ZAZNACZONE
                    </button>
                    <div class="btn-group" role="group">
                        <button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">SORTOWANIE</button>
                        <div class="dropdown-menu invoice-sort-menu">
                            <div class="invoice-sort-heading">Sortuj według</div>
                            @foreach (['number' => $numberSortLabel, 'order' => 'ID zamówienia', 'issue_date' => 'Data wystawienia', 'buyer' => 'Nabywca', 'gross' => 'Suma brutto'] as $sort => $label)
                                <a class="dropdown-item {{ $filterValue('sort', 'number') === $sort ? 'active' : '' }}" href="{{ $sortUrl($sort, $filterValue('direction', 'desc')) }}">{{ $label }}</a>
                            @endforeach
                            <div class="dropdown-divider"></div>
                            <div class="invoice-sort-heading">Kierunek</div>
                            <a class="dropdown-item {{ $filterValue('direction', 'desc') === 'desc' ? 'active' : '' }}" href="{{ $sortUrl($filterValue('sort', 'number'), 'desc') }}"><i class="bi bi-sort-down me-2" aria-hidden="true"></i>Malejąco</a>
                            <a class="dropdown-item {{ $filterValue('direction', 'desc') === 'asc' ? 'active' : '' }}" href="{{ $sortUrl($filterValue('sort', 'number'), 'asc') }}"><i class="bi bi-sort-up me-2" aria-hidden="true"></i>Rosnąco</a>
                        </div>
                    </div>
                </div>
                <span class="visually-hidden" aria-live="polite" data-selection-status>Nie zaznaczono {{ $documentNamePlural }}</span>

                <x-pagination-toolbar
                    :paginator="$invoices"
                    :per-page-options="$perPageOptions"
                    :per-page="$perPage"
                    aria-label="Paginacja {{ $documentNamePlural }}"
                />
            </footer>
        </section>
    </div>

    @if ($isInvoiceList)
        @include('invoices.partials.correction-series-modal', [
            'correctionSeries' => $correctionSeries,
            'modalId' => 'invoiceListCorrectionSeriesModal',
            'returnContext' => $returnContext,
        ])
    @endif

    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const checkboxes = Array.from(document.querySelectorAll('[data-invoice-checkbox]'));
            const selectAll = document.querySelector('[data-invoice-select-all]');
            const selectAllButton = document.querySelector('[data-select-all-button]');
            const printButton = document.querySelector('[data-bulk-print]');
            const deleteButton = document.querySelector('[data-bulk-delete]');
            const status = document.querySelector('[data-selection-status]');
            const printForm = document.getElementById('bulkInvoicePrintForm');
            const deleteForm = document.getElementById('bulkInvoiceDeleteForm');
            const printSelection = printForm?.querySelector('[data-bulk-print-selection]');
            const deleteSelection = deleteForm?.querySelector('[data-bulk-delete-selection]');
            const documentNamePlural = @json($documentNamePlural);

            const showDeleteBlockedMessage = (message) => {
                if (typeof window.nexOmsShowError === 'function') {
                    window.nexOmsShowError(message);

                    return;
                }

                window.alert(message);
            };

            document.querySelectorAll('[data-auto-submit-filter]').forEach((filter) => {
                filter.addEventListener('change', () => filter.form?.requestSubmit());
            });

            const updateSelection = () => {
                const checked = checkboxes.filter((checkbox) => checkbox.checked).length;
                if (selectAll) {
                    selectAll.checked = checkboxes.length > 0 && checked === checkboxes.length;
                    selectAll.indeterminate = checked > 0 && checked < checkboxes.length;
                }
                if (printButton) {
                    printButton.disabled = checked === 0;
                }
                if (deleteButton) {
                    deleteButton.disabled = checked === 0;
                }
                if (status) {
                    status.textContent = checked === 0
                        ? `Nie zaznaczono ${documentNamePlural}`
                        : `Zaznaczono: ${checked}`;
                }
            };

            const setAll = (checked) => {
                checkboxes.forEach((checkbox) => {
                    checkbox.checked = checked;
                });
                updateSelection();
            };

            const selectedCheckboxes = () => checkboxes.filter((checkbox) => checkbox.checked);

            selectAll?.addEventListener('change', () => setAll(selectAll.checked));
            selectAllButton?.addEventListener('click', () => setAll(!checkboxes.every((checkbox) => checkbox.checked)));
            checkboxes.forEach((checkbox) => checkbox.addEventListener('change', updateSelection));
            document.querySelectorAll('[data-invoice-delete-form]').forEach((form) => {
                form.addEventListener('submit', (event) => {
                    if (form.dataset.deleteBlockedMessage) {
                        event.preventDefault();
                        showDeleteBlockedMessage(form.dataset.deleteBlockedMessage);

                        return;
                    }

                    if (!window.confirm(form.dataset.confirmMessage)) {
                        event.preventDefault();
                    }
                });
            });
            printForm?.addEventListener('submit', (event) => {
                const selected = selectedCheckboxes();

                if (selected.length === 0) {
                    event.preventDefault();
                    window.alert(`Zaznacz co najmniej jedną pozycję z listy ${documentNamePlural}.`);

                    return;
                }

                printSelection.value = JSON.stringify(
                    selected.map((checkbox) => Number.parseInt(checkbox.dataset.invoiceId, 10))
                );
            });
            deleteForm?.addEventListener('submit', (event) => {
                const selected = selectedCheckboxes();

                if (selected.length === 0) {
                    event.preventDefault();
                    window.alert(`Zaznacz co najmniej jedną pozycję z listy ${documentNamePlural}.`);

                    return;
                }

                const blockedCheckbox = selected.find((checkbox) => checkbox.dataset.deleteBlockedMessage);

                if (blockedCheckbox) {
                    event.preventDefault();
                    showDeleteBlockedMessage(blockedCheckbox.dataset.deleteBlockedMessage);

                    return;
                }

                if (!window.confirm(`Czy na pewno chcesz usunąć zaznaczone ${documentNamePlural}?`)) {
                    event.preventDefault();

                    return;
                }

                deleteSelection.value = JSON.stringify(Object.fromEntries(
                    selected.map((checkbox) => [
                        checkbox.dataset.invoiceId,
                        Number.parseInt(checkbox.dataset.lockVersion, 10),
                    ])
                ));
            });
            updateSelection();
        });
    </script>
@endsection
