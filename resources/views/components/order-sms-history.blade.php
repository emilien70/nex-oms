@foreach($messages as $sms)
    <article class="border-top py-3 small" data-sms-id="{{ $sms->id }}">
        <div class="d-flex flex-wrap justify-content-between gap-2"><strong><i class="bi bi-chat-text me-1" aria-hidden="true"></i>SMS: {{ $sms->recipient }}</strong><span>{{ $sms->created_at->format('d.m.Y H:i:s') }}</span></div>
        <div class="text-secondary mt-1">{{ $sms->label() }} · {{ $sms->source === 'automation' ? 'Automatyczna akcja' : 'Recznie' }} · {{ $sms->parts }} cz. @if($sms->template_name_snapshot) · {{ $sms->template_name_snapshot }} @endif</div>
        <div class="mt-2" style="white-space:pre-wrap;overflow-wrap:anywhere">{{ $sms->body }}</div>
        @if($sms->status === 'submitted')<div class="text-secondary mt-1">Telefon zglosil wykonanie akcji SMS. To nie jest potwierdzenie doreczenia.</div>@endif
        @if($sms->status === 'uncertain')<div class="text-danger mt-1">Nie wiadomo, czy SMS zostal wyslany. Sprawdz telefon przed ponowna wysylka.</div>@endif
        @if(in_array($sms->status, ['queued','claimed'], true))<button class="btn btn-sm btn-outline-secondary mt-2" type="button" data-sms-cancel="{{ route('orders.sms.cancel', [$order, $sms]) }}"><i class="bi bi-x-lg me-1"></i>Anuluj zlecenie</button>@endif
    </article>
@endforeach
