<nav class="nav nav-tabs mb-3" aria-label="Automatyczne akcje">
    @foreach ([
        'orders.automatic-actions.index' => 'Automatyczne akcje',
        'orders.custom-events.index' => 'Zdarzenia własne',
        'orders.automatic-action-groups.index' => 'Grupy akcji',
    ] as $route => $label)
        <a href="{{ route($route) }}" @class(['nav-link', 'active' => request()->routeIs($route)])
            @if (request()->routeIs($route)) aria-current="page" @endif>{{ $label }}</a>
    @endforeach
</nav>
