<style>
    .allegro-order-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 20px; }
    .allegro-order-grid > div { min-width: 0; }
    .allegro-order-value { overflow-wrap: anywhere; }
    .allegro-order-table { min-width: 620px; }
    .allegro-order-table td { vertical-align: middle; }
    .allegro-order-table .allegro-order-value { max-width: 360px; }
    @media (max-width: 800px) { .allegro-order-grid { grid-template-columns: 1fr; } }
</style>

<section class="allegro-panel">
    <div class="allegro-panel-header"><h2 class="allegro-panel-title">Produkty w importowanych zamówieniach</h2></div>
    <div class="allegro-panel-body">
        <form method="POST" action="{{ route('integrations.allegro.orders.settings') }}">
            @csrf
            @method('PUT')
            @foreach (['use_linked_products' => 'Używaj powiązanych produktów OMS', 'use_catalog_product_names' => 'Nazwy produktów z katalogu OMS', 'use_catalog_vat' => 'VAT z katalogu OMS (zamówienia krajowe allegro.pl)'] as $field => $label)
                <input type="hidden" name="{{ $field }}" value="0">
                <div class="form-check form-switch mb-3">
                    <input id="allegro_{{ $field }}" class="form-check-input" type="checkbox" role="switch" name="{{ $field }}" value="1" @checked(old($field, $account->{$field}))>
                    <label class="form-check-label" for="allegro_{{ $field }}">{{ $label }}</label>
                    @error($field)<div class="text-danger small">{{ $message }}</div>@enderror
                </div>
            @endforeach
            <div class="d-flex justify-content-end"><button class="btn btn-sm btn-primary" type="submit"><i class="bi bi-check-lg me-1" aria-hidden="true"></i>Zapisz ustawienia</button></div>
        </form>
    </div>
</section>

<section class="allegro-panel">
    <div class="allegro-panel-header">
        <h2 class="allegro-panel-title">Pobierz zamówienie</h2>
        <span class="allegro-state {{ $status['class'] }}">{{ $status['label'] }}</span>
    </div>
    <div class="allegro-panel-body">
        <form method="GET" action="{{ route('integrations.allegro.edit') }}" class="row g-2 align-items-end">
            <input type="hidden" name="tab" value="orders">
            <div class="col-md-9">
                <label for="allegro_order_identifier" class="form-label">ID zamówienia Allegro</label>
                <input id="allegro_order_identifier" class="form-control" name="order_identifier" value="{{ $orderIdentifier }}" required maxlength="36" placeholder="xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx" autocomplete="off">
            </div>
            <div class="col-md-3">
                <button class="btn btn-primary w-100" type="submit" @disabled(! $account->isOperational())><i class="bi bi-search me-1" aria-hidden="true"></i>Podgląd zamówienia</button>
            </div>
        </form>
        @if (! $account->isOperational())
            <div class="alert alert-warning mt-3 mb-0">Połącz konto Allegro w zakładce Połączenie.</div>
        @endif
        @if ($orderPreviewError)
            <div class="alert alert-danger mt-3 mb-0" role="alert">{{ $orderPreviewError }}</div>
        @endif
        @foreach (['allegro_order_import', 'order_identifier', 'preview_hash'] as $field)
            @error($field)<div class="alert alert-danger mt-3 mb-0" role="alert">{{ $message }}</div>@enderror
        @endforeach
    </div>
</section>

@if ($orderPreview)
    @php($p = $orderPreview)
    <section class="allegro-panel">
        <div class="allegro-panel-header flex-wrap gap-2">
            <h2 class="allegro-panel-title">Podgląd zamówienia</h2>
            @if ($p['existing_order_id'] && ! $p['existing_order_trashed'])
                <a class="btn btn-sm btn-outline-primary" href="{{ route('orders.show', $p['existing_order_id']) }}">Otwórz zamówienie OMS #{{ $p['existing_order_id'] }}</a>
            @endif
        </div>
        <div class="allegro-panel-body">
            @if ($p['existing_order_trashed'])
                <div class="alert alert-warning">Zamówienie jest już w koszu OMS. Przywróć istniejące zamówienie #{{ $p['existing_order_id'] }}.</div>
            @elseif ($p['existing_order_id'])
                <div class="alert alert-info">Zamówienie zostało już pobrane. Ponowny import nie zmieni danych w OMS.</div>
            @endif
            @if ($p['blockers'])
                <div class="alert alert-danger" role="alert"><strong>Import niedostępny</strong><ul class="mb-0 mt-1">@foreach ($p['blockers'] as $blocker)<li>{{ $blocker }}</li>@endforeach</ul></div>
            @endif
            @if ($p['warnings'])
                <div class="alert alert-warning"><ul class="mb-0">@foreach ($p['warnings'] as $warning)<li>{{ $warning }}</li>@endforeach</ul></div>
            @endif
            <div class="allegro-details mb-4">
                <div><span class="allegro-detail-label">ID Allegro</span><span class="allegro-order-value">{{ $p['external_order_id'] }}</span></div>
                <div><span class="allegro-detail-label">Status Allegro / realizacja</span>{{ $p['external_status'] }} / {{ $p['fulfillment_status'] ?: '...' }}</div>
                <div><span class="allegro-detail-label">Data zakupu</span>{{ $p['purchased_at']?->format('d.m.Y H:i') ?: '...' }}</div>
                <div><span class="allegro-detail-label">Klient</span><span class="allegro-order-value">{{ $p['customer_login'] ?: '...' }}</span></div>
                <div><span class="allegro-detail-label">E-mail</span><span class="allegro-order-value">{{ $p['customer_email'] ?: '...' }}</span></div>
                <div><span class="allegro-detail-label">Telefon</span>{{ $p['customer_phone'] ?: '...' }}</div>
                <div><span class="allegro-detail-label">Sposób dostawy</span>{{ $p['shipping_method'] ?: '...' }}</div>
                <div><span class="allegro-detail-label">Allegro Smart!</span>{{ $p['smart'] ? 'Tak' : 'Nie' }}</div>
                <div><span class="allegro-detail-label">Żądanie faktury</span>{{ $p['invoice_required'] ? 'Tak' : 'Nie' }}</div>
            </div>
            <div class="table-responsive">
                <table class="table table-sm allegro-order-table">
                    <thead><tr><th aria-label="Zdjęcie"></th><th>Produkt / ID OMS</th><th>ID oferty</th><th class="text-end">Ilość</th><th class="text-end">Cena brutto</th><th class="text-end">Wartość brutto</th><th>VAT</th></tr></thead>
                    <tbody>@foreach ($p['items'] as $item)
                        <tr>
                            <td>
                                @if ($item['catalog']['thumbnail_path'] ?? null)
                                    <img src="{{ route('products.image', [$item['product_id'], 'thumbnail']) }}" width="40" height="40" style="object-fit: contain" alt="">
                                @else
                                    <i class="bi bi-camera text-secondary" aria-hidden="true"></i>
                                @endif
                            </td>
                            <td class="allegro-order-value">
                                <div>{{ $item['product_name'] }}</div>
                                @if ($item['product_id'])
                                    <div class="small text-secondary">{{ $item['catalog']['item_type'] === 'bundle' ? 'Zestaw OMS' : 'Produkt OMS' }} #{{ $item['product_id'] }}</div>
                                    @if ($item['source_product_name'] !== $item['product_name'])
                                        <div class="small text-secondary">Allegro: {{ $item['source_product_name'] }}</div>
                                    @elseif ($item['catalog']['name'] !== $item['product_name'])
                                        <div class="small text-secondary">OMS: {{ $item['catalog']['name'] }}</div>
                                    @endif
                                @elseif ($item['offer_id'])
                                    <div class="small text-secondary">{{ $p['catalog_settings']['use_linked_products'] ? 'Brak powiązania' : 'Powiązania wyłączone' }}</div>
                                @else
                                    <div class="small text-secondary">Usługa dodatkowa</div>
                                @endif
                            </td>
                            <td>{{ $item['offer_id'] ?: '...' }}</td><td class="text-end">{{ $item['quantity'] }}</td>
                            <td class="text-end text-nowrap">{{ $item['unit_price_gross'] }} {{ $p['currency'] }}</td>
                            <td class="text-end text-nowrap">{{ $item['total_price_gross'] }} {{ $p['currency'] }}</td>
                            <td class="text-nowrap">{{ $item['vat_rate'] !== null ? $item['vat_rate'].'%' : '...' }}</td>
                        </tr>
                    @endforeach</tbody>
                </table>
            </div>
            <dl class="row justify-content-end mb-4">
                <dt class="col-7 col-md-3">Koszt dostawy</dt><dd class="col-5 col-md-2 text-end">{{ $p['delivery_cost_gross'] }} {{ $p['currency'] }}</dd>
                <div class="w-100"></div>
                <dt class="col-7 col-md-3">Łączna wartość</dt><dd class="col-5 col-md-2 text-end fw-bold">{{ $p['total_gross'] }} {{ $p['currency'] }}</dd>
                <div class="w-100"></div>
                <dt class="col-7 col-md-3">Zapłacono</dt><dd class="col-5 col-md-2 text-end">{{ $p['paid_amount'] }} {{ $p['currency'] }}</dd>
            </dl>
            <div class="allegro-order-grid border-top pt-3">
                @foreach (['shipping_address' => 'Adres dostawy', 'billing_address' => 'Dane do faktury'] as $key => $label)
                    <div><h3 class="h6">{{ $label }}</h3>
                        @foreach (['name' => 'Imię i nazwisko', 'company_name' => 'Firma', 'tax_id' => 'NIP', 'street' => 'Adres', 'postal_code' => 'Kod pocztowy', 'city' => 'Miasto', 'country_code' => 'Kraj'] as $field => $caption)
                            <div class="small allegro-order-value"><span class="text-secondary">{{ $caption }}:</span> {{ $p[$key][$field] ?: '...' }}</div>
                        @endforeach
                    </div>
                @endforeach
                @if ($p['pickup_point']['id'] || $p['pickup_point']['name'])
                    <div><h3 class="h6">Odbiór w punkcie</h3>
                        <div>Nazwa: {{ $p['pickup_point']['name'] ?: '...' }}</div><div>ID: {{ $p['pickup_point']['id'] ?: '...' }}</div>
                        <div>{{ $p['pickup_point']['address'] }} {{ $p['pickup_point']['postal_code'] }} {{ $p['pickup_point']['city'] }}</div>
                    </div>
                @endif
                @if ($p['buyer_message'])
                    <div><h3 class="h6">Wiadomość kupującego</h3><div class="allegro-order-value" style="white-space: pre-wrap">{{ $p['buyer_message'] }}</div></div>
                @endif
            </div>
            <h3 class="h6 mt-4">Płatności Allegro</h3>
            <div class="table-responsive"><table class="table table-sm allegro-order-table">
                <thead><tr><th>ID płatności</th><th>Typ / operator</th><th>Data wpłaty</th><th class="text-end">Kwota wpłaty</th></tr></thead>
                <tbody>@forelse ($p['payments'] as $payment)
                    <tr><td class="allegro-order-value">{{ $payment['external_payment_id'] }}</td><td>{{ $payment['type'] }} / {{ \Modules\Integrations\Allegro\Support\AllegroPaymentProvider::label($payment['provider']) ?: '...' }}</td><td>{{ $payment['paid_at']?->format('d.m.Y H:i') ?: '...' }}</td><td class="text-end text-nowrap">{{ $payment['amount'] ?? '...' }} {{ $payment['currency'] }}</td></tr>
                @empty<tr><td colspan="4">Brak płatności</td></tr>@endforelse</tbody>
            </table></div>
            @if ($p['importable'] && ! $p['existing_order_id'])
                <form method="POST" action="{{ route('integrations.allegro.orders.import') }}" class="allegro-actions">
                    @csrf
                    <input type="hidden" name="order_identifier" value="{{ $p['external_order_id'] }}">
                    <input type="hidden" name="preview_hash" value="{{ $p['source_hash'] }}">
                    <button class="btn btn-primary" type="submit"><i class="bi bi-download me-1" aria-hidden="true"></i>Importuj do OMS</button>
                </form>
            @endif
        </div>
    </section>
@endif
