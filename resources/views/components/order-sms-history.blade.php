@foreach($messages as $sms)
    @php
        $statusClass = match ($sms->status) {
            'submitted' => 'is-sent',
            'failed', 'expired', 'cancelled' => 'is-failed',
            'uncertain' => 'is-unknown',
            default => 'is-pending',
        };
    @endphp
    <article class="order-email-message order-sms-message" data-sms-id="{{ $sms->id }}">
        <div class="order-email-message-meta">
            <strong>{{ $sms->actor_name }}</strong>
            <span>{{ ($sms->completed_at ?? $sms->created_at)?->format('d.m.Y H:i') }}</span>
            <span class="order-email-channel"><i class="bi bi-chat-text-fill" aria-hidden="true"></i> SMS</span>
            <span class="order-email-status order-sms-status {{ $statusClass }}" @if($sms->status === 'submitted') title="Telefon zgłosił wykonanie akcji SMS. To nie jest potwierdzenie doręczenia." tabindex="0" @endif>{{ $sms->status === 'submitted' ? 'Wysłane' : $sms->label() }}</span>
        </div>
        <div class="order-email-message-heading"><strong>Do:</strong> {{ $sms->recipient }}</div>
        <div class="order-email-message-body order-sms-message-body">{{ $sms->body }}</div>
        @if($sms->status === 'uncertain')<div class="alert alert-danger py-2 px-3 mt-2 mb-0">Nie wiadomo, czy SMS został wysłany. Sprawdź telefon przed ponowną wysyłką.</div>@endif
        @if(in_array($sms->status, ['queued','claimed'], true))<button class="btn btn-sm btn-outline-secondary mt-2" type="button" data-sms-cancel="{{ route('orders.sms.cancel', [$order, $sms]) }}"><i class="bi bi-x-lg me-1"></i>Anuluj zlecenie</button>@endif
    </article>
@endforeach
