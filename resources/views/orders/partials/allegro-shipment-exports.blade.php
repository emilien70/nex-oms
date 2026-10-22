@if ($shipment->order?->source === 'allegro')
    @php
        $currentNumbers = \Modules\Integrations\Allegro\Support\AllegroShipmentNumbers::current($shipment);
        $allegroExports = $shipment->allegroExports->filter(fn ($export) => isset($currentNumbers[$export->carrier_id.'|'.$export->waybill]));
    @endphp
    <div class="mt-1" style="font-size: 11px; line-height: 16px; overflow-wrap: anywhere" aria-label="Przekazywanie numerów do Allegro">
        @error('allegro_shipment_export')<div class="text-danger" role="alert">{{ $message }}</div>@enderror
        @forelse ($allegroExports as $export)
            <div class="d-flex flex-wrap align-items-center gap-1 {{ $export->state === 'synced' ? 'text-success' : ($export->state === 'failed' ? 'text-danger' : 'text-secondary') }}">
                <i class="bi {{ $export->state === 'synced' ? 'bi-check-circle-fill' : ($export->state === 'failed' ? 'bi-exclamation-circle-fill' : 'bi-clock') }}" aria-hidden="true"></i>
                <span title="{{ $export->error ?: ($export->confirmed_at?->format('d.m.Y H:i:s') ?: $export->stateLabel()) }}">Allegro: {{ $export->stateLabel() }}@if ($export->waybill !== $shipment->tracking_number) &middot; {{ $export->waybill }}@endif</span>
                @if ($export->state === 'failed')
                    <form method="POST" action="{{ route('orders.allegro.shipments.retry', ['order' => $shipment->order_id, 'export' => $export]) }}" class="d-inline">
                        @csrf
                        <button type="submit" class="btn btn-link btn-sm p-0" title="Ponów przekazanie numeru do Allegro" aria-label="Ponów przekazanie numeru {{ $export->waybill }} do Allegro"><i class="bi bi-arrow-clockwise" aria-hidden="true"></i></button>
                    </form>
                    <div class="w-100" role="alert">{{ $export->error }}</div>
                @endif
            </div>
        @empty
            <span class="text-secondary">Allegro: brak potwierdzenia</span>
        @endforelse
    </div>
@endif
