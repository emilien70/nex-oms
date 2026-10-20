@php
    $pickupRows = [
        'Nazwa' => ['pickup_point_name', $order->pickup_point_name],
        'ID' => ['pickup_point_id', $order->pickup_point_id],
        'Adres' => ['pickup_point_address', $order->pickup_point_address],
    ];
@endphp
<div class="nex-address-grid">
    @foreach ($pickupRows as $label => [$field, $value])
        <div class="inline-field-row" data-edit-field="{{ $field }}">
            <div class="nex-label">{{ $label }}</div>
            <div class="nex-value">@include('orders.partials.inline-edit-value', compact('field', 'value', 'label'))</div>
        </div>
    @endforeach
    <div class="inline-field-row inline-split-row" data-edit-field="pickup_point_postal_code">
        <div class="nex-label">Kod i miasto</div>
        <div class="nex-value inline-postal-city">
            @include('orders.partials.inline-edit-value', ['field' => 'pickup_point_postal_code', 'value' => $order->pickup_point_postal_code, 'label' => 'Kod pocztowy'])
            @include('orders.partials.inline-edit-value', ['field' => 'pickup_point_city', 'value' => $order->pickup_point_city, 'label' => 'Miasto'])
        </div>
    </div>
</div>
