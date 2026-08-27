@php
    $issuedInvoice = $salesDocumentActions['issuedInvoice'];
    $issuedProforma = $salesDocumentActions['issuedProforma'];
    $issuedCorrection = $salesDocumentActions['issuedCorrection'];
    $finalizedCorrections = $salesDocumentActions['finalizedCorrections'];
    $hasCorrections = $issuedCorrection !== null || $finalizedCorrections->isNotEmpty();
    $proformaLocked = $salesDocumentActions['proformaLocked'];
    $invoiceSeries = $salesDocumentActions['invoiceSeries'];
    $proformaSeries = $salesDocumentActions['proformaSeries'];
    $ksefSeriesEnabled = $salesDocumentActions['ksefSeriesEnabled'];
    $ksefHasSubmission = $salesDocumentActions['ksefHasSubmission'];
    $ksefCanSend = $salesDocumentActions['ksefCanSend'];
    $ksefAutomaticRefreshPending = $salesDocumentActions['ksefAutomaticRefreshPending'];
    $ksefSubmission = $salesDocumentActions['ksefSubmission'];
    $ksefPdfDownloadAvailable = $salesDocumentActions['ksefPdfDownloadAvailable'];
    $ksefVerificationUrl = $salesDocumentActions['ksefVerificationUrl'];
    $ksefPdfFilename = $salesDocumentActions['ksefPdfFilename'];
@endphp

<div
    id="order-sales-document-actions"
    class="management-sales-document-actions"
    data-sales-document-actions
    data-ksef-automatic-refresh="{{ $ksefAutomaticRefreshPending ? '1' : '0' }}"
>
    <div class="management-invoice-label">Faktura:</div>
    <div class="management-sales-document-error alert alert-danger" data-sales-document-error role="alert" hidden></div>

    @if ($issuedInvoice)
        <div class="management-issued-invoice-actions">
            <div class="btn-group management-issued-invoice-group" role="group" aria-label="Akcje Faktury VAT {{ $issuedInvoice->number }}">
                <a class="btn btn-sm btn-outline-secondary management-issued-invoice-number" href="{{ route('invoices.pdf', $issuedInvoice) }}" target="_blank" rel="noopener" title="Otwórz Fakturę VAT" data-sales-document-number>
                    <i class="bi bi-file-earmark-text" aria-hidden="true"></i>
                    <span>{{ $issuedInvoice->number }}</span>
                </a>
                <a class="btn btn-sm btn-outline-secondary management-issued-invoice-icon management-issued-invoice-print" href="{{ route('invoices.pdf', $issuedInvoice) }}" target="_blank" rel="noopener" title="Drukuj Fakturę VAT" aria-label="Drukuj Fakturę VAT">
                    <i class="bi bi-printer" aria-hidden="true"></i>
                </a>
                <a class="btn btn-sm btn-outline-secondary management-issued-invoice-icon management-issued-invoice-edit" href="{{ route('invoices.edit', ['invoice' => $issuedInvoice, 'return_to' => 'order']) }}" title="Edytuj Fakturę VAT" aria-label="Edytuj Fakturę VAT">
                    <i class="bi bi-pencil" aria-hidden="true"></i>
                </a>
                @unless ($issuedInvoice->isFinalized() || $hasCorrections)
                    <button
                        class="btn btn-sm btn-outline-secondary management-issued-invoice-icon management-issued-invoice-delete"
                        type="button"
                        data-bs-toggle="modal"
                        data-bs-target="#deleteSalesDocumentModal"
                        data-sales-document-delete-trigger
                        data-document-id="{{ $issuedInvoice->getKey() }}"
                        data-document-type="{{ $issuedInvoice->document_type->value }}"
                        data-document-number="{{ $issuedInvoice->number }}"
                        data-delete-url="{{ route('invoices.destroy', $issuedInvoice) }}"
                        data-expected-lock-version="{{ $issuedInvoice->lock_version }}"
                        data-return-to="order"
                        title="Usuń Fakturę VAT"
                        aria-label="Usuń Fakturę VAT"
                    >
                        <i class="bi bi-x-lg" aria-hidden="true"></i>
                    </button>
                @endunless
            </div>
            @if ($ksefHasSubmission)
                @if ($ksefPdfDownloadAvailable)
                    <a
                        class="management-issued-invoice-ksef management-issued-invoice-ksef-reference management-issued-invoice-ksef-download"
                        href="{{ route('invoices.ksef.submissions.invoice.download', ['invoice' => $issuedInvoice, 'submission' => $ksefSubmission]) }}"
                        data-order-ksef-reference
                        data-order-ksef-invoice-pdf
                        data-ksef-invoice-source-url="{{ route('invoices.ksef.submissions.invoice.download', ['invoice' => $issuedInvoice, 'submission' => $ksefSubmission]) }}"
                        data-ksef-pdf-generator-src="{{ asset('vendor/ksef-pdf-generator/1.1.31/ksef-fe-invoice-converter.umd.js') }}"
                        data-ksef-number="{{ $ksefSubmission->ksef_number }}"
                        data-ksef-acquisition-date="{{ $ksefSubmission->acquisition_date?->format('d.m.Y H:i:s') }}"
                        data-ksef-verification-url="{{ $ksefVerificationUrl }}"
                        data-ksef-pdf-filename="{{ $ksefPdfFilename }}"
                        title="Pobierz PDF Faktury z KSeF"
                    >KSeF: {{ $issuedInvoice->number }}</a>
                @else
                    <span
                        class="btn btn-sm management-issued-invoice-ksef"
                        role="button"
                        aria-disabled="true"
                        tabindex="0"
                        data-order-ksef-pending
                        data-order-ksef-tooltip
                        data-bs-placement="top"
                        data-bs-title="Faktura jest przekazywana do KSeF"
                        title="Faktura jest przekazywana do KSeF"
                    >KSeF</span>
                @endif
            @elseif ($ksefCanSend)
                <form method="POST" action="{{ route('invoices.ksef.submissions.first-attempt', $issuedInvoice) }}" class="management-issued-invoice-ksef-form" data-order-ksef-send-form>
                    @csrf
                    <input type="hidden" name="return_to" value="order">
                    <button class="btn btn-sm management-issued-invoice-ksef" type="submit" data-order-ksef-send-trigger data-order-ksef-tooltip data-bs-toggle="modal" data-bs-target="#orderKsefSendConfirmationModal" data-bs-placement="top" data-bs-title="Przeka&#380; do KSeF" title="Przeka&#380; do KSeF" aria-label="Przeka&#380; Faktur&#281; {{ $issuedInvoice->number }} do KSeF">KSeF</button>
                </form>
            @elseif ($ksefSeriesEnabled)
                <button class="btn btn-sm management-issued-invoice-ksef" type="button" aria-label="KSeF" data-sales-document-ksef-label disabled>KSeF</button>
            @endif
        </div>
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
                    <button
                        class="btn btn-sm btn-outline-secondary management-issued-invoice-icon management-issued-invoice-delete"
                        type="button"
                        data-bs-toggle="modal"
                        data-bs-target="#deleteSalesDocumentModal"
                        data-sales-document-delete-trigger
                        data-document-id="{{ $issuedProforma->getKey() }}"
                        data-document-type="{{ $issuedProforma->document_type->value }}"
                        data-document-number="{{ $issuedProforma->number }}"
                        data-delete-url="{{ route('invoices.destroy', $issuedProforma) }}"
                        data-expected-lock-version="{{ $issuedProforma->lock_version }}"
                        data-return-to="order"
                        title="Usuń Pro formę"
                        aria-label="Usuń Pro formę"
                    >
                        <i class="bi bi-x-lg" aria-hidden="true"></i>
                    </button>
                </div>
            </div>
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

    @if ($issuedCorrection || $finalizedCorrections->isNotEmpty())
        <div class="management-invoice-label management-correction-label">Korekta:</div>
    @endif

    @foreach ($finalizedCorrections as $finalizedCorrection)
        <div class="management-issued-invoice-actions management-issued-correction-actions">
            <div class="btn-group management-issued-invoice-group" role="group" aria-label="Akcje zamkniętej Korekty {{ $finalizedCorrection->number }}">
                <a class="btn btn-sm btn-outline-secondary management-issued-invoice-number" href="{{ route('invoices.pdf', $finalizedCorrection) }}" target="_blank" rel="noopener" title="Otwórz zamkniętą Korektę" data-sales-document-number>
                    <i class="bi bi-file-earmark-text" aria-hidden="true"></i>
                    <span>{{ $finalizedCorrection->number }}</span>
                </a>
                <a class="btn btn-sm btn-outline-secondary management-issued-invoice-icon management-issued-invoice-print" href="{{ route('invoices.pdf', $finalizedCorrection) }}" target="_blank" rel="noopener" title="Drukuj Korektę" aria-label="Drukuj Korektę">
                    <i class="bi bi-printer" aria-hidden="true"></i>
                </a>
                <a class="btn btn-sm btn-outline-secondary management-issued-invoice-icon management-issued-invoice-edit" href="{{ route('invoices.corrections.edit', ['correction' => $finalizedCorrection, 'return_to' => 'order']) }}" title="Otwórz zamkniętą Korektę" aria-label="Otwórz zamkniętą Korektę">
                    <i class="bi bi-eye" aria-hidden="true"></i>
                </a>
            </div>
        </div>
    @endforeach

    @if ($issuedCorrection)
        <div class="management-issued-invoice-actions management-issued-correction-actions">
            <div class="btn-group management-issued-invoice-group" role="group" aria-label="Akcje Korekty {{ $issuedCorrection->number }}">
                <a class="btn btn-sm btn-outline-secondary management-issued-invoice-number" href="{{ route('invoices.pdf', $issuedCorrection) }}" target="_blank" rel="noopener" title="Otw&oacute;rz Korekt&#281;" data-sales-document-number>
                    <i class="bi bi-file-earmark-text" aria-hidden="true"></i>
                    <span>{{ $issuedCorrection->number }}</span>
                </a>
                <a class="btn btn-sm btn-outline-secondary management-issued-invoice-icon management-issued-invoice-print" href="{{ route('invoices.pdf', $issuedCorrection) }}" target="_blank" rel="noopener" title="Drukuj Korekt&#281;" aria-label="Drukuj Korekt&#281;">
                    <i class="bi bi-printer" aria-hidden="true"></i>
                </a>
                <a class="btn btn-sm btn-outline-secondary management-issued-invoice-icon management-issued-invoice-edit" href="{{ route('invoices.corrections.edit', ['correction' => $issuedCorrection, 'return_to' => 'order']) }}" title="Edytuj Korekt&#281;" aria-label="Edytuj Korekt&#281;">
                    <i class="bi bi-pencil" aria-hidden="true"></i>
                </a>
                <button
                    class="btn btn-sm btn-outline-secondary management-issued-invoice-icon management-issued-invoice-delete"
                    type="button"
                    data-bs-toggle="modal"
                    data-bs-target="#deleteSalesDocumentModal"
                    data-sales-document-delete-trigger
                    data-document-id="{{ $issuedCorrection->getKey() }}"
                    data-document-type="{{ $issuedCorrection->document_type->value }}"
                    data-document-number="{{ $issuedCorrection->number }}"
                    data-delete-url="{{ route('invoices.destroy', $issuedCorrection) }}"
                    data-expected-lock-version="{{ $issuedCorrection->lock_version }}"
                    data-return-to="order"
                    title="Usu&#324; Korekt&#281;"
                    aria-label="Usu&#324; Korekt&#281;"
                >
                    <i class="bi bi-x-lg" aria-hidden="true"></i>
                </button>
            </div>
            <button class="btn btn-sm btn-outline-secondary management-issued-invoice-icon management-issued-invoice-attachment" type="button" title="Wgrywanie dokumentu nie jest jeszcze dost&#281;pne" aria-label="Wgrywanie dokumentu nie jest jeszcze dost&#281;pne" disabled>
                <i class="bi bi-paperclip" aria-hidden="true"></i>
            </button>
        </div>
    @endif
</div>
