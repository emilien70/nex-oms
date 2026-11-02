@if ($order->source === 'von_halsky' && $order->vonHalskyOrderLink)
    @php($halskyLink = $order->vonHalskyOrderLink)
    <div class="d-flex flex-wrap gap-2 align-items-baseline" data-von-halsky-status="{{ $halskyLink->external_status }}">
        <span class="text-muted">Status Von Halsky:</span>
        <span>
            {{ $halskyLink->externalOrderStatus()->label() }}
            @if ($halskyLink->externalOrderStatus() === \Modules\Integrations\VonHalsky\Enums\VonHalskyOrderStatus::Unknown)
                <span class="text-muted">({{ $halskyLink->external_status }})</span>
            @endif
            @if (! $halskyLink->last_status_checked_at)
                <i class="bi bi-info-circle text-muted ms-1" title="Status zapisany przy imporcie. Nie sprawdzono go jeszcze po imporcie." aria-label="Status zapisany przy imporcie"></i>
            @endif
        </span>
    </div>
    @if ($halskyLink->last_status_sync_error)
        <div class="text-warning small mt-2" role="status">{{ $halskyLink->last_status_sync_error }}</div>
    @endif
    @include('orders.partials.von-halsky-command', ['order' => $order])
@endif
