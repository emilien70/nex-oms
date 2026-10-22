@php
    $sidebarStatuses = \App\Models\ReturnStatusSetting::query()->orderBy('sort_order')->orderBy('id')->get();
    $sidebarCounts = \App\Models\OrderReturn::query()->selectRaw('local_status_id, COUNT(*) as total')->groupBy('local_status_id')->pluck('total', 'local_status_id');
@endphp
<aside class="orders-context-sidebar" aria-label="Menu zwrotów">
    <div class="orders-context-inner">
        <div class="orders-context-add-form">
            <a class="orders-context-add orders-context-add--return" href="{{ route('returns.create') }}"><i class="bi bi-plus-circle orders-context-plus" aria-hidden="true"></i>Stwórz zwrot</a>
        </div>
        <nav class="orders-context-list">
            <a class="orders-context-link orders-context-link-all {{ request()->routeIs('returns.index') && ! request()->filled('return_status') ? 'active' : '' }}" href="{{ route('returns.index') }}"><span class="orders-context-all-icon" aria-hidden="true"></span><span class="orders-context-link-label">Wszystkie</span></a>
            @foreach ($sidebarStatuses as $sidebarStatus)
                <a class="orders-context-link {{ (string) request('return_status') === (string) $sidebarStatus->id ? 'active' : '' }}" href="{{ route('returns.index', ['return_status' => $sidebarStatus->id]) }}">
                    <span class="orders-context-count" style="background:{{ $sidebarStatus->color }};color:{{ $sidebarStatus->textColor() }}">{{ $sidebarCounts[$sidebarStatus->id] ?? 0 }}</span><span class="orders-context-link-label">{{ $sidebarStatus->name }}</span>
                </a>
            @endforeach
            <div class="orders-context-footer"><a class="orders-context-add-status" href="{{ route('settings.return-statuses.index') }}">+ Dodaj status</a><a class="orders-context-refresh" href="{{ request()->fullUrl() }}" title="Odśwież listę i liczniki zwrotów" aria-label="Odśwież listę i liczniki zwrotów"><i class="bi bi-arrow-clockwise" aria-hidden="true"></i></a></div>
        </nav>
    </div>
</aside>
