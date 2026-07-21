<div class="nex-address-grid">
    <div class="inline-field-row"><div class="nex-label">Nazwa</div><div class="nex-value {{ $order->pickup_point_name ? '' : 'nex-empty' }}">{{ $order->pickup_point_name ?: '...' }}<span class="inline-pencil">&#9998;</span></div></div>
    <div class="inline-field-row"><div class="nex-label">ID</div><div class="nex-value {{ $order->pickup_point_id ? '' : 'nex-empty' }}">{{ $order->pickup_point_id ?: '...' }}<span class="inline-pencil">&#9998;</span></div></div>
    <div class="inline-field-row"><div class="nex-label">Adres</div><div class="nex-value {{ $order->pickup_point_address ? '' : 'nex-empty' }}">{{ $order->pickup_point_address ?: '...' }}<span class="inline-pencil">&#9998;</span></div></div>
    <div class="inline-field-row"><div class="nex-label">Kod i miasto</div><div class="nex-value {{ ($order->pickup_point_postal_code || $order->pickup_point_city) ? '' : 'nex-empty' }}">{{ trim(($order->pickup_point_postal_code ?? '') . ' ' . ($order->pickup_point_city ?? '')) ?: '...' }}<span class="inline-pencil">&#9998;</span></div></div>
</div>
