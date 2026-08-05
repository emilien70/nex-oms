@php
    $reopenMode = $reopenForm['mode'] ?? 'create';
    $reopenSeries = $reopenForm['series'] ?? null;
    $reopenAction = $reopenForm['action'] ?? route('invoices.series.store');
    $reopenMethod = $reopenForm['method'] ?? 'POST';
@endphp

<div
    class="modal fade"
    id="invoiceSeriesModal"
    tabindex="-1"
    aria-labelledby="invoiceSeriesModalTitle"
    aria-hidden="true"
    data-series-modal
    data-create-form-url="{{ route('invoices.series.form') }}"
    data-store-url="{{ route('invoices.series.store') }}"
    data-reopen="{{ $reopenForm === null ? '0' : '1' }}"
>
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-xl">
        <form class="modal-content" method="POST" action="{{ $reopenAction }}" enctype="multipart/form-data" data-series-modal-form>
                @csrf
                <input
                    type="hidden"
                    name="_method"
                    value="PATCH"
                    data-series-method
                    @disabled($reopenMethod !== 'PATCH')
                >
                <input type="hidden" name="form_mode" value="{{ $reopenMode }}" data-series-form-mode>
                <input
                    type="hidden"
                    name="editing_series_id"
                    value="{{ $reopenSeries?->id }}"
                    data-series-editing-id
                >

                <div class="modal-header">
                    <h2 class="modal-title fs-6" id="invoiceSeriesModalTitle" data-series-modal-title>
                        Seria numeracji
                    </h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Zamknij"></button>
                </div>

                <div class="modal-body" data-series-modal-body>
                    @if (($reopenForm['viewData'] ?? null) !== null)
                        @include('invoices.series.partials._form', $reopenForm['viewData'])
                    @else
                        <div data-series-type-picker>
                            <label class="form-label" for="invoice-series-type-picker">Typ dokumentu</label>
                            <select
                                class="form-select form-select-sm {{ $errors->has('document_type') ? 'is-invalid' : '' }}"
                                id="invoice-series-type-picker"
                                data-series-document-type
                            >
                                <option value="">Wybierz typ dokumentu</option>
                                @foreach (\Modules\Invoices\Enums\InvoiceDocumentType::cases() as $type)
                                    <option value="{{ $type->value }}">{{ $type->label() }}</option>
                                @endforeach
                            </select>
                            @if ($errors->has('document_type'))
                                <div class="invalid-feedback">{{ $errors->first('document_type') }}</div>
                            @endif
                        </div>
                    @endif
                </div>

                <div class="modal-footer invoice-series-modal-footer">
                    <span
                        class="invoice-series-record-id"
                        data-series-record-badge
                        @if (! $reopenSeries?->id) hidden @endif
                    >
                        ID: <span data-series-record-id>{{ $reopenSeries?->id }}</span>
                    </span>
                    <div class="invoice-series-modal-actions">
                        <button
                            type="submit"
                            class="btn btn-primary btn-sm"
                            data-series-submit
                            @disabled(($reopenForm['viewData'] ?? null) === null)
                        >Zapisz</button>
                        <button type="button" class="btn btn-light btn-sm border" data-bs-dismiss="modal">Zamknij</button>
                    </div>
                </div>
        </form>
    </div>
</div>

<template data-series-type-picker-template>
    <div data-series-type-picker>
        <label class="form-label" for="invoice-series-type-picker-dynamic">Typ dokumentu</label>
        <select
            class="form-select form-select-sm"
            id="invoice-series-type-picker-dynamic"
            data-series-document-type
        >
            <option value="">Wybierz typ dokumentu</option>
            @foreach (\Modules\Invoices\Enums\InvoiceDocumentType::cases() as $type)
                <option value="{{ $type->value }}">{{ $type->label() }}</option>
            @endforeach
        </select>
    </div>
</template>
