@php
    $allegroInvoice = $allegroState;
    $isAllegroCorrection = $allegroDocument?->isCorrection();
    $allegroConfirm = $isAllegroCorrection
        ? 'Przekazać zamkniętą korektę do Allegro jako osobny dokument?'.(! empty($allegroInvoice['sourceWarning']) ? ' OMS nie potwierdził faktury źródłowej w Allegro. Sprawdź, czy znajduje się już przy zamówieniu.' : '')
        : 'Przekazać fakturę do Allegro?';
@endphp
@if ($allegroInvoice)
    @php($invoiceExport = $allegroInvoice['export'])
    <div class="small mt-2" style="grid-column: 1 / -1; min-width: 0; overflow-wrap: anywhere;" data-allegro-invoice-export data-allegro-document-id="{{ $allegroDocument?->id }}">
        @if (isset($errors) && $errors->has('allegro_invoice_export'))<div class="text-danger mb-1" role="alert">{{ $errors->first('allegro_invoice_export') }}</div>@endif
        @if ($allegroDocument)
            <div class="d-flex flex-wrap align-items-center gap-2">
                <span class="{{ $invoiceExport?->state === 'synced' ? 'text-success' : (in_array($invoiceExport?->state, ['failed', 'uncertain'], true) ? 'text-danger' : 'text-muted') }}">
                    <i class="bi {{ $invoiceExport?->state === 'synced' ? 'bi-check-circle' : 'bi-file-earmark-text' }} me-1" aria-hidden="true"></i>Allegro: {{ $invoiceExport?->label() ?? 'Nie przekazano' }}
                </span>
                @if ($allegroInvoice['canSend'])
                    <form class="flex-shrink-0" method="POST" action="{{ route('orders.allegro.invoices.store', ['order' => $order, 'invoice' => $allegroDocument]) }}" data-confirm-message="{{ $allegroConfirm }}" onsubmit="return confirm(this.dataset.confirmMessage)">
                        @csrf
                        <input type="hidden" name="confirmed" value="1">
                        <input type="hidden" name="expected_lock_version" value="{{ $allegroDocument->lock_version }}">
                        <button type="submit" class="btn btn-sm btn-outline-secondary" title="Przekaż do Allegro" aria-label="Przekaż do Allegro"><i class="bi bi-cloud-upload" aria-hidden="true"></i></button>
                    </form>
                @elseif ($allegroInvoice['canRetry'])
                    <form class="flex-shrink-0" method="POST" action="{{ route('orders.allegro.invoices.retry', ['order' => $order, 'export' => $invoiceExport]) }}" data-confirm-message="{{ $invoiceExport->upload_started_at ? 'Sprawdzić wynik w Allegro bez ponownego przesyłania pliku?' : $allegroConfirm }}" onsubmit="return confirm(this.dataset.confirmMessage)">
                        @csrf
                        <input type="hidden" name="confirmed" value="1">
                        <button type="submit" class="btn btn-sm btn-outline-secondary" title="{{ $invoiceExport->upload_started_at ? 'Sprawdź w Allegro' : 'Przekaż do Allegro' }}" aria-label="{{ $invoiceExport->upload_started_at ? 'Sprawdź w Allegro' : 'Przekaż do Allegro' }}"><i class="bi {{ $invoiceExport->upload_started_at ? 'bi-arrow-repeat' : 'bi-cloud-upload' }}" aria-hidden="true"></i></button>
                    </form>
                @endif
            </div>
            @if ($invoiceExport?->error && $invoiceExport->state !== 'waiting')<div class="text-danger mt-1">{{ $invoiceExport->error }}</div>@endif
            @if ($allegroInvoice['changed'])<div class="text-warning-emphasis mt-1">{{ $isAllegroCorrection ? 'Korekta zmieniła się' : 'Faktura zmieniła się' }} po przygotowaniu kopii dla Allegro. Plik w Allegro nie został podmieniony.</div>@endif
            @if (! empty($allegroInvoice['sourceWarning']) && $invoiceExport?->state !== 'waiting')<div class="text-warning-emphasis mt-1">OMS nie potwierdził przekazania faktury źródłowej do Allegro. Sprawdź ją przed ręcznym przekazaniem korekty.</div>@endif
        @endif
        @if ($allegroInvoice['historical'])
            <div class="text-warning-emphasis mt-1">Usunięta lokalnie faktura {{ $allegroInvoice['historical']->invoice_number }}: {{ $allegroInvoice['historical']->label() }}. Usunięcie w OMS nie usuwa dokumentu z Allegro.</div>
        @endif
    </div>
@endif
