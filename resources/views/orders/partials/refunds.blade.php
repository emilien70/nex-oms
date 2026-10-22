@php
    $refundService = app(\App\Services\OrderReturnRefundService::class);
    $refundIdentity = $refundService->orderIdentity($order);
    $orderRefunds = $refundService->query($refundIdentity)->with('allocations')->orderByDesc('requested_at')->orderByDesc('id')->paginate(20, ['*'], 'order_refund_page')->withQueryString();
    $refundMoney = app(\Modules\Invoices\Services\InvoiceMoneyFormatter::class);
@endphp
@if ($refundIdentity)
    <h3 class="h6 mt-3">Zwroty wpłat Allegro</h3>
    @forelse ($orderRefunds as $refund)
        <div class="nex-timeline-item">
            <div class="d-flex flex-wrap justify-content-between gap-2"><span class="small text-secondary">{{ $refund->requested_at->timezone(config('app.timezone'))->format('d.m.Y H:i:s') }}</span><strong>{{ $refundMoney->format($refund->total_value) }} {{ $refund->currency }}</strong></div>
            <div class="fw-semibold text-{{ $refund->status === 'SUCCESS' ? 'success' : 'warning-emphasis' }}">{{ $refund->statusLabel() }}</div>
            <div class="small">Produkty: {{ $refund->products_value !== null ? $refundMoney->format($refund->products_value).' '.$refund->currency : 'Nieustalony podział' }}; dostawa: {{ $refundMoney->format($refund->delivery_value) }} {{ $refund->currency }}</div>
            @if ($refund->other_value !== '0.00')<div class="small">Pozostałe składniki: {{ $refundMoney->format($refund->other_value) }} {{ $refund->currency }}</div>@endif
            <div class="small text-secondary" style="overflow-wrap:anywhere">ID refundacji: {{ $refund->external_refund_id }}</div>
            @if ($refund->sync_error)<div class="small text-warning-emphasis">{{ $refund->sync_error }}</div>@endif
            @foreach ($refund->allocations as $allocation)
                @if ($allocation->order_return_id && ($allocation->products_value !== '0.00' || $allocation->delivery_value !== '0.00'))<a class="small d-block" href="{{ route('returns.show', $allocation->order_return_id) }}">Zwrot {{ $allocation->return_number }}</a>@endif
            @endforeach
        </div>
    @empty
        <div class="text-secondary small">Brak pobranych refundacji tego zamówienia.</div>
    @endforelse
    @if ($orderRefunds->hasPages()){{ $orderRefunds->links() }}@endif
@endif
