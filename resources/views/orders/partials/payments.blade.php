@forelse ($order->payNowPayments as $payment)
    <div class="nex-timeline-item">
        <div class="d-flex flex-wrap align-items-start justify-content-between gap-2">
            <div>
                <div class="small text-secondary">
                    {{ $payment->confirmed_at?->format('Y-m-d H:i') ?? $payment->updated_at?->format('Y-m-d H:i') }}
                </div>
                <div class="fw-semibold">Zarejestrowano płatność PayNow</div>
            </div>
            <div class="fw-semibold">{{ str_replace('.', ',', (string) $payment->amount) }} {{ $payment->currency }}</div>
        </div>
        <div class="small text-secondary mt-1">ID PayNow: {{ $payment->provider_payment_id }}</div>
        @if ($payment->needs_review)
            <div class="small text-warning-emphasis mt-1">
                <i class="bi bi-exclamation-triangle me-1" aria-hidden="true"></i>
                Wymaga sprawdzenia
                @if ($payment->safe_error_message)
                    — {{ $payment->safe_error_message }}
                @endif
            </div>
        @elseif ($payment->accounted_at)
            <div class="small text-success mt-1">
                <i class="bi bi-check-circle me-1" aria-hidden="true"></i>
                Zaksięgowano automatycznie
            </div>
        @endif
    </div>
@empty
    <div class="text-secondary">Brak zarejestrowanych płatności PayNow.</div>
@endforelse
