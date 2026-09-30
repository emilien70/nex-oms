@php
    $actorName = data_get($message->actor_snapshot, 'name')
        ?: ($message->source === \App\Enums\OrderEmailMessageSource::Automation ? 'Automatyczna akcja' : 'Operator NEX-OMS');
    $statusClass = match ($message->status) {
        \App\Enums\OrderEmailMessageStatus::Sent => 'is-sent',
        \App\Enums\OrderEmailMessageStatus::Failed => 'is-failed',
        \App\Enums\OrderEmailMessageStatus::Unknown => 'is-unknown',
        default => 'is-pending',
    };
@endphp

<article
    class="order-email-message"
    data-email-message-id="{{ $message->getKey() }}"
    data-email-status-url="{{ route('orders.email-messages.status', [$order, $message]) }}"
    data-email-terminal="{{ $message->status->isTerminal() ? '1' : '0' }}"
>
    <div class="order-email-message-meta">
        <strong>{{ $actorName }}</strong>
        <span>{{ ($message->sent_at ?? $message->created_at)?->format('d.m.Y H:i') }}</span>
        <span class="order-email-channel"><i class="bi bi-envelope-fill" aria-hidden="true"></i> E-mail</span>
        <span class="order-email-status {{ $statusClass }}">{{ $message->status->label() }}</span>
        <span class="text-secondary">{{ $message->source->label() }}</span>
    </div>
    <div class="order-email-message-heading"><strong>Temat wiadomości:</strong> {{ $message->subject }}</div>
    <div class="order-email-message-heading"><strong>Do:</strong> {{ $message->to_email }}</div>
    <div class="order-email-message-body">
        @if ($message->format === \App\Enums\EmailTemplateFormat::Html)
            {!! $message->body_html !!}
        @else
            {!! nl2br(e($message->body_text)) !!}
        @endif
    </div>

    @if ($message->attachments->isNotEmpty())
        <div class="order-email-message-attachments">
            <strong>Wysłane załączniki:</strong>
            @foreach ($message->attachments as $attachment)
                <a href="{{ route('orders.email-messages.attachments.show', [$order, $message, $attachment]) }}">
                    <i class="bi bi-paperclip" aria-hidden="true"></i>{{ $attachment->file_name }}
                </a>
            @endforeach
        </div>
    @endif

    @if ($message->safe_error_message)
        <div class="alert alert-danger py-2 px-3 mt-3 mb-0">{{ $message->safe_error_message }}</div>
    @endif
</article>
