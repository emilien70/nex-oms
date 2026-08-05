@php
    $issuedInvoice = $salesDocumentActions['issuedInvoice'];
    $issuedProforma = $salesDocumentActions['issuedProforma'];
    $proformaLocked = $salesDocumentActions['proformaLocked'];
    $invoiceSeries = $salesDocumentActions['invoiceSeries'];
    $proformaSeries = $salesDocumentActions['proformaSeries'];
@endphp

<div id="order-sales-document-actions" class="management-sales-document-actions" data-sales-document-actions>
    <div class="management-invoice-label">Faktura:</div>
    <div class="management-sales-document-error alert alert-danger" data-sales-document-error role="alert" hidden></div>

    @if ($issuedInvoice)
        <div class="management-issued-invoice-actions">
            <div class="btn-group management-issued-invoice-group" role="group" aria-label="Akcje Faktury VAT {{ $issuedInvoice->number }}">
                <a class="btn btn-sm btn-outline-secondary management-issued-invoice-number" href="{{ route('invoices.pdf', $issuedInvoice) }}" target="_blank" rel="noopener" title="Otwórz Fakturę VAT">
                    <i class="bi bi-file-earmark-text" aria-hidden="true"></i>
                    <span>{{ $issuedInvoice->number }}</span>
                </a>
                <a class="btn btn-sm btn-outline-secondary management-issued-invoice-icon management-issued-invoice-print" href="{{ route('invoices.pdf', $issuedInvoice) }}" target="_blank" rel="noopener" title="Drukuj Fakturę VAT" aria-label="Drukuj Fakturę VAT">
                    <i class="bi bi-printer" aria-hidden="true"></i>
                </a>
                <a class="btn btn-sm btn-outline-secondary management-issued-invoice-icon management-issued-invoice-edit" href="{{ route('invoices.edit', $issuedInvoice) }}" title="Edytuj Fakturę VAT" aria-label="Edytuj Fakturę VAT">
                    <i class="bi bi-pencil" aria-hidden="true"></i>
                </a>
                <button class="btn btn-sm btn-outline-secondary management-issued-invoice-icon management-issued-invoice-delete" type="button" data-bs-toggle="modal" data-bs-target="#deleteInvoiceFromOrderModal" title="Usuń Fakturę VAT" aria-label="Usuń Fakturę VAT">
                    <i class="bi bi-x-lg" aria-hidden="true"></i>
                </button>
            </div>
            <button class="btn btn-sm btn-outline-secondary management-issued-invoice-icon management-issued-invoice-attachment" type="button" title="Wgrywanie dokumentu nie jest jeszcze dostępne" aria-label="Wgrywanie dokumentu nie jest jeszcze dostępne" disabled>
                <i class="bi bi-paperclip" aria-hidden="true"></i>
            </button>
        </div>
        @include('invoices.partials.delete-modal', [
            'invoice' => $issuedInvoice,
            'modalId' => 'deleteInvoiceFromOrderModal',
            'ajax' => true,
        ])
    @else
        @if ($invoiceSeries->count() === 1)
            <form method="POST" action="{{ route('orders.invoice.store', $order) }}" class="management-document-action management-invoice-button" data-sales-document-form>
                @csrf
                <input type="hidden" name="invoice_series_id" value="{{ $invoiceSeries->first()->id }}">
                <button class="btn btn-sm btn-outline-secondary" type="submit">WYSTAW FAKTUR&#280;</button>
            </form>
        @elseif ($invoiceSeries->count() > 1)
            <div class="dropdown management-document-dropdown management-invoice-button">
                <button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">WYSTAW FAKTUR&#280;</button>
                <ul class="dropdown-menu">
                    @foreach ($invoiceSeries as $series)
                        <li>
                            <form method="POST" action="{{ route('orders.invoice.store', $order) }}" data-sales-document-form>
                                @csrf
                                <input type="hidden" name="invoice_series_id" value="{{ $series->id }}">
                                <button class="dropdown-item" type="submit">{{ $series->name }}</button>
                            </form>
                        </li>
                    @endforeach
                </ul>
            </div>
        @else
            <button class="btn btn-sm btn-outline-secondary management-invoice-button" type="button" disabled>WYSTAW FAKTUR&#280;</button>
        @endif

        @if ($issuedProforma && ! $proformaLocked)
            <div class="management-issued-invoice-actions management-issued-proforma-actions">
                <div class="btn-group management-issued-invoice-group" role="group" aria-label="Akcje Pro formy {{ $issuedProforma->number }}">
                    <form method="POST" action="{{ route('orders.proforma.store', $order) }}" class="management-document-action" data-sales-document-form data-open-document-after-submit>
                        @csrf
                        <input type="hidden" name="invoice_series_id" value="{{ $issuedProforma->invoice_series_id }}">
                        <button class="btn btn-sm btn-outline-secondary" type="submit" title="Odśwież Pro formę i otwórz PDF" aria-label="Odśwież Pro formę i otwórz PDF">{{ $issuedProforma->number }}</button>
                    </form>
                    <button class="btn btn-sm btn-outline-secondary management-issued-invoice-icon management-issued-invoice-delete" type="button" data-bs-toggle="modal" data-bs-target="#deleteProformaFromOrderModal" title="Usuń Pro formę" aria-label="Usuń Pro formę">
                        <i class="bi bi-x-lg" aria-hidden="true"></i>
                    </button>
                </div>
            </div>
            @include('invoices.partials.delete-modal', [
                'invoice' => $issuedProforma,
                'modalId' => 'deleteProformaFromOrderModal',
                'ajax' => true,
            ])
        @elseif (! $proformaLocked && $proformaSeries->count() === 1)
            <form method="POST" action="{{ route('orders.proforma.store', $order) }}" class="management-document-action management-proforma-button" data-sales-document-form>
                @csrf
                <input type="hidden" name="invoice_series_id" value="{{ $proformaSeries->first()->id }}">
                <button class="btn btn-sm btn-outline-secondary" type="submit">PRO FORMA</button>
            </form>
        @elseif (! $proformaLocked && $proformaSeries->count() > 1)
            <div class="dropdown management-document-dropdown management-proforma-button">
                <button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">PRO FORMA</button>
                <ul class="dropdown-menu">
                    @foreach ($proformaSeries as $series)
                        <li>
                            <form method="POST" action="{{ route('orders.proforma.store', $order) }}" data-sales-document-form>
                                @csrf
                                <input type="hidden" name="invoice_series_id" value="{{ $series->id }}">
                                <button class="dropdown-item" type="submit">
                                    <span class="d-block">{{ $series->name }} @if ($series->is_system)<small class="text-muted">systemowa</small>@endif</span>
                                    <small class="text-muted">{{ $series->number_format }}</small>
                                </button>
                            </form>
                        </li>
                    @endforeach
                </ul>
            </div>
        @elseif (! $proformaLocked)
            <button class="btn btn-sm btn-outline-secondary management-proforma-button" type="button" disabled>PRO FORMA</button>
        @endif
    @endif
</div>
