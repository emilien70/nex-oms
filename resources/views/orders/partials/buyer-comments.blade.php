@if (filled($buyerComment = $order->allegroOrderLink?->buyer_message))
    <article class="order-buyer-comment" aria-label="Komentarz do zamówienia">
        <div class="order-buyer-comment-header">
            <span>{{ $order->allegroOrderLink->buyer_name ?: ($order->shipping_name ?: $order->customer_login) }}:</span>
            <time title="Data zakupu">{{ $order->purchased_at?->format('d.m.Y H:i') }}</time>
            <span class="order-buyer-comment-label"><i class="bi bi-chat" aria-hidden="true"></i> Komentarz do zamówienia.</span>
        </div>
        <div class="order-buyer-comment-body">{{ $buyerComment }}</div>
    </article>
@endif
@foreach ($order->prestashopBuyerMessages as $buyerComment)
    <article class="order-buyer-comment" aria-label="Komentarz do zamówienia" data-prestashop-comment-id="{{ $buyerComment->external_message_id }}">
        <div class="order-buyer-comment-header">
            <span>{{ $buyerComment->author_name }}:</span>
            <time title="Data komentarza w PrestaShop">{{ $buyerComment->source_created_at?->format('d.m.Y H:i') }}</time>
            <span class="order-buyer-comment-label"><i class="bi bi-chat" aria-hidden="true"></i> Komentarz do zamówienia.</span>
        </div>
        <div class="order-buyer-comment-body">{{ $buyerComment->message }}</div>
    </article>
@endforeach
