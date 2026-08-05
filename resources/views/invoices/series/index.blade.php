@extends('layouts.app')

@section('title', 'Serie numeracji - NEX-OMS')

@section('content')
    <style>
        .invoice-series-page {
            background: #f4f6f8;
            margin: -1.5rem;
            min-height: 100vh;
            padding: 24px;
        }

        .invoice-series-info {
            background: #ffffff;
            border: 1px solid #dfe4ea;
            border-left: 3px solid #0d6efd;
            border-radius: 7px;
            color: #4e565f;
            font-size: 13px;
            margin-bottom: 16px;
            padding: 13px 16px;
        }

        .invoice-series-panel {
            background: #ffffff;
            border: 1px solid #dfe4ea;
            border-radius: 8px;
            box-shadow: 0 1px 3px rgba(15, 23, 42, .06);
            overflow: visible;
        }

        .invoice-series-header {
            align-items: center;
            border-bottom: 1px solid #e5e7eb;
            display: flex;
            gap: 16px;
            justify-content: space-between;
            min-height: 58px;
            padding: 12px 16px;
        }

        .invoice-series-title {
            color: #111827;
            font-size: 17px;
            font-weight: 700;
            margin: 0;
        }

        .invoice-series-new {
            align-items: center;
            display: inline-flex;
            gap: 6px;
        }

        .invoice-series-modal-loading {
            align-items: center;
            color: #64748b;
            display: flex;
            font-size: 13px;
            gap: 9px;
            justify-content: center;
            min-height: 180px;
        }

        #invoiceSeriesModal .modal-dialog {
            max-width: 960px;
            width: calc(100% - 32px);
        }

        #invoiceSeriesModal .modal-content {
            border: 0;
            border-radius: 8px;
        }

        #invoiceSeriesModal .modal-header {
            border-bottom: 0;
            min-height: 66px;
            padding: 18px 32px 14px;
        }

        #invoiceSeriesModal .modal-title {
            color: #1f2937;
            font-size: 16px !important;
            font-weight: 600;
        }

        #invoiceSeriesModal .modal-body {
            padding: 0 32px 24px;
        }

        .invoice-series-form-layout {
            margin: 0 auto;
            max-width: 886px;
        }

        #invoiceSeriesModal [data-series-type-picker] {
            align-items: center;
            column-gap: 18px;
            display: grid;
            grid-template-columns: 200px minmax(0, 1fr);
            margin: 12px auto;
            max-width: 886px;
        }

        .invoice-series-system-note {
            align-items: flex-start;
            color: #64748b;
            display: flex;
            font-size: 12px;
            gap: 7px;
            line-height: 1.4;
        }

        .invoice-series-next-stage-note {
            background: #f8fafc;
            border: 1px solid #e5e7eb;
            border-radius: 7px;
            color: #64748b;
            font-size: 12px;
            margin-top: 16px;
            padding: 10px 12px;
        }

        .invoice-series-readiness-note {
            align-items: flex-start;
            background: transparent;
            border: 0;
            color: #64748b;
            display: flex;
            font-size: 12px;
            gap: 8px;
            margin: 0 !important;
            padding: 10px 0 12px 218px;
        }

        .invoice-series-sections {
            margin-top: 0;
        }

        .invoice-series-form-section {
            border: 0;
            border-top: 1px solid #d8dee6;
            padding: 14px 0;
        }

        .invoice-series-form-section h3 {
            height: 1px;
            margin: -1px;
            overflow: hidden;
            padding: 0;
            position: absolute;
            width: 1px;
            clip: rect(0, 0, 0, 0);
            white-space: nowrap;
        }

        .invoice-series-form-layout > .row,
        .invoice-series-form-section > .row {
            display: block;
            margin: 0;
        }

        .invoice-series-form-layout > .row > [class*="col-"],
        .invoice-series-form-section > .row > [class*="col-"] {
            align-items: center;
            column-gap: 18px;
            display: grid;
            grid-template-columns: 200px minmax(0, 1fr);
            margin: 0;
            max-width: none;
            padding: 6px 0;
            width: 100%;
        }

        .invoice-series-form-layout > .row > .d-flex:not(.d-none),
        .invoice-series-form-section > .row > .d-flex:not(.d-none) {
            display: grid !important;
        }

        .invoice-series-form-layout > .row > .d-none,
        .invoice-series-form-section > .row > .d-none {
            display: none !important;
        }

        .invoice-series-form-section--single {
            align-items: start;
            column-gap: 18px;
            display: grid;
            grid-template-columns: 200px minmax(0, 1fr);
        }

        .invoice-series-form-section--single > .form-label {
            grid-column: 1;
        }

        .invoice-series-form-section--single > .form-control,
        .invoice-series-form-section--single > .form-select {
            grid-column: 2;
        }

        .invoice-series-form-section--single > .form-text,
        .invoice-series-form-section--single > .invalid-feedback {
            grid-column: 2;
        }

        .invoice-series-form-break {
            border-top: 1px solid #d8dee6;
            margin-top: 10px !important;
            padding-top: 18px !important;
        }

        #invoiceSeriesModal .form-control,
        #invoiceSeriesModal .form-select {
            color: #4e565f;
            font-size: 13px;
            min-height: 39px;
            padding: 8px 12px;
        }

        #invoiceSeriesModal textarea.form-control {
            min-height: 112px;
        }

        #invoiceSeriesModal input[type="file"].form-control {
            padding: 7px 10px;
        }

        #invoiceSeriesModal .form-text,
        #invoiceSeriesModal .invalid-feedback {
            grid-column: 2;
            line-height: 1.5;
            margin-top: 5px;
        }

        #invoiceSeriesModal .form-check,
        #invoiceSeriesModal .invoice-series-system-note,
        #invoiceSeriesModal .invoice-series-current-logo {
            grid-column: 2;
        }

        #invoiceSeriesModal .form-check {
            align-items: center;
            display: flex;
            min-height: 32px;
        }

        #invoiceSeriesModal .form-check .invalid-feedback {
            margin-left: 8px;
        }

        .invoice-series-modal-footer {
            align-items: center;
            border-top: 1px solid #d8dee6;
            justify-content: space-between;
            min-height: 70px;
            padding: 14px 32px;
        }

        .invoice-series-record-id {
            background: #198754;
            border-radius: 20px;
            color: #ffffff;
            font-size: 12px;
            font-weight: 600;
            padding: 9px 18px;
        }

        .invoice-series-modal-actions {
            align-items: center;
            display: flex;
            gap: 10px;
            margin-left: auto;
        }

        .invoice-series-modal-actions .btn {
            border-radius: 20px;
            min-width: 88px;
            padding: 8px 18px;
        }

        .invoice-series-current-logo {
            align-items: center;
            color: #64748b;
            display: flex;
            font-size: 12px;
            gap: 7px;
        }

        #invoiceSeriesModal .form-label {
            color: #4e565f;
            font-size: 12px;
            margin: 0;
            text-align: right;
        }

        #invoiceSeriesModal .form-text,
        #invoiceSeriesModal .form-check-label {
            font-size: 12px;
        }

        .invoice-series-table-wrap {
            overflow-x: auto;
        }

        .invoice-series-table {
            font-size: 13px;
            margin: 0;
            min-width: 760px;
        }

        .invoice-series-table th {
            background: #f8fafc;
            border-bottom-color: #dfe4ea;
            color: #4e565f;
            font-size: 11px;
            font-weight: 600;
            padding: 11px 14px;
            text-transform: uppercase;
        }

        .invoice-series-table td {
            border-bottom-color: #e8edf2;
            color: #4e565f;
            padding: 11px 14px;
            vertical-align: middle;
        }

        .invoice-series-table tbody tr:last-child td {
            border-bottom: 0;
        }

        .invoice-series-name {
            align-items: center;
            color: #1f2937;
            display: inline-flex;
            gap: 7px;
            font-weight: 500;
        }

        .invoice-system-star {
            color: #64748b;
            cursor: default;
            display: inline-flex;
            font-size: 14px;
        }

        .invoice-series-action,
        .invoice-series-action-disabled {
            align-items: center;
            border: 1px solid #dfe4ea;
            border-radius: 50%;
            display: inline-flex;
            height: 30px;
            justify-content: center;
            padding: 0;
            width: 30px;
        }

        .invoice-series-numbering-action {
            align-items: center;
            display: inline-flex;
            font-size: 12px;
            gap: 6px;
            white-space: nowrap;
        }

        .invoice-numbering-series-summary,
        .invoice-numbering-preview-grid {
            display: grid;
            gap: 8px 16px;
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }

        .invoice-numbering-series-summary {
            background: #f8fafc;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            padding: 12px;
        }

        .invoice-numbering-series-summary div,
        .invoice-numbering-preview-grid div {
            display: flex;
            flex-direction: column;
            gap: 2px;
        }

        .invoice-numbering-series-summary span,
        .invoice-numbering-preview-grid span {
            color: #6b7280;
            font-size: 11px;
        }

        .invoice-numbering-series-summary strong,
        .invoice-numbering-preview-grid strong {
            color: #1f2937;
            font-size: 13px;
            font-weight: 600;
        }

        .invoice-numbering-preview-grid {
            border-bottom: 1px solid #e5e7eb;
            border-top: 1px solid #e5e7eb;
            padding: 12px 0;
        }

        .invoice-series-action {
            background: #ffffff;
            color: #4e565f;
        }

        .invoice-series-action:hover {
            background: #f8fafc;
            border-color: #bfc8d4;
            color: #0d6efd;
        }

        .invoice-series-action-disabled {
            background: #f8fafc;
            color: #b4bdc8;
            cursor: default;
        }

        .invoice-series-footer {
            align-items: center;
            border-top: 1px solid #e5e7eb;
            display: flex;
            gap: 16px;
            justify-content: space-between;
            min-height: 54px;
            padding: 10px 14px;
        }

        .invoice-series-count {
            color: #6b7280;
            font-size: 12px;
        }

        .invoice-series-empty {
            color: #6b7280;
            font-size: 13px;
            padding: 32px 16px;
            text-align: center;
        }

        @media (max-width: 767.98px) {
            .invoice-series-page {
                margin: -1rem;
                padding: 14px;
            }

            .invoice-series-header {
                align-items: flex-start;
                flex-direction: column;
            }

            .invoice-series-footer {
                align-items: flex-start;
                flex-direction: column;
            }

            .invoice-numbering-series-summary,
            .invoice-numbering-preview-grid {
                grid-template-columns: 1fr;
            }

            #invoiceSeriesModal .modal-dialog {
                margin: 8px auto;
                width: calc(100% - 16px);
            }

            #invoiceSeriesModal .modal-header,
            #invoiceSeriesModal .modal-body,
            .invoice-series-modal-footer {
                padding-left: 16px;
                padding-right: 16px;
            }

            .invoice-series-form-layout > .row > [class*="col-"],
            .invoice-series-form-section > .row > [class*="col-"],
            .invoice-series-form-section--single,
            #invoiceSeriesModal [data-series-type-picker] {
                display: grid;
                grid-template-columns: minmax(0, 1fr);
                row-gap: 5px;
            }

            #invoiceSeriesModal .form-label,
            .invoice-series-form-section--single > .form-label,
            .invoice-series-form-section--single > .form-control,
            .invoice-series-form-section--single > .form-select,
            .invoice-series-form-section--single > .form-text,
            .invoice-series-form-section--single > .invalid-feedback,
            #invoiceSeriesModal .form-text,
            #invoiceSeriesModal .invalid-feedback,
            #invoiceSeriesModal .form-check,
            #invoiceSeriesModal .invoice-series-system-note,
            #invoiceSeriesModal .invoice-series-current-logo {
                grid-column: 1;
                text-align: left;
            }

            .invoice-series-readiness-note {
                padding-left: 0;
            }

            .invoice-series-modal-footer {
                align-items: stretch;
                flex-direction: column;
            }

            .invoice-series-record-id {
                align-self: flex-start;
            }

            .invoice-series-modal-actions {
                justify-content: flex-end;
                margin-left: 0;
            }
        }
    </style>

    <div class="invoice-series-page">
        @include('invoices._navigation')

        <div class="invoice-series-info" role="note">
            <i class="bi bi-info-circle me-2" aria-hidden="true"></i>
            Serie systemowe są zawsze aktywne i oznaczone pustą gwiazdką. Dodatkowe serie można aktywować, ukrywać i bezpiecznie usuwać.
        </div>

        <section class="invoice-series-panel" aria-labelledby="invoice-series-title">
            <header class="invoice-series-header">
                <h1 class="invoice-series-title" id="invoice-series-title">Serie numeracji</h1>
                <button
                    class="btn btn-success btn-sm invoice-series-new"
                    type="button"
                    data-role="new-series"
                    data-series-create
                >
                    <i class="bi bi-plus-circle" aria-hidden="true"></i>
                    Nowa seria numeracji
                </button>
            </header>

            @if ($series->isEmpty())
                <div class="invoice-series-empty">Nie znaleziono serii numeracji.</div>
            @else
                <div class="invoice-series-table-wrap">
                    <table class="table invoice-series-table">
                        <thead>
                            <tr>
                                <th scope="col">Rodzaj</th>
                                <th scope="col">Nazwa</th>
                                <th scope="col">Format</th>
                                <th class="text-center" scope="col">Numeracja</th>
                                <th class="text-center" scope="col">Pokaż/ukryj</th>
                                <th class="text-center" scope="col">Edytuj</th>
                                <th class="text-center" scope="col">Usuń</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($series as $item)
                                <tr data-series-row="{{ $item->id }}">
                                    <td>{{ $item->document_type->label() }}</td>
                                    <td>
                                        <span class="invoice-series-name">
                                            @if ($item->is_system)
                                                <span
                                                    class="invoice-system-star"
                                                    data-role="system-series-marker"
                                                    data-series-id="{{ $item->id }}"
                                                    title="Predefiniowana seria systemowa"
                                                    aria-label="Predefiniowana seria systemowa"
                                                ><i class="bi bi-star" aria-hidden="true"></i></span>
                                            @endif
                                            {{ $item->name }}
                                        </span>
                                    </td>
                                    <td>{{ $item->number_format }}</td>
                                    <td class="text-center">
                                        <button
                                            class="btn btn-outline-primary btn-sm invoice-series-numbering-action"
                                            type="button"
                                            data-next-number-open
                                            data-series-id="{{ $item->id }}"
                                            data-form-url="{{ route('invoices.series.next-number.form', $item) }}"
                                            data-store-url="{{ route('invoices.series.next-number.store', $item) }}"
                                            aria-label="Ustaw następny numer dla serii {{ $item->name }}"
                                        >
                                            <i class="bi bi-123" aria-hidden="true"></i>
                                            Ustaw następny numer
                                        </button>
                                    </td>
                                    <td class="text-center">
                                        @if ($item->is_system)
                                            <span
                                                class="invoice-series-action-disabled"
                                                data-role="system-active-indicator"
                                                data-series-id="{{ $item->id }}"
                                                title="Seria systemowa jest zawsze aktywna i nie może zostać ukryta."
                                                aria-label="Seria systemowa jest zawsze aktywna i nie może zostać ukryta."
                                            ><i class="bi bi-eye" aria-hidden="true"></i></span>
                                        @else
                                            <form
                                                class="d-inline"
                                                method="POST"
                                                action="{{ route('invoices.series.active', $item) }}"
                                                data-role="series-active-form"
                                                data-series-id="{{ $item->id }}"
                                            >
                                                @csrf
                                                @method('PATCH')
                                                <input type="hidden" name="is_active" value="{{ $item->is_active ? 0 : 1 }}">
                                                <button
                                                    class="invoice-series-action"
                                                    type="submit"
                                                    title="{{ $item->is_active ? 'Ukryj serię numeracji' : 'Aktywuj serię numeracji' }}"
                                                    aria-label="{{ $item->is_active ? 'Ukryj serię numeracji' : 'Aktywuj serię numeracji' }}"
                                                ><i class="bi {{ $item->is_active ? 'bi-eye' : 'bi-eye-slash' }}" aria-hidden="true"></i></button>
                                            </form>
                                        @endif
                                    </td>
                                    <td class="text-center">
                                        <button
                                            class="invoice-series-action"
                                            type="button"
                                            data-role="series-edit"
                                            data-series-id="{{ $item->id }}"
                                            data-series-edit
                                            data-edit-url="{{ route('invoices.series.edit', $item) }}"
                                            data-update-url="{{ route('invoices.series.update', $item) }}"
                                            title="Edytuj serię numeracji"
                                            aria-label="Edytuj serię numeracji {{ $item->name }}"
                                        ><i class="bi bi-pencil" aria-hidden="true"></i></button>
                                    </td>
                                    <td class="text-center">
                                        @if ($item->is_system)
                                            <span
                                                class="invoice-series-action-disabled"
                                                data-role="system-delete-disabled"
                                                data-series-id="{{ $item->id }}"
                                                title="Predefiniowanej serii systemowej nie można usunąć."
                                                aria-label="Predefiniowanej serii systemowej nie można usunąć."
                                                aria-disabled="true"
                                            ><i class="bi bi-x-lg" aria-hidden="true"></i></span>
                                        @elseif ($item->series_using_as_default_correction_count > 0)
                                            <span
                                                class="invoice-series-action-disabled"
                                                data-role="series-delete-disabled"
                                                data-series-id="{{ $item->id }}"
                                                title="Nie można usunąć serii numeracji, ponieważ jest przypisana jako seria korekt."
                                                aria-label="Nie można usunąć serii numeracji, ponieważ jest przypisana jako seria korekt."
                                                aria-disabled="true"
                                            ><i class="bi bi-x-lg" aria-hidden="true"></i></span>
                                        @else
                                            <form
                                                class="d-inline"
                                                method="POST"
                                                action="{{ route('invoices.series.destroy', $item) }}"
                                                data-role="series-delete-form"
                                                data-series-id="{{ $item->id }}"
                                                data-confirm-message="Czy na pewno chcesz usunąć serię numeracji „{{ $item->name }}”?"
                                                onsubmit="return window.confirm(this.dataset.confirmMessage)"
                                            >
                                                @csrf
                                                @method('DELETE')
                                                <button
                                                    class="invoice-series-action text-danger"
                                                    type="submit"
                                                    title="Usuń serię numeracji"
                                                    aria-label="Usuń serię numeracji"
                                                ><i class="bi bi-x-lg" aria-hidden="true"></i></button>
                                            </form>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif

            <footer class="invoice-series-footer">
                <span class="invoice-series-count">Znaleziono: {{ $series->total() }}</span>
                <x-pagination-toolbar
                    :paginator="$series"
                    :per-page-options="[10]"
                    :per-page="10"
                    aria-label="Paginacja serii numeracji"
                />
            </footer>
        </section>
    </div>

    @include('invoices.series._modal')
    @include('invoices.series._next-number-modal')

    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const modalElement = document.querySelector('[data-series-modal]');
            const createButton = document.querySelector('[data-series-create]');

            if (!modalElement || !createButton || typeof bootstrap === 'undefined') {
                return;
            }

            const modal = bootstrap.Modal.getOrCreateInstance(modalElement);
            const form = modalElement.querySelector('[data-series-modal-form]');
            const body = modalElement.querySelector('[data-series-modal-body]');
            const title = modalElement.querySelector('[data-series-modal-title]');
            const submit = modalElement.querySelector('[data-series-submit]');
            const method = modalElement.querySelector('[data-series-method]');
            const modeInput = modalElement.querySelector('[data-series-form-mode]');
            const editingIdInput = modalElement.querySelector('[data-series-editing-id]');
            const recordBadge = modalElement.querySelector('[data-series-record-badge]');
            const recordId = modalElement.querySelector('[data-series-record-id]');
            const pickerTemplate = document.querySelector('[data-series-type-picker-template]');
            const createFormUrl = modalElement.dataset.createFormUrl;
            const storeUrl = modalElement.dataset.storeUrl;
            let activeMode = 'create';
            let activeRequest = null;
            let requestSequence = 0;
            let lastLoad = null;

            const setSubmitEnabled = (enabled) => {
                submit.disabled = !enabled;
            };

            const updateRecordBadge = () => {
                const seriesId = body.querySelector('[data-series-form-fragment]')?.dataset.seriesId || '';

                recordBadge.hidden = seriesId === '';
                recordId.textContent = seriesId;
            };

            const updateInvoiceDependencies = () => {
                const vatSource = body.querySelector('[name="vat_rate_source"]')?.value;
                const includeShipping = body.querySelector('[name="include_shipping"][type="checkbox"]')?.checked === true;
                const shippingVatMode = body.querySelector('[name="shipping_vat_mode"]')?.value;
                const paymentSource = body.querySelector('[name="payment_method_source"]')?.value;
                const paymentDueMode = body.querySelector('[name="payment_due_mode"]')?.value;
                const visibility = {
                    'default-vat-rate': vatSource === 'fixed',
                    'shipping-vat-settings': includeShipping,
                    'shipping-vat-rate': includeShipping && shippingVatMode === 'fixed',
                    'fixed-payment-method': paymentSource === 'fixed',
                    'payment-due-days': paymentDueMode === 'days_from_issue',
                };

                Object.entries(visibility).forEach(([name, visible]) => {
                    body.querySelectorAll(`[data-invoice-dependent="${name}"]`).forEach((element) => {
                        element.classList.toggle('d-none', !visible);
                    });
                });

                const correctionIssuerSource = body.querySelector('[name="correction_issuer_source"]')?.value;
                const correctionPaymentSource = body.querySelector('[name="correction_payment_method_source"]')?.value;
                const correctionVisibility = {
                    'issuer-name': correctionIssuerSource === 'series',
                    'fixed-payment-method': correctionPaymentSource === 'fixed',
                };

                Object.entries(correctionVisibility).forEach(([name, visible]) => {
                    body.querySelectorAll(`[data-correction-dependent="${name}"]`).forEach((element) => {
                        element.classList.toggle('d-none', !visible);
                        element.querySelectorAll('[data-required-when-visible]').forEach((field) => {
                            field.required = visible;
                        });
                    });
                });
            };

            const showLoading = () => {
                body.innerHTML = `
                    <div class="invoice-series-modal-loading" role="status" aria-live="polite">
                        <span class="spinner-border spinner-border-sm" aria-hidden="true"></span>
                        <span>Ładowanie formularza…</span>
                    </div>
                `;
                setSubmitEnabled(false);
                updateRecordBadge();
            };

            const renderTypePicker = () => {
                body.replaceChildren(pickerTemplate.content.cloneNode(true));
                setSubmitEnabled(false);
                updateRecordBadge();
            };

            const showLoadingError = () => {
                body.innerHTML = `
                    <div class="alert alert-danger mb-0" role="alert">
                        <div>Nie udało się załadować formularza serii numeracji. Spróbuj ponownie.</div>
                        <button class="btn btn-outline-danger btn-sm mt-3" type="button" data-series-retry>
                            Spróbuj ponownie
                        </button>
                    </div>
                `;
                setSubmitEnabled(false);
                updateRecordBadge();
            };

            const captureValues = () => {
                const values = {};

                body.querySelectorAll('[name]').forEach((field) => {
                    if (field.type === 'file') {
                        return;
                    }

                    values[field.name] = field.type === 'checkbox'
                        ? (field.checked ? '1' : '0')
                        : field.value;
                });

                return values;
            };

            const restoreValues = (values, preserveNumberFormat) => {
                Object.entries(values || {}).forEach(([name, value]) => {
                    if (name === 'document_type' || name === 'logo' || (!preserveNumberFormat && name === 'number_format')) {
                        return;
                    }

                    const fields = [...body.querySelectorAll(`[name="${CSS.escape(name)}"]`)];
                    const field = fields.find((candidate) => candidate.type === 'checkbox') || fields[0];
                    if (!field) {
                        return;
                    }

                    if (field.type === 'checkbox') {
                        field.checked = value === '1';
                    } else {
                        field.value = value;
                    }
                });
            };

            const focusFirstField = () => {
                const field = body.querySelector('.is-invalid, input:not([type="hidden"]):not([disabled]), select:not([disabled]), textarea:not([disabled])');
                window.setTimeout(() => field?.focus(), 120);
            };

            const loadFragment = async (url, options = {}) => {
                const sequence = ++requestSequence;
                activeRequest?.abort();
                activeRequest = new AbortController();
                lastLoad = { url, options };
                showLoading();

                try {
                    const response = await fetch(url, {
                        headers: {
                            Accept: 'text/html',
                            'X-Requested-With': 'XMLHttpRequest',
                        },
                        cache: 'no-store',
                        signal: activeRequest.signal,
                    });

                    if (!response.ok) {
                        throw new Error('Form request failed.');
                    }

                    const html = await response.text();
                    if (sequence !== requestSequence) {
                        return;
                    }

                    body.innerHTML = html;
                    restoreValues(options.values, options.preserveNumberFormat === true);
                    updateInvoiceDependencies();
                    updateRecordBadge();
                    setSubmitEnabled(true);
                    focusFirstField();
                } catch (error) {
                    if (error.name === 'AbortError' || sequence !== requestSequence) {
                        return;
                    }

                    showLoadingError();
                }
            };

            const resetCreateForm = () => {
                activeMode = 'create';
                form.action = storeUrl;
                method.disabled = true;
                modeInput.value = 'create';
                editingIdInput.value = '';
                title.textContent = 'Seria numeracji';
                renderTypePicker();
            };

            createButton.addEventListener('click', () => {
                activeRequest?.abort();
                resetCreateForm();
                modal.show();
                focusFirstField();
            });

            document.querySelectorAll('[data-series-edit]').forEach((button) => {
                button.addEventListener('click', () => {
                    activeMode = 'edit';
                    form.action = button.dataset.updateUrl;
                    method.disabled = false;
                    modeInput.value = 'edit';
                    editingIdInput.value = button.dataset.seriesId;
                    title.textContent = 'Seria numeracji';
                    modal.show();
                    loadFragment(button.dataset.editUrl);
                });
            });

            body.addEventListener('change', (event) => {
                const selector = event.target.closest('[data-series-document-type]');
                if (!selector) {
                    if (event.target.closest('[data-invoice-control], [data-correction-control]')) {
                        updateInvoiceDependencies();
                    }

                    return;
                }

                if (selector.value === '') {
                    return;
                }

                const values = captureValues();
                const url = new URL(createFormUrl, window.location.origin);
                url.searchParams.set('document_type', selector.value);

                loadFragment(url.toString(), {
                    values,
                    preserveNumberFormat: activeMode === 'edit',
                });
            });

            body.addEventListener('click', (event) => {
                if (!event.target.closest('[data-series-retry]') || !lastLoad) {
                    return;
                }

                loadFragment(lastLoad.url, lastLoad.options);
            });

            modalElement.addEventListener('hidden.bs.modal', () => {
                activeRequest?.abort();
                requestSequence += 1;
                lastLoad = null;
                resetCreateForm();
            });

            if (modalElement.dataset.reopen === '1') {
                activeMode = modeInput.value;
                modal.show();
                setSubmitEnabled(body.querySelector('[data-series-form-fragment]') !== null);
                updateInvoiceDependencies();
                updateRecordBadge();
                focusFirstField();
            }
        });
    </script>

    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const modalElement = document.querySelector('[data-next-number-modal]');

            if (!modalElement || typeof bootstrap === 'undefined') {
                return;
            }

            const modal = bootstrap.Modal.getOrCreateInstance(modalElement);
            const form = modalElement.querySelector('[data-next-number-modal-form]');
            const body = modalElement.querySelector('[data-next-number-modal-body]');
            const submit = modalElement.querySelector('[data-next-number-submit]');
            let formRequest = null;
            let previewRequest = null;
            let previewTimer = null;

            const showLoading = () => {
                body.innerHTML = `
                    <div class="invoice-series-modal-loading" role="status" aria-live="polite">
                        <span class="spinner-border spinner-border-sm" aria-hidden="true"></span>
                        <span>Ładowanie danych numeracji…</span>
                    </div>
                `;
                submit.disabled = true;
            };

            const showLoadError = () => {
                body.innerHTML = '<div class="alert alert-danger mb-0" role="alert">Nie udało się pobrać danych numeracji. Spróbuj ponownie.</div>';
                submit.disabled = true;
            };

            const renderPreview = (data) => {
                const fragment = body.querySelector('[data-next-number-form-fragment]');
                if (!fragment) {
                    return;
                }

                fragment.querySelector('[data-preview-last]').textContent = data.current_last_sequence_number;
                fragment.querySelector('[data-preview-floor]').textContent = data.protected_floor_sequence_number;
                fragment.querySelector('[data-preview-next]').textContent = data.current_next_sequence_number;
                fragment.querySelector('[data-preview-formatted]').textContent = data.formatted_number;
                fragment.querySelector('[data-next-number-period-description]').textContent = data.period_description;
            };

            const showPreviewError = (message) => {
                const alert = body.querySelector('[data-next-number-preview-error]');
                if (!alert) {
                    return;
                }

                alert.textContent = message;
                alert.classList.remove('d-none');
            };

            const hidePreviewError = () => {
                body.querySelector('[data-next-number-preview-error]')?.classList.add('d-none');
            };

            const refreshPreview = async () => {
                const fragment = body.querySelector('[data-next-number-form-fragment]');
                const nextNumber = fragment?.querySelector('[data-next-sequence-number]');
                if (!fragment || !nextNumber || nextNumber.value === '') {
                    submit.disabled = true;
                    return;
                }

                previewRequest?.abort();
                previewRequest = new AbortController();
                const url = new URL(fragment.dataset.previewUrl, window.location.origin);
                const period = fragment.querySelector('[data-next-number-period]');
                if (period) {
                    url.searchParams.set(period.name, period.value);
                }
                url.searchParams.set('next_sequence_number', nextNumber.value);
                hidePreviewError();
                submit.disabled = true;

                try {
                    const response = await fetch(url.toString(), {
                        headers: {
                            Accept: 'application/json',
                            'X-Requested-With': 'XMLHttpRequest',
                        },
                        cache: 'no-store',
                        signal: previewRequest.signal,
                    });
                    const data = await response.json();

                    if (!response.ok) {
                        const validationMessage = Object.values(data.errors || {}).flat()[0];
                        throw new Error(validationMessage || data.message || 'Nie udało się przygotować podglądu numeru.');
                    }

                    renderPreview(data);
                    submit.disabled = false;
                } catch (error) {
                    if (error.name === 'AbortError') {
                        return;
                    }

                    showPreviewError(error.message || 'Nie udało się przygotować podglądu numeru.');
                    submit.disabled = true;
                }
            };

            const schedulePreview = () => {
                window.clearTimeout(previewTimer);
                previewTimer = window.setTimeout(refreshPreview, 220);
            };

            const loadForm = async (formUrl, storeUrl) => {
                formRequest?.abort();
                formRequest = new AbortController();
                form.action = storeUrl;
                showLoading();

                try {
                    const response = await fetch(formUrl, {
                        headers: {
                            Accept: 'text/html',
                            'X-Requested-With': 'XMLHttpRequest',
                        },
                        cache: 'no-store',
                        signal: formRequest.signal,
                    });

                    if (!response.ok) {
                        throw new Error('Form request failed.');
                    }

                    body.innerHTML = await response.text();
                    submit.disabled = false;
                    window.setTimeout(() => body.querySelector('.is-invalid, [data-next-sequence-number]')?.focus(), 120);
                } catch (error) {
                    if (error.name !== 'AbortError') {
                        showLoadError();
                    }
                }
            };

            document.querySelectorAll('[data-next-number-open]').forEach((button) => {
                button.addEventListener('click', () => {
                    modal.show();
                    loadForm(button.dataset.formUrl, button.dataset.storeUrl);
                });
            });

            body.addEventListener('input', (event) => {
                if (event.target.closest('[data-next-sequence-number], [data-next-number-period]')) {
                    schedulePreview();
                }
            });

            body.addEventListener('change', (event) => {
                if (event.target.closest('[data-next-number-period]')) {
                    schedulePreview();
                }
            });

            modalElement.addEventListener('hidden.bs.modal', () => {
                formRequest?.abort();
                previewRequest?.abort();
                window.clearTimeout(previewTimer);
                showLoading();
            });

            if (modalElement.dataset.reopenSeriesId && modalElement.dataset.reopenFormUrl) {
                modal.show();
                loadForm(modalElement.dataset.reopenFormUrl, modalElement.dataset.reopenStoreUrl);
            }
        });
    </script>
@endsection
