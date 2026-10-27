@php
    $printerChoices = $nexPrinterChoices ?? collect();
    $printButtonClass = $printButtonClass ?? 'btn btn-outline-secondary';
    $printButtonLabel = $printButtonLabel ?? 'Drukuj';
    $printIconOnly = $printIconOnly ?? false;
    $printMenuAlign = $printMenuAlign ?? 'dropdown-menu-end';
    $printTooltip = 'Drukuj - prześlij bezpośrednio do drukarki';
    $printUrl = $printUrl ?? route('invoices.nex-printer.store', $invoice);
    $printOrderLabel = $printOrderLabel ?? ($invoice->order_id !== null ? 'Zamówienie '.$invoice->order_reference_snapshot : '');
    $printOrderUrl = $printOrderUrl ?? ($invoice->order_id !== null ? route('orders.show', $invoice->order_id) : '');
@endphp

<div
    class="dropdown nex-printer-print-dropdown"
    data-nex-printer-dropdown
    data-print-url="{{ $printUrl }}"
    data-idempotency-key="{{ (string) \Illuminate\Support\Str::uuid() }}"
    data-order-label="{{ $printOrderLabel }}"
    data-order-url="{{ $printOrderUrl }}"
    data-nex-printer-tooltip
    data-bs-placement="top"
    data-bs-title="{{ $printTooltip }}"
>
    <button
        class="{{ $printButtonClass }}"
        type="button"
        data-bs-toggle="dropdown"
        data-bs-auto-close="true"
        data-nex-printer-toggle
        aria-label="{{ $printTooltip }}"
        aria-expanded="false"
        @if ($invoicePrintButton ?? false) data-invoice-print-button @endif
        @if ($correctionPrintButton ?? false) data-correction-print-button @endif
    >
        <i class="bi bi-printer {{ $printIconOnly ? '' : 'me-1' }}" aria-hidden="true"></i>
        @unless ($printIconOnly)
            <span>{{ $printButtonLabel }}</span>
        @endunless
    </button>
    <ul class="dropdown-menu {{ $printMenuAlign }} nex-printer-print-menu">
        @forelse ($printerChoices as $printer)
            <li>
                <button
                    class="dropdown-item"
                    type="button"
                    data-nex-printer-option
                    data-printer-id="{{ $printer->getKey() }}"
                >{{ $printer->display_name }} ({{ $printer->agent->name }})</button>
            </li>
        @empty
            <li><span class="dropdown-item-text text-secondary">Brak aktywnych drukarek PDF</span></li>
        @endforelse
    </ul>
</div>
