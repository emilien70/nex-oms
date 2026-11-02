@if ($order->source === 'von_halsky' && $order->vonHalskyOrderLink?->externalOrderStatus()->warning())
    <div class="alert alert-warning" role="status" data-von-halsky-warning>
        {{ $order->vonHalskyOrderLink->externalOrderStatus()->warning() }}
    </div>
@endif
