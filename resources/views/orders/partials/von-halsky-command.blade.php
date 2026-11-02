@if (app(\Modules\Integrations\VonHalsky\Services\VonHalskyStatusMappingService::class)->ready())
    @php($halskyCommand = $order->vonHalskyOrderLink?->latestCommand)
    <div class="border-top mt-2 pt-2" data-von-halsky-command>
        <div class="text-muted">Przekazanie statusu do Von Halsky:</div>
        @if ($halskyCommand)
            <div class="d-flex flex-wrap gap-2 align-items-baseline">
                <span>{{ $halskyCommand->action->label() }}</span>
                <span class="{{ $halskyCommand->state === 'succeeded' ? 'text-success' : (in_array($halskyCommand->state, ['blocked', 'failed', 'uncertain'], true) ? 'text-warning' : 'text-muted') }}">{{ $halskyCommand->stateLabel() }}</span>
            </div>
            @if ($halskyCommand->last_error)
                <div class="small mt-1" role="status">{{ $halskyCommand->last_error }}</div>
            @endif
            @if ($halskyCommand->state === 'failed' && ! $halskyCommand->retried_at && $order->vonHalskyOrderLink->account?->status_sync_enabled)
                <form method="POST" action="{{ route('orders.von-halsky-command.retry', [$order, $halskyCommand->id]) }}" class="mt-2">
                    @csrf
                    <input type="hidden" name="expected_lock_version" value="{{ $halskyCommand->lock_version }}">
                    <button class="btn btn-sm btn-outline-secondary" type="submit" onclick="this.disabled = true; this.form.requestSubmit();"><i class="bi bi-arrow-repeat me-1" aria-hidden="true"></i>Ponów</button>
                </form>
            @endif
        @else
            <div>Nie przekazano</div>
        @endif
    </div>
@endif
