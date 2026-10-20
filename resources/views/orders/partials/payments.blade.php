@php
    $prestashopPayments = $order->prestashopOrderLinks
        ->flatMap(fn ($link) => $link->payments)
        ->sortByDesc(fn ($payment) => $payment->paid_at?->getTimestamp() ?? $payment->id);
    $allegroPayments = $order->allegroOrderLink?->payments ?? collect();
@endphp

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
@endforelse

@foreach ($prestashopPayments as $payment)
    <div class="nex-timeline-item">
        <div class="d-flex flex-wrap align-items-start justify-content-between gap-2">
            <div>
                <div class="small text-secondary">
                    {{ $payment->paid_at?->format('Y-m-d H:i') ?? $payment->updated_at?->format('Y-m-d H:i') }}
                </div>
                <div class="fw-semibold">Zarejestrowano płatność PrestaShop</div>
            </div>
            <div class="fw-semibold">{{ str_replace('.', ',', (string) $payment->amount) }} {{ $payment->currency }}</div>
        </div>
        @if ($payment->method)
            <div class="small text-secondary mt-1">Metoda: {{ $payment->method }}</div>
        @endif
        @if ($payment->transaction_id)
            <div class="small text-secondary">ID transakcji: {{ $payment->transaction_id }}</div>
        @endif
        @if ($payment->accounting_status === 'review')
            <div class="small text-warning-emphasis mt-1">
                <i class="bi bi-exclamation-triangle me-1" aria-hidden="true"></i>
                Wymaga sprawdzenia
                @if ($payment->safe_error_message)
                    — {{ $payment->safe_error_message }}
                @endif
            </div>
        @elseif ($payment->accounting_status === 'mirrored')
            <div class="small text-secondary mt-1">
                <i class="bi bi-link-45deg me-1" aria-hidden="true"></i>
                Płatność była już zaksięgowana przez PayNow
            </div>
        @elseif ($payment->accounted_at)
            <div class="small text-success mt-1">
                <i class="bi bi-check-circle me-1" aria-hidden="true"></i>
                Zaksięgowano automatycznie
            </div>
        @else
            <div class="small text-secondary mt-1">Oczekuje na zaksięgowanie</div>
        @endif
    </div>
@endforeach

@foreach ($allegroPayments as $payment)
    <div class="nex-timeline-item">
        <div class="d-flex flex-wrap align-items-start justify-content-between gap-2">
            <div>
                <div class="small text-secondary">{{ $payment->paid_at?->format('Y-m-d H:i') ?? $payment->created_at?->format('Y-m-d H:i') }}</div>
                <div class="fw-semibold">Płatność Allegro</div>
            </div>
            <div class="fw-semibold">{{ $payment->amount !== null ? str_replace('.', ',', (string) $payment->amount).' '.$payment->currency : 'Brak wpłaty' }}</div>
        </div>
        <div class="small text-secondary mt-1" style="overflow-wrap: anywhere">ID płatności Allegro: {{ $payment->external_payment_id }}</div>
        <div class="small text-secondary">{{ $payment->type }}{{ $payment->provider ? ' / '.\Modules\Integrations\Allegro\Support\AllegroPaymentProvider::label($payment->provider) : '' }}</div>
        <div class="small {{ $payment->accounted_at ? 'text-success' : 'text-secondary' }} mt-1">{{ $payment->accounted_at ? 'Zaksięgowano podczas importu' : ($payment->type === 'CASH_ON_DELIVERY' ? 'Pobranie' : 'Płatność niezakończona') }}</div>
    </div>
@endforeach

@if ($order->payNowPayments->isEmpty() && $prestashopPayments->isEmpty() && $allegroPayments->isEmpty())
    <div class="text-secondary">Brak zarejestrowanych płatności.</div>
@endif
