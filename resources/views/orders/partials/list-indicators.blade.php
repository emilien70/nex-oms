@php
    $complete = $order->purchaseIsComplete();
    $link = $order->allegroOrderLink;
    $cancelled = $link?->import_state === 'cancelled' || $link?->external_status === 'CANCELLED';
    $paymentColor = $order->cash_on_delivery ? 'cod' : ($order->payment_status === 'paid' ? 'confirmed' : 'inactive');
    if (\Brick\Math\BigDecimal::of((string) $order->paid_amount)->isGreaterThan(0) && $order->payment_status !== 'paid' && ! $order->cash_on_delivery) {
        $paymentColor = 'attention';
    }
    $readinessTitle = $cancelled
        ? trim('Zamówienie anulowane w Allegro. '.($link?->processing_error ?? ''))
        : ($complete ? 'Kompletne dane zamówienia' : ($link?->processing_error ?: 'Zakup niepełny. Oczekujemy na dokończenie w Allegro.'));
@endphp
<div class="order-indicators" aria-label="Informacje o zamówieniu">
    <span class="order-indicator {{ $cancelled ? 'cancelled' : ($complete ? 'confirmed' : 'inactive') }}" title="{{ $readinessTitle }}" aria-label="{{ $readinessTitle }}">{{ $cancelled ? '!!' : 'T' }}</span>
    <span class="order-indicator {{ $paymentColor }}" title="{{ $order->cash_on_delivery ? 'Płatność za pobraniem' : ($order->payment_status === 'paid' ? 'Opłacone' : 'Brak pełnej wpłaty') }}" aria-label="Płatność">$</span>
    <span class="order-indicator {{ $order->shipments_exists ? 'shipping' : 'inactive' }} order-indicator-delivery" title="{{ $order->shipments_exists ? 'Utworzono przesyłkę z numerem nadania' : 'Brak przesyłki' }}" aria-label="Przesyłka"><i class="bi bi-truck" aria-hidden="true"></i></span>
    @if ($order->issued_invoice_exists)
        <span class="order-indicator invoice" title="Wystawiono fakturę VAT" aria-label="Wystawiono fakturę VAT"><i class="bi bi-file-earmark-fill" aria-hidden="true"></i><span class="invoice-lines" aria-hidden="true"></span><span class="invoice-currency" aria-hidden="true">$</span></span>
    @endif
    @if (filled($link?->buyer_message) || $order->prestashop_buyer_messages_exists)
        <span class="order-indicator inactive" title="Komentarz kupującego do zamówienia" aria-label="Komentarz kupującego"><i class="bi bi-envelope" aria-hidden="true"></i></span>
    @endif
    @if (filled($order->notes))
        <span class="order-indicator inactive" title="{{ $order->notes }}" aria-label="Uwagi do zamówienia"><i class="bi bi-info-circle-fill" aria-hidden="true"></i></span>
    @endif
</div>
