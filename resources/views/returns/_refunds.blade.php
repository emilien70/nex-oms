@forelse ($refunds as $refund)
    @php($allocationState = $refundService->allocationState($refund, $orderReturn))
    <article class="border-bottom py-3" style="font-size:12px">
        <div class="d-flex flex-wrap justify-content-between gap-2"><span>{{ $refund->requested_at->timezone(config('app.timezone'))->format('d.m.Y H:i:s') }}</span><strong class="text-{{ $refund->status === 'SUCCESS' ? 'success' : 'warning-emphasis' }}">{{ $refund->statusLabel() }}</strong></div>
        <div class="mt-1">Kwota zlecenia: <strong>{{ $money->format($refund->total_value) }} {{ $refund->currency }}</strong></div>
        <div>Produkty: {{ $refund->products_value !== null ? $money->format($refund->products_value).' '.$refund->currency : 'Nieustalony podział' }}</div>
        <div>Dostawa: {{ $money->format($refund->delivery_value) }} {{ $refund->currency }}</div>
        @if ($refund->other_value !== '0.00')<div>Pozostałe składniki: {{ $money->format($refund->other_value) }} {{ $refund->currency }}</div>@endif
        <div class="returns-muted mt-1" style="overflow-wrap:anywhere">ID refundacji: {{ $refund->external_refund_id }}</div>
        <div class="returns-muted">Powód: {{ $refund->reason }}</div>
        @if ($refund->seller_comment)<div class="returns-comment">{{ $refund->seller_comment }}</div>@endif
        @if ($refund->sync_error)<div class="text-warning-emphasis mt-1">{{ $refund->sync_error }}</div>@endif
        @foreach ($refund->allocations as $allocation)
            @if ($allocation->products_value !== '0.00' || $allocation->delivery_value !== '0.00')
                <div class="mt-1">Zwrot {{ $allocation->return_number }}: produkty {{ $money->format($allocation->products_value) }}, dostawa {{ $money->format($allocation->delivery_value) }} {{ $allocation->currency }}</div>
            @endif
        @endforeach
        @if ($allocationState['can_allocate'])
            <details class="mt-2"><summary>Przypisanie do zwrotu {{ $orderReturn->id }}</summary>
                <form method="POST" action="{{ route('returns.refunds.allocate', [$orderReturn, $refund]) }}" class="mt-2">
                    @csrf @method('PATCH')
                    <input type="hidden" name="expected_refund_version" value="{{ $refund->lock_version }}"><input type="hidden" name="expected_return_version" value="{{ $orderReturn->lock_version }}">
                    <div class="row g-2">
                        <div class="col-6"><label class="form-label" for="refundProducts{{ $refund->id }}">Produkty ({{ $refund->currency }})</label><input class="form-control form-control-sm" type="number" id="refundProducts{{ $refund->id }}" name="products_value" min="0" max="{{ $allocationState['products_max'] }}" step="0.01" value="{{ $allocationState['current']?->products_value ?? '0.00' }}" required></div>
                        <div class="col-6"><label class="form-label" for="refundDelivery{{ $refund->id }}">Dostawa ({{ $refund->currency }})</label><input class="form-control form-control-sm" type="number" id="refundDelivery{{ $refund->id }}" name="delivery_value" min="0" max="{{ $allocationState['delivery_max'] }}" step="0.01" value="{{ $allocationState['current']?->delivery_value ?? '0.00' }}" required></div>
                    </div>
                    <button class="btn btn-sm btn-outline-primary mt-2" type="submit"><i class="bi bi-check-lg" aria-hidden="true"></i> Zapisz przypisanie</button>
                </form>
            </details>
        @endif
        @if ($allocationState['managed'])<div class="small text-secondary mt-2">Rozliczenie zlecenia zwrotu wpłaty OMS.</div>@endif
        @if (! $allocationState['managed'] && $allocationState['current'] && ($allocationState['current']->products_value !== '0.00' || $allocationState['current']->delivery_value !== '0.00'))
            <form method="POST" action="{{ route('returns.refunds.allocate', [$orderReturn, $refund]) }}" class="mt-2">@csrf @method('PATCH')
                <input type="hidden" name="expected_refund_version" value="{{ $refund->lock_version }}"><input type="hidden" name="expected_return_version" value="{{ $orderReturn->lock_version }}"><input type="hidden" name="products_value" value="0.00"><input type="hidden" name="delivery_value" value="0.00">
                <button class="btn btn-sm btn-outline-secondary" type="submit"><i class="bi bi-x-circle" aria-hidden="true"></i> Wyzeruj przypisanie</button>
            </form>
        @endif
    </article>
@empty
    <div class="returns-muted py-2">Brak pobranych refundacji tego zamówienia.</div>
@endforelse
@if ($refunds->hasPages()){{ $refunds->links() }}@endif
@if ($refundEvents->isNotEmpty())
    <details class="mt-2"><summary>Historia odczytów i zmian refundacji</summary>
        @foreach ($refundEvents as $refundEvent)
            <div class="border-bottom py-2" style="font-size:12px">{{ $refundEvent->observed_at->timezone(config('app.timezone'))->format('d.m.Y H:i:s') }}<br>{{ $refundEvent->data['status'] }}: {{ $money->format($refundEvent->data['total_value']) }} {{ $refundEvent->data['currency'] }}</div>
        @endforeach
        @if ($refundEvents->hasPages()){{ $refundEvents->links() }}@endif
    </details>
@endif
