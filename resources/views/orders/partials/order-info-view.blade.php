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
@endphp

<div class="paid-amount-row" data-paid-view>
    <div class="nex-label">Zap&#322;acono:</div>
    <button class="badge border-0 {{ $paidBadgeClass }}" type="button" data-paid-edit-open aria-label="Edytuj wp&#322;at&#281;">{{ $moneyValue($order->paid_amount) }} {{ $order->currency }}</button>
    <span class="text-secondary">z {{ $moneyValue($order->total_gross) }} {{ $order->currency }}</span>
    <form method="POST" action="{{ route('orders.recalculate-total', $order) }}" class="ms-auto" data-order-ajax-form>
        @csrf
        @method('PATCH')
        <button class="mini-icon-button" type="submit" title="Przelicz &#322;&#261;czn&#261; warto&#347;&#263;" aria-label="Przelicz &#322;&#261;czn&#261; warto&#347;&#263;">&#8635;</button>
    </form>
    <button class="mini-icon-button" type="button" data-paid-edit-open title="Edytuj wp&#322;at&#281;" aria-label="Edytuj wp&#322;at&#281;">&#9998;</button>
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

<div class="order-info-section">
    <div class="order-info-row inline-field-row inline-edit-trigger" data-edit-section="order-info"><div class="nex-label">Klient (login):</div><div class="nex-value {{ $order->customer_login ? '' : 'nex-empty' }}">{{ $order->customer_login ?: '...' }}<span class="inline-pencil">&#9998;</span></div></div>
    <div class="order-info-row inline-field-row inline-edit-trigger" data-edit-section="order-info"><div class="nex-label">E-mail:</div><div class="nex-value {{ $order->customer_email ? '' : 'nex-empty' }}">{{ $order->customer_email ?: '...' }}<span class="inline-pencil">&#9998;</span></div></div>
    <div class="order-info-row inline-field-row inline-edit-trigger" data-edit-section="order-info"><div class="nex-label">Telefon:</div><div class="nex-value {{ $order->customer_phone ? '' : 'nex-empty' }}">{{ $order->customer_phone ?: '...' }}<span class="inline-pencil">&#9998;</span></div></div>
    <div class="order-info-row inline-field-row inline-edit-trigger" data-edit-section="order-info"><div class="nex-label">&#377;r&oacute;d&#322;o:</div><div class="nex-value">{{ $sourceOptions[$order->source] ?? $order->source }}<span class="inline-pencil">&#9998;</span></div></div>
</div>
<div class="order-info-section">
    <div class="order-info-row inline-field-row inline-edit-trigger" data-edit-section="order-info"><div class="nex-label">Spos&oacute;b wysy&#322;ki:</div><div class="nex-value {{ $order->shipping_method ? '' : 'nex-empty' }}">{{ $order->shipping_method ?: '...' }}<span class="inline-pencil">&#9998;</span></div></div>
    <div class="order-info-row inline-field-row inline-edit-trigger" data-edit-section="order-info"><div class="nex-label">Pobranie:</div><div class="nex-value">{{ $order->cash_on_delivery ? 'Tak' : 'Nie' }}<span class="inline-pencil">&#9998;</span></div></div>
    <div class="order-info-row inline-field-row inline-edit-trigger" data-edit-section="order-info"><div class="nex-label">Koszt wysy&#322;ki:</div><div class="nex-value">{{ $moneyValue($order->delivery_cost_gross) }} {{ $order->currency }}<span class="inline-pencil">&#9998;</span></div></div>
    <div class="order-info-row inline-field-row inline-edit-trigger" data-edit-section="order-info"><div class="nex-label">Spos&oacute;b p&#322;atno&#347;ci:</div><div class="nex-value {{ $order->payment_method ? '' : 'nex-empty' }}">{{ $order->payment_method ?: '...' }}<span class="inline-pencil">&#9998;</span></div></div>
</div>
<div class="order-info-section">
    <div class="order-info-row inline-field-row inline-edit-trigger" data-edit-section="order-info"><div class="nex-label">Uwagi:</div><div class="nex-value {{ $order->notes ? '' : 'nex-empty' }}">{{ $order->notes ?: '...' }}<span class="inline-pencil">&#9998;</span></div></div>
</div>
