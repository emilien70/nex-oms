@php
    $moneyValue = fn ($value) => number_format((float) $value, 2, ',', ' ');
    $paidAmount = (float) $order->paid_amount;
    $totalGross = (float) $order->total_gross;
    $isFullyPaid = $totalGross > 0 && abs($paidAmount - $totalGross) < 0.005;
    $paidBadgeClass = $paidAmount <= 0 ? 'bg-secondary' : ($isFullyPaid ? 'bg-success' : 'bg-danger');
    $sourceOptions = $sourceOptions ?? [
        'manual' => html_entity_decode('R&#281;czne', ENT_QUOTES, 'UTF-8'),
        'allegro' => 'Allegro',
        'prestashop' => 'PrestaShop',
    ];
    $prestashopSourceName = $order->source === 'prestashop'
        ? trim((string) $order->prestashopOrderLinks->first()?->account?->name)
        : '';
    $sourceLabel = $prestashopSourceName !== ''
        ? $prestashopSourceName
        : ($sourceOptions[$order->source] ?? $order->source);
    if ($order->source === 'allegro' && $order->allegroOrderLink) {
        $sourceLabel = $order->allegroOrderLink->account?->name ?: $order->allegroOrderLink->account_name;
    }
@endphp

<div class="paid-amount-row" data-paid-view>
    <div class="nex-label">Zap&#322;acono:</div>
    <button class="badge border-0 {{ $paidBadgeClass }}" type="button" data-paid-edit-open aria-label="Edytuj wp&#322;at&#281;">{{ $moneyValue($order->paid_amount) }} {{ $order->currency }}</button>
    <span class="text-secondary">z {{ $moneyValue($order->total_gross) }} {{ $order->currency }}</span>
    <div class="paid-amount-action-group" role="group" aria-label="Akcje p&#322;atno&#347;ci">
        <form method="POST" action="{{ route('orders.recalculate-total', $order) }}" data-order-ajax-form>
            @csrf
            @method('PATCH')
            <button class="paid-amount-action-button" type="submit" title="Przelicz &#322;&#261;czn&#261; warto&#347;&#263;" aria-label="Przelicz &#322;&#261;czn&#261; warto&#347;&#263;">
                <i class="bi bi-arrow-clockwise" aria-hidden="true"></i>
            </button>
        </form>
        <button class="paid-amount-action-button" type="button" data-paid-edit-open title="Edytuj wp&#322;at&#281;" aria-label="Edytuj wp&#322;at&#281;">
            <i class="bi bi-pencil" aria-hidden="true"></i>
        </button>
        @if ($order->payment_status === \App\Services\OrderPaymentStateService::STATUS_UNPAID)
            <button
                class="paid-amount-action-button is-paynow-action"
                type="button"
                aria-label="Generuj link p&#322;atno&#347;ci"
                data-payment-link-action
                data-payment-link-url="{{ route('orders.paynow.payment-link', $order) }}"
                data-bs-toggle="tooltip"
                data-bs-placement="top"
                data-bs-trigger="hover focus"
                data-bs-html="true"
                data-bs-custom-class="paynow-link-tooltip"
                data-bs-title="Generuj link p&#322;atno&#347;ci.<br>Pojedyncze klikni&#281;cie: kopiuj do schowka<br>Podw&oacute;jne klikni&#281;cie: otw&oacute;rz link."
            >
                <i class="bi bi-link-45deg" aria-hidden="true"></i>
            </button>
        @endif
    </div>
</div>
<form method="POST" action="{{ route('orders.paid-amount.update', $order) }}" class="paid-amount-edit align-items-center flex-wrap gap-2" data-paid-edit data-total="{{ (float) $order->total_gross }}" data-order-ajax-form>
    @csrf
    @method('PATCH')
    <input class="form-control form-control-sm paid-amount-input" type="number" step="0.01" min="0" max="{{ (float) $order->total_gross }}" name="paid_amount" value="{{ old('paid_amount', $order->paid_amount) }}" data-paid-input>
    <span class="text-secondary small">z {{ $moneyValue($order->total_gross) }} {{ $order->currency }}</span>
    <button class="mini-icon-button border-primary text-primary" type="submit" data-paid-submit aria-label="Zapisz wp&#322;at&#281;">&#10003;</button>
    <button class="mini-icon-button text-success" type="button" data-paid-set="{{ (float) $order->total_gross }}" aria-label="Ustaw pe&#322;n&#261; kwot&#281;">{{ number_format((float) $order->total_gross, 2, '.', '') }}</button>
    <button class="mini-icon-button text-danger" type="button" data-paid-set="0.00" aria-label="Ustaw zero">0.00</button>
    <button class="mini-icon-button" type="button" data-paid-cancel aria-label="Anuluj edycj&#281; wp&#322;aty">&#10005;</button>
</form>

@php
    $infoSections = [
        [
            'customer_login' => ['Klient (login):', $order->customer_login],
            'customer_email' => ['E-mail:', $order->customer_email],
            'customer_phone' => ['Telefon:', $order->customer_phone],
            'source' => ['Źródło:', $sourceLabel],
        ],
        [
            'shipping_method' => ['Sposób wysyłki:', $order->shipping_method],
            'cash_on_delivery' => ['Pobranie:', $order->cash_on_delivery ? 'Tak' : 'Nie'],
            'delivery_cost_gross' => ['Koszt wysyłki:', $order->allegroOrderLink && ! $order->allegroOrderLink->delivery_cost_known ? 'Nieustalony' : $moneyValue($order->delivery_cost_gross).' '.$order->currency],
            'payment_method' => ['Sposób płatności:', $order->payment_method],
        ],
        ['notes' => ['Uwagi:', $order->notes]],
    ];
@endphp
@foreach ($infoSections as $infoRows)
    <div class="order-info-section">
        @foreach ($infoRows as $field => [$label, $value])
            <div class="order-info-row inline-field-row inline-edit-trigger" data-edit-section="order-info" data-edit-field="{{ $field }}">
                <div class="nex-label">{{ $label }}</div>
                <div class="nex-value">@include('orders.partials.inline-edit-value', compact('field', 'label', 'value'))</div>
            </div>
        @endforeach
    </div>
@endforeach
