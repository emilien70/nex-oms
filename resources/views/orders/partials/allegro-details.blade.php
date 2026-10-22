@php
    $hasSnapshot = is_array($link->details_snapshot) && $link->details_snapshot !== [];
    $details = $link->details_snapshot ?? [];
    $detailDate = static fn ($value) => filled($value)
        ? \Carbon\CarbonImmutable::parse($value)->timezone(config('app.timezone'))->format('d.m.Y H:i:s') : '...';
    $detailMoney = static fn ($value) => is_array($value) ? $value['amount'].' '.$value['currency'] : '...';
    $total = $hasSnapshot ? $details['total'] : ['amount' => $order->total_gross, 'currency' => $order->currency ?: '...'];
    $deliveryCost = $hasSnapshot ? $details['delivery_cost'] : ['amount' => $order->delivery_cost_gross, 'currency' => $order->currency ?: '...'];
    $shippingMethod = $hasSnapshot ? $details['shipping_method'] : $order->shipping_method;
    $buyerMessage = $hasSnapshot ? $details['buyer_message'] : $link->buyer_message;
    $declaredPackages = $hasSnapshot ? $details['declared_packages'] : $link->declared_packages;
    $marketplace = $hasSnapshot ? $details['marketplace_id'] : $link->marketplace_id;
    $transactionUrl = \Modules\Integrations\Allegro\Support\AllegroOrderUrl::make($link->environment, $link->external_order_id);
    $provider = \Modules\Integrations\Allegro\Support\AllegroPaymentProvider::label($details['payment_provider'] ?? null);
    $paymentLabel = trim(($details['payment_type'] ?? '').' '.($provider ?: '')) ?: ($order->payment_method ?: '...');
    $paymentStatusLabel = match ($order->payment_status) {
        'paid' => 'Opłacone',
        'unpaid' => 'Nieopłacone',
        default => '...',
    };
    $deliveryAddress = $details['shipping_address'] ?? implode(', ', array_filter([
        $order->shipping_name, $order->shipping_company, $order->shipping_street,
        trim(($order->shipping_postal_code ?? '').' '.($order->shipping_city ?? '')),
    ], fn ($value) => filled($value)));
@endphp
<div class="allegro-order-details">
    <table class="allegro-delivery-windows">
        <tbody>
            @foreach ([['Wysyłka', 'dispatch', 'dispatch'], ['Dostawa', 'delivery', 'delivery']] as [$label, $key, $badge])
                @php
                    $to = $details[$key.'_to'] ?? null;
                    $days = filled($to) && $order->purchased_at
                        ? (int) $order->purchased_at->toImmutable()->timezone(config('app.timezone'))->startOfDay()
                            ->diffInDays(\Carbon\CarbonImmutable::parse($to)->timezone(config('app.timezone'))->startOfDay(), false)
                        : null;
                @endphp
                <tr>
                    <td>{{ $label }}</td>
                    <td>od: {{ $detailDate($details[$key.'_from'] ?? null) }}<br>do: {{ $detailDate($to) }}</td>
                    <td>@if ($days !== null)<span class="allegro-detail-badge is-{{ $badge }}" title="Liczba dni od daty zakupu">{{ $days }} {{ $days === 1 ? 'dzień' : 'dni' }}</span>@endif</td>
                </tr>
            @endforeach
        </tbody>
    </table>
    <div>Łączna cena: {{ $detailMoney($total) }}, Cena wysyłki: {{ $detailMoney($deliveryCost) }}, Nazwa wysyłki: {{ $shippingMethod ?: '...' }}</div>
    <div>Płatność: {{ $paymentLabel }}, Status płatności: {{ $paymentStatusLabel }}</div>
    <div>Komentarz kupującego: <span class="allegro-buyer-message">{{ $buyerMessage }}</span></div>
    <div>Dostawa: {{ $deliveryAddress ?: '...' }}</div>
    <div>Faktura: {{ $link->import_state === 'incomplete' ? 'Nieustalone' : (($details['invoice_required'] ?? $link->invoice_required) ? 'Tak' : 'Nie') }}</div>

    <div class="allegro-detail-group">
        Allegro SMART: <span class="allegro-detail-badge is-dispatch">{{ $link->import_state === 'incomplete' ? 'Nieustalone' : (($details['smart'] ?? $link->smart) ? 'Tak' : 'Nie') }}</span>
    </div>
    <div>Deklarowana ilość paczek: {{ $declaredPackages ?? '...' }}</div>

    <div class="allegro-detail-group">Zwrot prowizji: <button type="button" class="allegro-inline-link" data-bs-toggle="collapse" data-bs-target="#orderAllegroFeesCollapse" aria-controls="orderAllegroFeesCollapse" title="Sprawdź uznania w rozliczeniach tego zamówienia">Szukaj</button></div>
    <div>Szczegóły: @if ($transactionUrl)<a href="{{ $transactionUrl }}" target="_blank" rel="noopener noreferrer">Karta transakcji</a>@else ... @endif</div>
    <div>Pobrano: {{ $detailDate($link->imported_at) }}</div>
    <div>Źródło: {{ $marketplace ?: '...' }}</div>
    @if ($link->status_sync_state)
        <div class="allegro-detail-group">
            Status realizacji Allegro: {{ \Modules\Integrations\Allegro\Enums\AllegroFulfillmentStatus::tryFrom($link->fulfillment_status ?? '')?->label() ?? ($link->fulfillment_status ?: '...') }}
        </div>
        <div>Przekazywanie statusu: {{ ['pending' => 'Oczekuje', 'synced' => 'Potwierdzone', 'failed' => 'Błąd', 'skipped' => 'Pominięto'][$link->status_sync_state] ?? $link->status_sync_state }}</div>
        @if ($link->status_sync_error)<div class="text-danger text-break" role="alert">{{ $link->status_sync_error }}</div>@endif
        @if ($link->status_sync_state === 'failed')
            <form method="POST" action="{{ route('orders.allegro.status.retry', $order) }}" class="mt-2">@csrf
                <button class="btn btn-sm btn-outline-primary" type="submit"><i class="bi bi-arrow-clockwise me-1" aria-hidden="true"></i>Ponów przekazanie statusu</button>
            </form>
        @endif
    @endif
    @error('allegro_status_sync')<div class="text-danger text-break" role="alert">{{ $message }}</div>@enderror
</div>
