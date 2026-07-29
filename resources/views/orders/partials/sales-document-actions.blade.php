@php
    $issuedInvoice = $salesDocumentActions['issuedInvoice'];
    $issuedProforma = $salesDocumentActions['issuedProforma'];
    $invoiceSeries = $salesDocumentActions['invoiceSeries'];
    $proformaSeries = $salesDocumentActions['proformaSeries'];
@endphp

<div id="order-sales-document-actions" class="management-sales-document-actions" data-sales-document-actions>
    <div class="management-invoice-label">Faktura:</div>
    <div class="management-sales-document-error alert alert-danger" data-sales-document-error role="alert" hidden></div>

    @if ($issuedInvoice)
        <a class="btn btn-sm btn-outline-secondary management-invoice-button" href="{{ route('invoices.pdf', $issuedInvoice) }}" target="_blank" rel="noopener">{{ $issuedInvoice->number }}</a>
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
                                <button class="dropdown-item" type="submit">
                                    <span class="d-block">{{ $series->name }} @if ($series->is_system)<small class="text-muted">systemowa</small>@endif</span>
                                    <small class="text-muted">{{ $series->number_format }}</small>
                                </button>
                            </form>
                        </li>
                    @endforeach
                </ul>
            </div>
        @else
            <button class="btn btn-sm btn-outline-secondary management-invoice-button" type="button" disabled>WYSTAW FAKTUR&#280;</button>
        @endif

        @if ($issuedProforma)
            <a class="btn btn-sm btn-outline-secondary management-proforma-button" href="{{ route('invoices.pdf', $issuedProforma) }}" target="_blank" rel="noopener">{{ $issuedProforma->number }}</a>
        @elseif ($proformaSeries->count() === 1)
            <form method="POST" action="{{ route('orders.proforma.store', $order) }}" class="management-document-action management-proforma-button" data-sales-document-form>
                @csrf
                <input type="hidden" name="invoice_series_id" value="{{ $proformaSeries->first()->id }}">
                <button class="btn btn-sm btn-outline-secondary" type="submit">PRO FORMA</button>
            </form>
        @elseif ($proformaSeries->count() > 1)
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
        @else
            <button class="btn btn-sm btn-outline-secondary management-proforma-button" type="button" disabled>PRO FORMA</button>
        @endif
    @endif
</div>
