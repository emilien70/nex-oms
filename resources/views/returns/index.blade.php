@extends('layouts.app')
@section('title', 'Zwroty - NEX-OMS')
@section('content')
@include('orders.partials.list-styles')
@php
    $selectedStatus = $returnStatuses->firstWhere('id', (int) ($filters['return_status'] ?? 0));
    $sourceLabels = ['manual' => 'Ręczne', 'prestashop' => 'PrestaShop', 'allegro' => 'Allegro'];
    $starLabels = ['' => 'Usuń oznaczenie', 'orange' => 'Pomarańczowa', 'navy' => 'Granatowa', 'green' => 'Zielona', 'blue' => 'Niebieska', 'red' => 'Czerwona'];
@endphp
<style>
    .return-list-indicators{display:grid;grid-template-columns:repeat(3,22px);gap:2px;align-self:flex-start;flex-shrink:0}
    .return-list-indicators span{width:22px;height:22px;display:flex;align-items:center;justify-content:center;border-radius:2px;color:white;background:#9ca3af;font-size:16px;font-weight:700}
    .return-list-indicators .is-active{background:#429de0}.return-list-indicators .is-completed{background:#147a49}
    .return-bulk-selected{font-size:12px;color:#64748b}.returns-filter-heading{font-size:13px}
</style>
<div class="orders-page">
    @foreach ($syncAccounts as $syncAccount)
        @if ($syncAccount->returns_error)<div class="alert alert-warning py-2">{{ $syncAccount->name }}: {{ $syncAccount->returns_error }}</div>@endif
    @endforeach
    @if ($errors->any())<div class="alert alert-danger py-2" role="alert"><ul class="mb-0">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
    <header class="orders-header mb-4">
        <h1 class="orders-status-heading">@if ($selectedStatus)<span class="orders-status-heading-dot" style="background:{{ $selectedStatus->color }}"></span>@endif<span>{{ $selectedStatus?->name ?? 'Wszystkie zwroty' }}</span></h1>
        <div class="orders-top-actions">
            <a class="orders-context-add orders-context-add--return d-md-none" href="{{ route('returns.create') }}"><i class="bi bi-plus-circle orders-context-plus" aria-hidden="true"></i>Stwórz zwrot</a>
            <button class="orders-filters-toggle {{ count(array_filter($filters)) ? 'is-active' : '' }}" type="button" data-bs-toggle="collapse" data-bs-target="#returnsFiltersPanel" aria-expanded="{{ count(array_filter($filters)) ? 'true' : 'false' }}" aria-controls="returnsFiltersPanel"><span class="orders-filters-toggle-icon"><i class="bi bi-filter-left" aria-hidden="true"></i></span><span>Filtrowanie zwrotów</span><i class="bi bi-chevron-down orders-filters-toggle-chevron" aria-hidden="true"></i></button>
        </div>
    </header>
    <div class="collapse {{ count(array_filter($filters)) ? 'show' : '' }}" id="returnsFiltersPanel">
        <form class="orders-filters-panel" method="GET" action="{{ route('returns.index') }}">
            <input type="hidden" name="per_page" value="{{ $perPage }}">
            <div class="orders-filters-grid">
                <div><label class="form-label" for="returnSearch">Szukaj</label><input class="form-control" id="returnSearch" name="q" value="{{ $filters['q'] ?? '' }}"></div>
                <div><label class="form-label" for="returnStatus">Status zwrotu</label><select class="form-select" id="returnStatus" name="return_status"><option value="">Wszystkie</option>@foreach ($returnStatuses as $status)<option value="{{ $status->id }}" @selected($selectedStatus?->id === $status->id)>{{ $status->name }}</option>@endforeach</select></div>
                <div><label class="form-label" for="returnSource">Źródło zamówienia</label><select class="form-select" id="returnSource" name="source"><option value="">Wszystkie</option>@foreach (collect($sourceLabels)->keys()->merge(\App\Models\OrderReturn::query()->distinct()->pluck('source'))->unique() as $source)<option value="{{ $source }}" @selected(($filters['source'] ?? '') === $source)>{{ $sourceLabels[$source] ?? $source }}</option>@endforeach</select></div>
                <div><label class="form-label" for="returnAccount">Integracja</label><select class="form-select" id="returnAccount" name="account"><option value="">Wszystkie</option>@foreach ($accountNames->filter() as $name)<option @selected(($filters['account'] ?? '') === $name)>{{ $name }}</option>@endforeach</select></div>
                <div><label class="form-label" for="returnMethod">Utworzenie</label><select class="form-select" id="returnMethod" name="creation_method"><option value="">Wszystkie</option><option value="manual" @selected(($filters['creation_method'] ?? '') === 'manual')>Ręcznie</option><option value="allegro_import" @selected(($filters['creation_method'] ?? '') === 'allegro_import')>Import Allegro</option></select></div>
                <div><label class="form-label" for="returnFrom">Data od</label><input class="form-control" type="date" id="returnFrom" name="from" value="{{ $filters['from'] ?? '' }}"></div>
                <div><label class="form-label" for="returnTo">Data do</label><input class="form-control" type="date" id="returnTo" name="to" value="{{ $filters['to'] ?? '' }}"></div>
                <div><label class="form-label" for="returnSort">Kolejność</label><select class="form-select" id="returnSort" name="sort">@foreach (['newest'=>'Najnowsze', 'oldest'=>'Najstarsze', 'amount_desc'=>'Kwota malejąco (według walut)', 'amount_asc'=>'Kwota rosnąco (według walut)'] as $value=>$label)<option value="{{ $value }}" @selected(($filters['sort'] ?? 'newest') === $value)>{{ $label }}</option>@endforeach</select></div>
                <div class="d-flex align-items-center gap-2"><input class="form-check-input" type="checkbox" id="returnStarred" name="starred" value="1" @checked($filters['starred'] ?? false)><label for="returnStarred">Oznaczone gwiazdką</label></div>
            </div>
            <div class="d-flex gap-2 mt-3"><button class="btn btn-sm btn-primary" type="submit"><i class="bi bi-search" aria-hidden="true"></i> Szukaj</button><a class="btn btn-sm btn-outline-secondary" href="{{ route('returns.index') }}">Wyczyść</a></div>
        </form>
    </div>
    <form id="bulkReturnsForm" method="POST" action="{{ route('returns.bulk-status') }}">@csrf</form>
    <div class="orders-list-toolbar mb-3">
        <div class="selection-menu dropdown">
            <button class="selection-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false" aria-label="Menu zaznaczania zwrotów"><i class="bi bi-check-square selection-checkbox-icon" aria-hidden="true"></i><i class="bi bi-chevron-down selection-caret" aria-hidden="true"></i></button>
            <div class="dropdown-menu selection-dropdown-menu">
                <button class="dropdown-item" type="button" data-return-select="all">Zaznacz wszystkie na stronie</button>
                <button class="dropdown-item" type="button" data-return-select="starred">Zaznacz oznaczone na stronie</button>
                <button class="dropdown-item" type="button" data-return-select="none">Odznacz wszystkie</button>
                <button class="dropdown-item" type="button" data-return-select="invert">Odwróć zaznaczenie</button>
            </div>
        </div>
        <div class="dropdown">
            <button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false" data-return-bulk disabled>Zmień status</button>
            <div class="dropdown-menu">@foreach ($returnStatuses as $status)<button class="dropdown-item" type="submit" form="bulkReturnsForm" name="local_status_id" value="{{ $status->id }}"><span class="bulk-status-color-dot" style="background:{{ $status->color }}"></span>{{ $status->name }}</button>@endforeach</div>
        </div>
        <span class="return-bulk-selected" data-return-selected></span>
        <x-pagination-toolbar :paginator="$returns" :per-page-options="[20,30,50,75,100,150,200]" :per-page="$perPage" aria-label="Paginacja zwrotów" />
    </div>
    <div class="orders-table-card"><div class="table-responsive"><table class="table orders-table align-middle" aria-label="Wszystkie zwroty">
        <colgroup><col style="width:22px"><col style="width:18px"><col style="width:96px"><col style="width:152px"><col><col style="width:104px"><col style="width:220px"><col style="width:118px"></colgroup>
        <thead><tr><th class="order-select-column"></th><th class="order-star-column"></th><th>Numer<br><span class="orders-th-subtitle fw-normal text-secondary">(Nr ref.)</span></th><th class="order-customer-column">Imię i nazwisko<br><span class="orders-th-subtitle fw-normal text-secondary">(źródło)</span></th><th>Przedmioty</th><th>Kwota</th><th class="order-extra-column">Informacje dodatkowe</th><th>Data utworzenia<br><span class="orders-th-subtitle fw-normal text-secondary">(w statusie)</span></th></tr></thead>
        <tbody>
        @forelse ($returns as $record)
            <tr>
                <td class="order-select-column"><input class="form-check-input" type="checkbox" form="bulkReturnsForm" name="return_ids[]" value="{{ $record->id }}" aria-label="Zaznacz zwrot {{ $record->id }}" data-return-checkbox data-starred="{{ $record->star_color ? '1' : '0' }}"><input type="hidden" form="bulkReturnsForm" name="versions[{{ $record->id }}]" value="{{ $record->lock_version }}"></td>
                <td class="order-star-column"><div class="dropdown">
                    <button class="star-button star-{{ $record->star_color ?: 'empty' }}" type="button" data-bs-toggle="dropdown" aria-label="Wybierz kolor oznaczenia zwrotu {{ $record->id }}" aria-expanded="false">{!! $record->star_color ? '&#9733;' : '&#9734;' !!}</button>
                    <div class="dropdown-menu star-picker"><div class="d-flex gap-1">@foreach ($starColors as $color)<form method="POST" action="{{ route('returns.star', $record) }}">@csrf @method('PATCH')<input type="hidden" name="expected_lock_version" value="{{ $record->lock_version }}"><input type="hidden" name="star_color" value="{{ $color }}"><button class="star-color-button star-{{ $color ?: 'empty' }}" title="{{ $starLabels[$color] }}" aria-label="{{ $starLabels[$color] }}" type="submit">{!! $color ? '&#9733;' : '&#9734;' !!}</button></form>@endforeach</div></div>
                </div></td>
                <td><a class="order-number-link" href="{{ route('returns.show', $record) }}">{{ $record->id }}</a>@if ($record->reference_number)<div class="order-subline">({{ $record->reference_number }})</div>@endif</td>
                <td>
                    <div class="order-customer-name">{{ $record->buyer_name ?: ($record->buyer_login ?: '...') }}</div>
                    <div class="order-subline order-source-line">
                        <span class="order-source-icon {{ $record->source === 'allegro' ? 'source-allegro' : ($record->source === 'prestashop' ? 'source-prestashop' : 'source-manual') }}" aria-hidden="true">
                            @if ($record->source === 'allegro')
                                al
                            @elseif ($record->source === 'prestashop')
                                <span class="source-cart-handle"></span>
                            @endif
                        </span>
                        @if ($record->source === 'allegro')
                            <span>(<button type="button" class="order-login-copy" data-return-copy="{{ $record->buyer_login }}" title="Kopiuj do schowka">{{ $record->buyer_login ?: '...' }}</button>){{ $record->account_name ? ' z '.$record->account_name : '' }}</span>
                        @else
                            <span>{{ $record->sourceLabel() }}</span>
                        @endif
                    </div>
                </td>
                <td><div class="order-items-list">@foreach ($record->items->take(3) as $item)<div>{{ $item->quantity }}x {{ $item->name }}</div>@endforeach @if ($record->items->count() > 3)<div class="order-subline">+{{ $record->items->count()-3 }} więcej</div>@endif</div></td>
                <td><span class="order-amount">{{ $money->format($record->total_value) }} {{ $record->currency }}</span></td>
                <td class="order-extra-column"><div class="order-extra-content"><div class="order-extra-text">
                    <span class="order-status-badge" style="background:{{ $record->localStatus?->color ?? '#9ca3af' }};color:{{ $record->localStatus?->textColor() ?? '#fff' }}">{{ $record->localStatusLabel() }}</span>
                    @if ($record->order && ! $record->order->trashed())<div class="order-subline"><a href="{{ route('orders.show', $record->order) }}">Zamówienie {{ $record->order_id }}</a></div>@endif
                </div><div class="return-list-indicators">
                    <span title="Zwrot środków: brak danych" aria-label="Zwrot środków: brak danych">$</span>
                    <span class="{{ $record->localStatus?->code === 'completed' ? 'is-completed' : (in_array($record->localStatus?->code, ['received','in_progress'], true) ? 'is-active' : '') }}" title="Obsługa: {{ $record->localStatusLabel() }}"><i class="bi bi-{{ $record->localStatus?->code === 'completed' ? 'box-seam' : 'check-lg' }}" aria-hidden="true"></i></span>
                    <span class="{{ $record->parcels->contains(fn ($parcel) => filled($parcel->waybill) || filled($parcel->transporting_waybill)) ? 'is-active' : '' }}" title="Przesyłki zwrotne: {{ $record->parcels->count() }}"><i class="bi bi-truck" aria-hidden="true"></i></span>
                    @if ($record->notes || $record->items->contains(fn ($item) => filled($item->comment)))<span title="Uwagi lub komentarz do zwrotu"><i class="bi bi-info-circle-fill" aria-hidden="true"></i></span>@endif
                    @if ($record->sync_error)<span title="{{ $record->sync_error }}" style="background:#d84738"><i class="bi bi-exclamation-lg" aria-hidden="true"></i></span>@endif
                </div></div></td>
                <td><div>{{ $record->submitted_at->timezone(config('app.timezone'))->format('d.m.Y H:i') }}</div><div class="order-subline">{{ $record->local_status_changed_at?->timezone(config('app.timezone'))->format('d.m.Y H:i') ?: '...' }}</div></td>
            </tr>
        @empty <tr><td colspan="8" class="text-center text-secondary py-4">Brak zwrotów do wyświetlenia.</td></tr> @endforelse
        </tbody>
    </table></div></div>
</div>
<script>
document.addEventListener('DOMContentLoaded', () => {
    const boxes = [...document.querySelectorAll('[data-return-checkbox]')];
    const update = () => { const count = boxes.filter(box => box.checked).length; document.querySelector('[data-return-bulk]').disabled = !count; document.querySelector('[data-return-selected]').textContent = count ? 'Zaznaczono: ' + count : ''; };
    boxes.forEach(box => box.addEventListener('change', update));
    document.querySelectorAll('[data-return-select]').forEach(button => button.addEventListener('click', () => {
        boxes.forEach(box => { box.checked = button.dataset.returnSelect === 'all' || (button.dataset.returnSelect === 'starred' && box.dataset.starred === '1') || (button.dataset.returnSelect === 'invert' && !box.checked); }); update();
    }));
    document.querySelectorAll('[data-return-copy]').forEach(button => button.addEventListener('click', async () => {
        if (!button.dataset.returnCopy) return;
        try { await navigator.clipboard.writeText(button.dataset.returnCopy); } catch { window.nexOmsShowActivity?.({tone:'error',title:'Schowek',message:'Nie udało się skopiować loginu.'}); }
    }));
});
</script>
@endsection
