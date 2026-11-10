<span class="custom-event-badge">
    <span @class(['custom-event-symbol', 'text-dark' => $event->color === '#FFFFFF']) style="--event-color: {{ $event->color }}">
        <i class="bi bi-{{ $event->icon }}" aria-hidden="true"></i>
        @if ($withSpinner ?? false)
            <span class="quick-action-spinner spinner-border" aria-hidden="true"></span>
        @endif
    </span>
    <span class="custom-event-label">{{ $event->buttonLabel() }}</span>
</span>
