@foreach ($refundCommands as $refundCommand)
    <div class="border-bottom py-2">
        <div class="d-flex flex-wrap justify-content-between gap-2">
            <strong>{{ $refundCommand->stateLabel() }}</strong>
            <span>{{ $refundCommand->total_value }} {{ $refundCommand->currency }}</span>
        </div>
        <div class="small text-secondary">{{ $refundCommand->created_at->format('d.m.Y H:i:s') }} · Produkty: {{ $refundCommand->products_value }} · Dostawa: {{ $refundCommand->delivery_value }}</div>
        @if ($refundCommand->error)<div class="small text-warning-emphasis">{{ $refundCommand->error }}</div>@endif
        @if ($refundCommand->external_refund_id)<div class="small text-secondary text-break">ID refundacji: {{ $refundCommand->external_refund_id }}</div>@endif
        @if (isset($orderReturn) && $refundCommand->state === 'uncertain' && ! $refundCommand->external_refund_id)
            <details class="mt-2"><summary>Uzgodnij z refundacją w Allegro</summary>
                <form method="POST" action="{{ route('returns.refund-payment.reconcile', [$orderReturn, $refundCommand]) }}" class="mt-2">
                    @csrf
                    <label class="form-label" for="refundIdentity{{ $refundCommand->id }}">ID refundacji z Allegro</label>
                    <input class="form-control form-control-sm" id="refundIdentity{{ $refundCommand->id }}" name="external_refund_id" maxlength="36" required>
                    <div class="form-check my-2"><input class="form-check-input" type="checkbox" name="confirmed_identity" value="1" id="refundMatch{{ $refundCommand->id }}" required><label class="form-check-label small" for="refundMatch{{ $refundCommand->id }}">Potwierdzam w Allegro, że to wynik tego zlecenia, a nie innego zwrotu.</label></div>
                    <button class="btn btn-sm btn-outline-secondary" type="submit"><i class="bi bi-link-45deg me-1" aria-hidden="true"></i>Sprawdź i powiąż</button>
                </form>
            </details>
        @endif
    </div>
@endforeach
@if ($refundCommands->hasPages()){{ $refundCommands->links() }}@endif
