@extends('layouts.app')

@section('title', 'Zwrot '.$orderReturn->id.' - NEX-OMS')

@section('content')
@include('returns._styles')
<div class="returns-page">
    <header class="returns-header">
        <div class="returns-heading">
            <h1>Zwrot {{ $orderReturn->id }}</h1>
            <span class="customer">{{ $orderReturn->buyer_name ?: $orderReturn->buyer_login }}</span>
        </div>
        <nav class="returns-navigation" aria-label="Nawigacja zwrotów">
            @if ($previous)<a class="btn arrow" href="{{ route('returns.show', $previous) }}" title="Poprzedni zwrot" aria-label="Poprzedni zwrot"><i class="bi bi-chevron-left"></i></a>@else<span class="btn arrow disabled" aria-hidden="true"><i class="bi bi-chevron-left"></i></span>@endif
            @if ($next)<a class="btn arrow" href="{{ route('returns.show', $next) }}" title="Następny zwrot" aria-label="Następny zwrot"><i class="bi bi-chevron-right"></i></a>@else<span class="btn arrow disabled" aria-hidden="true"><i class="bi bi-chevron-right"></i></span>@endif
            <a class="btn back" href="{{ route('returns.index') }}"><i class="bi bi-arrow-return-left"></i> Powrót do listy zwrotów</a>
        </nav>
    </header>

    @if ($orderReturn->sync_error)<div class="alert alert-warning mt-3">{{ $orderReturn->sync_error }}</div>@endif
    @if (! $order && $orderReturn->creation_method === 'allegro_import')<div class="alert alert-secondary mt-3">Nie powiązano zwrotu z zamówieniem w OMS.</div>@elseif ($order?->trashed())<div class="alert alert-secondary mt-3">Powiązane zamówienie znajduje się w koszu.</div>@endif
    @if ($errors->any())<div class="alert alert-danger mt-3"><ul class="mb-0">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif

    <section class="returns-card returns-products" aria-label="Produkty zwrotu">
        <div class="returns-table-wrap">
            <table class="returns-table">
                <thead><tr><th class="photo" aria-label="Zdjęcie"><i class="bi bi-camera" aria-hidden="true"></i></th><th>ID PROD.</th><th class="name">NAZWA PRODUKTU</th><th class="metric">ILOŚĆ</th><th class="metric">CENA</th><th class="metric">WARTOŚĆ</th></tr></thead>
                <tbody>
                @foreach ($orderReturn->items as $item)
                    <tr>
                        <td>@if ($item->product && ($item->product->thumbnail_path || $item->product->image_path))<img class="returns-thumb" src="{{ route('products.image', [$item->product, $item->product->thumbnail_path ? 'thumbnail' : 'original']) }}" alt="" loading="lazy">@else<span class="returns-thumb"><i class="bi bi-image" aria-hidden="true"></i></span>@endif</td>
                        <td>@if ($item->product)<a href="{{ route('products.index', ['q' => $item->product_id, 'status' => 'all']) }}">{{ $item->product_id }}</a>@else<span class="returns-muted">...</span>@endif</td>
                        <td class="name">{{ $item->name }}<div class="returns-reason">Powód: {{ $item->reasonLabel() }}</div>@if ($item->comment)<div class="returns-comment">{{ $item->comment }}</div>@endif</td>
                        <td class="metric">{{ $item->quantity }}</td><td class="metric">{{ $money->format($item->unit_price) }} {{ $item->currency }}</td><td class="metric">{{ $money->format($item->total_value) }} {{ $item->currency }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </section>

    <div class="returns-grid">
        <section class="returns-card">
            <h2>Informacje o zwrocie</h2>
            <form method="POST" action="{{ route('returns.status', $orderReturn) }}" class="d-flex gap-2 px-3 mb-3">@csrf @method('PATCH')<input type="hidden" name="expected_lock_version" value="{{ $orderReturn->lock_version }}"><label class="visually-hidden" for="localReturnStatus">Status zwrotu</label><select class="form-select form-select-sm" id="localReturnStatus" name="local_status_id">@foreach ($returnStatuses as $returnStatus)<option value="{{ $returnStatus->id }}" @selected($orderReturn->local_status_id === $returnStatus->id)>{{ $returnStatus->name }}</option>@endforeach</select><button class="btn btn-sm btn-outline-secondary" type="submit" title="Zmień status zwrotu" aria-label="Zmień status zwrotu"><i class="bi bi-check-lg"></i></button></form>
            <div class="returns-body"><dl class="returns-fields mb-0">
                <dt>Status:</dt><dd><span class="returns-badge" style="background:{{ $orderReturn->localStatus?->color ?? '#9ca3af' }};color:{{ $orderReturn->localStatus?->textColor() ?? '#fff' }}">{{ $orderReturn->localStatusLabel() }}</span></dd>
                <dt>Numer referencyjny:</dt><dd>{{ $orderReturn->reference_number ?: '...' }}</dd>
                <dt>Wartość produktów:</dt><dd>{{ $money->format($orderReturn->total_value) }} {{ $orderReturn->currency }}</dd>
                <dt>Zwrócono środki:</dt><dd class="returns-muted">Brak danych</dd>
                <dt>Data utworzenia:</dt><dd>{{ $orderReturn->submitted_at->timezone(config('app.timezone'))->format('d.m.Y H:i:s') }}</dd>
                <dt>Data w statusie:</dt><dd>{{ $orderReturn->local_status_changed_at?->timezone(config('app.timezone'))->format('d.m.Y H:i:s') ?: '...' }}</dd>
                <dt>Źródło:</dt><dd>{{ $orderReturn->sourceLabel() }}</dd>
                <dt>Utworzenie:</dt><dd>{{ $orderReturn->creation_method === 'manual' ? 'Ręcznie' : 'Import Allegro' }}</dd>
                @if ($orderReturn->creation_method === 'allegro_import')
                <dt>Status Allegro:</dt><dd>{{ $orderReturn->statusLabel() }}</dd>
                <dt>Status odczytano:</dt><dd>{{ $orderReturn->status_observed_at?->timezone(config('app.timezone'))->format('d.m.Y H:i:s') ?: '...' }}</dd>
                <dt>Marketplace:</dt><dd>{{ $orderReturn->marketplace_id ?: '...' }}</dd>
                <dt>Zewnętrzny numer:</dt><dd class="returns-link-break">{{ $orderReturn->external_return_id }}</dd>
                @endif
                @if ($orderReturn->notes)<dt>Uwagi:</dt><dd class="returns-comment">{{ $orderReturn->notes }}</dd>@endif
            </dl></div>
        </section>
        <section class="returns-card">
            <h2>Zamówienie i dokumenty</h2>
            <div class="returns-body"><dl class="returns-fields mb-0">
                <dt>Zamówienie:</dt><dd>@if ($order && ! $order->trashed())<a href="{{ route('orders.show', $order) }}">{{ $order->id }} <i class="bi bi-box-arrow-up-right" aria-hidden="true"></i></a>@elseif ($order){{ $order->id }} (w koszu)@else{{ $orderReturn->order_snapshot['order_id'] ?? '...' }}@endif</dd>
                @if ($orderReturn->external_order_id)<dt>Transakcja:</dt><dd class="returns-link-break"><a href="https://{{ $orderReturn->environment === 'sandbox' ? 'salescenter.allegro.com.allegrosandbox.pl' : 'salescenter.allegro.com' }}/orders/{{ rawurlencode($orderReturn->external_order_id) }}" target="_blank" rel="noopener noreferrer">{{ $orderReturn->external_order_id }}</a></dd>@elseif ($order?->external_id || ! empty($orderReturn->order_snapshot['order_external_id']))<dt>Numer zewnętrzny:</dt><dd>{{ $order?->external_id ?: $orderReturn->order_snapshot['order_external_id'] }}</dd>@endif
                <dt>Dokumenty:</dt><dd class="documents">
                    @forelse (($order?->invoices ?? collect())->filter(fn ($invoice) => $invoice->status === \Modules\Invoices\Enums\InvoiceDocumentStatus::Issued && filled($invoice->number)) as $invoice)
                        <div><a href="{{ route('invoices.pdf', $invoice) }}" target="_blank" rel="noopener"><i class="bi bi-file-earmark-text" aria-hidden="true"></i> {{ $invoice->number }}</a></div>
                    @empty <span class="returns-muted">Nie wystawiono</span> @endforelse
                </dd>
            </dl></div>
        </section>
        <section class="returns-card">
            <h2>Dane klienta</h2>
            <div class="returns-body"><dl class="returns-fields mb-0">
                <dt>Imię i nazwisko:</dt><dd>{{ $orderReturn->buyer_name ?: '...' }}</dd>
                <dt>Klient (login):</dt><dd>{{ $orderReturn->buyer_login ?: '...' }}</dd>
                <dt>E-mail:</dt><dd>{{ $orderReturn->buyer_email ?: '...' }}</dd>
                <dt>Telefon:</dt><dd>{{ $orderReturn->buyer_phone ?: '...' }}</dd>
            </dl></div>
        </section>
        <section class="returns-card returns-span-2">
            <h2>Adres dostawy (zamówienie)</h2>
            <div class="returns-body"><dl class="returns-fields mb-0">
                <dt>Imię i nazwisko:</dt><dd>{{ $address->name ?? '...' }}</dd>
                <dt>Firma:</dt><dd>{{ ($address->company_name ?? null) ?: '...' }}</dd>
                <dt>Adres:</dt><dd>{{ \App\Support\AddressLineFormatter::formatAddressLine($address->street ?? null, $address->building_number ?? null, $address->apartment_number ?? null) ?: '...' }}</dd>
                <dt>Kod i miasto:</dt><dd>{{ ($address->postal_code ?? null) ?: '...' }}&nbsp; {{ ($address->city ?? null) ?: '...' }}</dd>
                <dt>Województwo:</dt><dd>{{ ($address->province ?? null) ?: '...' }}</dd>
                <dt>Kraj:</dt><dd>{{ $countries->name($address->country_code ?? null) ?: (($address->country_code ?? null) ?: '...') }}</dd>
            </dl></div>
        </section>
    </div>

    <section class="returns-card returns-section">
        <h2>Przesyłki (zamówienie)</h2>
        <div class="returns-body returns-table-wrap"><table class="returns-small-table">
            <thead><tr><th>DATA UTWORZENIA</th><th>PRZEWOŹNIK</th><th>NAZWA KONTA</th><th>NUMER NADAWCZY</th><th>STATUS</th></tr></thead>
            <tbody>
            @forelse ($orderShipments as $row)
                <tr><td>{{ $row['shipment']->created_at?->timezone(config('app.timezone'))->format('d.m.Y H:i') }}</td><td>{{ $row['carrier'] ?: '...' }}</td><td>{{ $row['shipment']->courierAccount?->name ?: '...' }}</td><td class="text-primary">{{ $row['waybill'] }}</td><td>{{ $row['shipment']->statusLabel() }}</td></tr>
            @empty <tr><td colspan="5" class="returns-empty">Brak przesyłek zamówienia.</td></tr> @endforelse
            </tbody>
        </table></div>
    </section>
    <section class="returns-card returns-section">
        <h2>Przesyłki zwrotne</h2>
        <div class="returns-body returns-table-wrap"><table class="returns-small-table">
            <thead><tr><th>DATA UTWORZENIA</th><th>PRZEWOŹNIK</th><th>NUMER NADAWCZY</th><th>PRZEWOŹNIK DORĘCZAJĄCY</th><th>NUMER DORĘCZAJĄCY</th></tr></thead>
            <tbody>
            @forelse ($orderReturn->parcels as $parcel)
                <tr><td>{{ $parcel->submitted_at?->timezone(config('app.timezone'))->format('d.m.Y H:i') ?: '...' }}</td><td>{{ $parcel->carrier_id ?: '...' }}</td><td>{{ $parcel->waybill ?: '...' }}</td><td>{{ $parcel->transporting_carrier_id ?: '...' }}</td><td>{{ $parcel->transporting_waybill ?: '...' }}</td></tr>
            @empty <tr><td colspan="5" class="returns-empty">Brak przesyłek zwrotnych.</td></tr> @endforelse
            </tbody>
        </table></div>
    </section>

    <div class="returns-grid">
        <section class="returns-card returns-span-2">
            <h2>Wymiana wiadomości (zamówienie)</h2>
            @forelse ($messages as $message)
                <article class="returns-message">
                    <div class="returns-message-meta"><strong>{{ $message->subject }}</strong><span>{{ $message->created_at->timezone(config('app.timezone'))->format('d.m.Y H:i') }}</span><span>{{ $message->status->label() }}</span></div>
                    <div class="returns-message-meta">Do: {{ $message->to_email }}</div>
                    <div class="returns-message-body">{{ $message->body_text ?: html_entity_decode(strip_tags((string) $message->body_html), ENT_QUOTES | ENT_HTML5, 'UTF-8') }}</div>
                </article>
            @empty <div class="returns-body returns-muted">Brak wiadomości.</div> @endforelse
            @if ($order && ! $order->trashed() && $order->emailMessages()->count() > $messages->count())<div class="returns-body"><a href="{{ route('orders.show', $order) }}">Pozostałe wiadomości w zamówieniu</a></div>@endif
        </section>
        <section class="returns-card">
            <h2>Informacje dodatkowe</h2>
            <div class="returns-body returns-history">
                <details open><summary>Historia zmian zwrotu</summary>
                    <ul>
                    @foreach ($events as $event)
                        <li>{{ $event->observed_at->timezone(config('app.timezone'))->format('d.m.Y H:i:s') }}<br>
                            @if ($event->type === 'imported')
                                Pobrano zwrot:
                            @elseif ($event->type === 'manual_created')
                                Utworzono zwrot ręcznie:
                            @elseif (in_array($event->type, ['status_changed', 'local_status_changed'], true))
                                Zmieniono status:
                            @else
                                Zaktualizowano dane:
                            @endif
                            {{ \App\Models\OrderReturn::STATUSES[$event->status] ?? $event->status }}
                        </li>
                    @endforeach
                    </ul>
                    @if ($events->hasPages()){{ $events->links() }}@endif
                </details>
            </div>
        </section>
    </div>
</div>
@endsection
