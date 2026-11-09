<div {{ $attributes->class(['orders-list-toolbar', 'mb-3']) }} data-list-toolbar-slot>
    {{ $slot }}
</div>
<div data-list-toolbar-dock role="group" aria-label="Dolny pasek listy" hidden inert>
    <div class="orders-list-toolbar">{{ $slot }}</div>
</div>
