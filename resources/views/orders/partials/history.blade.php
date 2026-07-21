@forelse ($order->events as $event)
    <div class="nex-timeline-item">
        <div class="small text-secondary">{{ $event->created_at?->format('Y-m-d H:i') }}</div>
        <div class="fw-semibold">{{ $event->title }}</div>
        @if ($event->description)
            <div>{{ $event->description }}</div>
        @endif
    </div>
@empty
    <div class="text-secondary">Brak historii zam&oacute;wienia.</div>
@endforelse
