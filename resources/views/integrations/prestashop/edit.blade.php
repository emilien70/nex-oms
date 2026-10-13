@extends('layouts.app')

@section('title', 'PrestaShop - NEX-OMS')

@section('content')
    <style>
        .prestashop-page {
            background: #f4f6f8;
            margin: -1.5rem;
            min-height: 100vh;
            padding: 24px;
        }

        .prestashop-shell {
            max-width: 1080px;
        }

        .prestashop-heading,
        .prestashop-card-header,
        .prestashop-actions {
            align-items: center;
            display: flex;
        }

        .prestashop-heading {
            justify-content: space-between;
            margin-bottom: 16px;
        }

        .prestashop-heading h1 {
            font-size: 20px;
            font-weight: 700;
            margin: 0;
        }

        .prestashop-card {
            background: #fff;
            border: 1px solid #d7dde5;
            border-radius: 6px;
            box-shadow: 0 1px 3px rgba(17, 24, 39, .06);
        }

        .prestashop-card + .prestashop-card {
            margin-top: 16px;
        }

        .prestashop-card-header {
            border-bottom: 1px solid #e3e7ed;
            justify-content: space-between;
            padding: 16px 18px;
        }

        .prestashop-card-header strong {
            font-size: 15px;
        }

        .prestashop-card-body {
            padding: 18px;
        }

        .prestashop-status {
            align-items: center;
            display: inline-flex;
            font-size: 12px;
            font-weight: 700;
            gap: 6px;
        }

        .prestashop-status-dot {
            border-radius: 50%;
            height: 8px;
            width: 8px;
        }

        .prestashop-status-success {
            color: #137333;
        }

        .prestashop-status-success .prestashop-status-dot {
            background: #198754;
        }

        .prestashop-status-error {
            color: #b42318;
        }

        .prestashop-status-error .prestashop-status-dot {
            background: #dc3545;
        }

        .prestashop-status-idle {
            color: #667085;
        }

        .prestashop-status-idle .prestashop-status-dot {
            background: #98a2b3;
        }

        .prestashop-section {
            border-top: 1px solid #e3e7ed;
            margin-top: 20px;
            padding-top: 18px;
        }

        .prestashop-section-title {
            font-size: 14px;
            font-weight: 700;
            margin: 0 0 12px;
        }

        .prestashop-meta {
            display: grid;
            gap: 12px 20px;
            grid-template-columns: repeat(4, minmax(0, 1fr));
        }

        .prestashop-meta-label {
            color: #667085;
            display: block;
            font-size: 11px;
            margin-bottom: 2px;
            text-transform: uppercase;
        }

        .prestashop-meta-value {
            color: #1f2937;
            font-size: 13px;
            font-weight: 600;
            overflow-wrap: anywhere;
        }

        .prestashop-color {
            border: 1px solid #cfd6df;
            display: inline-block;
            height: 12px;
            margin-right: 6px;
            vertical-align: -1px;
            width: 12px;
        }

        .prestashop-actions {
            border-top: 1px solid #e3e7ed;
            gap: 8px;
            justify-content: flex-end;
            margin-top: 18px;
            padding-top: 16px;
        }

        .prestashop-preview-columns {
            display: grid;
            gap: 24px;
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }

        .prestashop-address-line {
            color: #344054;
            font-size: 13px;
            line-height: 1.55;
        }

        @media (max-width: 767.98px) {
            .prestashop-page {
                margin: -1rem;
                padding: 16px;
            }

            .prestashop-heading {
                align-items: flex-start;
                flex-direction: column;
                gap: 10px;
            }

            .prestashop-meta {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }

            .prestashop-preview-columns {
                grid-template-columns: 1fr;
            }

            .prestashop-actions {
                align-items: stretch;
                flex-direction: column-reverse;
            }

            .prestashop-actions .btn,
            .prestashop-actions form,
            .prestashop-actions form .btn {
                width: 100%;
            }
        }
    </style>

    <div class="prestashop-page">
        <div class="prestashop-shell">
            <div class="prestashop-heading">
                <h1>PrestaShop</h1>
                <a class="btn btn-sm btn-outline-secondary" href="{{ route('integrations.index') }}">
                    <i class="bi bi-arrow-left" aria-hidden="true"></i>
                    Lista integracji
                </a>
            </div>

            @if (session('success'))
                <div class="alert alert-success py-2" role="alert">{{ session('success') }}</div>
            @endif

            @if (session('error'))
                <div class="alert alert-danger py-2" role="alert">{{ session('error') }}</div>
            @endif

            @if ($errors->any())
                <div class="alert alert-danger py-2" role="alert">
                    @foreach ($errors->all() as $error)
                        <div>{{ $error }}</div>
                    @endforeach
                </div>
            @endif

            <section class="prestashop-card" aria-labelledby="prestashop-settings-heading">
                <div class="prestashop-card-header">
                    <strong id="prestashop-settings-heading">Sklep internetowy</strong>

                    @if ($account->last_test_status?->value === 'success')
                        <span class="prestashop-status prestashop-status-success">
                            <span class="prestashop-status-dot" aria-hidden="true"></span>
                            Połączono
                        </span>
                    @elseif ($account->last_test_status?->value === 'error')
                        <span class="prestashop-status prestashop-status-error">
                            <span class="prestashop-status-dot" aria-hidden="true"></span>
                            Błąd połączenia
                        </span>
                    @else
                        <span class="prestashop-status prestashop-status-idle">
                            <span class="prestashop-status-dot" aria-hidden="true"></span>
                            Nie testowano
                        </span>
                    @endif
                </div>

                <div class="prestashop-card-body">
                    @if ($account->last_test_message)
                        <div class="alert {{ $account->last_test_status?->value === 'success' ? 'alert-success' : 'alert-danger' }} py-2 small">
                            {{ $account->last_test_message }}
                            @if ($account->last_tested_at)
                                <span class="d-block mt-1 text-muted">{{ $account->last_tested_at->format('d.m.Y H:i') }}</span>
                            @endif
                        </div>
                    @endif

                    <form id="prestashop-settings-form" method="POST" action="{{ route('integrations.prestashop.update') }}" novalidate>
                        @csrf
                        @method('PUT')

                        <div class="row g-3">
                            <div class="col-md-5">
                                <label class="form-label" for="prestashop-name">Nazwa wyświetlana</label>
                                <input
                                    id="prestashop-name"
                                    class="form-control @error('name') is-invalid @enderror"
                                    name="name"
                                    type="text"
                                    maxlength="120"
                                    value="{{ old('name', $account->name) }}"
                                    required
                                >
                            </div>

                            <div class="col-md-7">
                                <label class="form-label" for="prestashop-base-url">Adres URL sklepu</label>
                                <input
                                    id="prestashop-base-url"
                                    class="form-control @error('base_url') is-invalid @enderror"
                                    name="base_url"
                                    type="url"
                                    maxlength="2048"
                                    value="{{ old('base_url', $account->base_url) }}"
                                    placeholder="https://multi-click.pl"
                                    required
                                >
                            </div>

                            <div class="col-12">
                                <label class="form-label" for="prestashop-product-url-template">Wzór linku do produktu</label>
                                <input
                                    id="prestashop-product-url-template"
                                    class="form-control @error('product_url_template') is-invalid @enderror"
                                    name="product_url_template"
                                    type="text"
                                    maxlength="2048"
                                    value="{{ old('product_url_template', $account->resolvedProductUrlTemplate()) }}"
                                    placeholder="https://multi-click.pl/index.php?controller=product&id_product=[product_id]"
                                    required
                                >
                                <div class="form-text">Użyj znacznika <code>[product_id]</code> w miejscu ID produktu PrestaShop.</div>
                            </div>

                            <div class="col-12">
                                <label class="form-label" for="prestashop-api-key">Klucz API</label>
                                <input
                                    id="prestashop-api-key"
                                    class="form-control @error('api_key') is-invalid @enderror"
                                    name="api_key"
                                    type="password"
                                    minlength="32"
                                    maxlength="32"
                                    autocomplete="new-password"
                                    placeholder="{{ filled($account->api_key) ? 'Zapisano - pozostaw puste bez zmian' : 'Wpisz 32-znakowy klucz API' }}"
                                >
                            </div>

                            <div class="col-12">
                                <input name="is_active" type="hidden" value="0">
                                <div class="form-check form-switch">
                                    <input
                                        id="prestashop-active"
                                        class="form-check-input @error('is_active') is-invalid @enderror"
                                        name="is_active"
                                        type="checkbox"
                                        value="1"
                                        @checked(old('is_active', $account->is_active))
                                        @disabled(! $canActivate && ! $account->is_active)
                                    >
                                    <label class="form-check-label" for="prestashop-active">Integracja aktywna</label>
                                </div>
                                @if (! $canActivate && ! $account->is_active)
                                    <div class="form-text">Najpierw zapisz ustawienia i wykonaj poprawny test połączenia.</div>
                                @endif
                            </div>
                        </div>
                    </form>

                    <section class="prestashop-section" aria-labelledby="prestashop-permissions-heading">
                        <h2 class="prestashop-section-title" id="prestashop-permissions-heading">Wymagane uprawnienia API</h2>
                        <div class="small text-muted">
                            W panelu PrestaShop włącz Webservice i nadaj kluczowi uprawnienie Podgląd (GET) dla:
                            <strong>shops</strong>, <strong>languages</strong>, <strong>currencies</strong>, <strong>order_states</strong>,
                            <strong>orders</strong>, <strong>order_details</strong>, <strong>addresses</strong>, <strong>customers</strong>,
                            <strong>carriers</strong>, <strong>countries</strong>, <strong>states</strong> i <strong>order_payments</strong>.
                            Dla danych punktu odbioru nadaj również Podgląd (GET) dla <strong>bl_order</strong>, jeśli ten zasób jest dostępny.
                        </div>
                    </section>

                    @if ($account->last_test_status?->value === 'success')
                        <section class="prestashop-section" aria-labelledby="prestashop-detected-heading">
                            <h2 class="prestashop-section-title" id="prestashop-detected-heading">Wykryta konfiguracja</h2>
                            <div class="prestashop-meta">
                                <div>
                                    <span class="prestashop-meta-label">Sklep</span>
                                    <span class="prestashop-meta-value">{{ $account->shop_name }} (ID {{ $account->shop_id }})</span>
                                </div>
                                <div>
                                    <span class="prestashop-meta-label">Wersja</span>
                                    <span class="prestashop-meta-value">{{ $account->detected_version ?: 'Nierozpoznana' }}</span>
                                </div>
                                <div>
                                    <span class="prestashop-meta-label">Język statusów</span>
                                    <span class="prestashop-meta-value">{{ strtoupper((string) $account->language_iso_code) }}</span>
                                </div>
                                <div>
                                    <span class="prestashop-meta-label">Waluty</span>
                                    <span class="prestashop-meta-value">
                                        {{ collect($account->available_currencies ?? [])->pluck('iso_code')->join(', ') }}
                                    </span>
                                </div>
                                <div>
                                    <span class="prestashop-meta-label">Rozszerzone dane zamówień</span>
                                    <span class="prestashop-meta-value">{{ $account->order_enrichment_resource ?: 'Niedostępne' }}</span>
                                </div>
                            </div>
                        </section>

                        <section class="prestashop-section" aria-labelledby="prestashop-statuses-heading">
                            <h2 class="prestashop-section-title" id="prestashop-statuses-heading">
                                Statusy zamówień ({{ $account->orderStates->count() }})
                            </h2>
                            <div class="table-responsive">
                                <table class="table table-sm align-middle mb-0">
                                    <thead>
                                        <tr>
                                            <th scope="col">ID</th>
                                            <th scope="col">Nazwa</th>
                                            <th scope="col">Znaczenie w PrestaShop</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach ($account->orderStates as $state)
                                            <tr>
                                                <td>{{ $state->external_id }}</td>
                                                <td>
                                                    @if ($state->color)
                                                        <span class="prestashop-color" style="background-color: {{ $state->color }}" aria-hidden="true"></span>
                                                    @endif
                                                    {{ $state->name }}
                                                </td>
                                                <td>
                                                    @if ($state->paid)
                                                        <span class="badge text-bg-success">Opłacone</span>
                                                    @endif
                                                    @if ($state->shipped)
                                                        <span class="badge text-bg-primary">Wysłane</span>
                                                    @endif
                                                    @if ($state->delivery)
                                                        <span class="badge text-bg-info">Dostarczone</span>
                                                    @endif
                                                    @if ($state->hidden)
                                                        <span class="badge text-bg-secondary">Ukryte</span>
                                                    @endif
                                                    @if (! $state->paid && ! $state->shipped && ! $state->delivery && ! $state->hidden)
                                                        <span class="text-muted">-</span>
                                                    @endif
                                                </td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        </section>
                    @endif

                    <div class="prestashop-actions">
                        <form method="POST" action="{{ route('integrations.prestashop.test-connection') }}">
                            @csrf
                            <button class="btn btn-outline-primary" type="submit" @disabled(! $credentialsConfigured)>
                                <i class="bi bi-wifi" aria-hidden="true"></i>
                                Testuj połączenie
                            </button>
                        </form>

                        <button class="btn btn-primary" type="submit" form="prestashop-settings-form">
                            Zapisz
                        </button>
                    </div>
                </div>
            </section>

            <section class="prestashop-card" aria-labelledby="prestashop-import-heading">
                <div class="prestashop-card-header">
                    <strong id="prestashop-import-heading">Testowy import zamówienia</strong>
                </div>

                <div class="prestashop-card-body">
                    <form class="row g-2 align-items-end" method="GET" action="{{ route('integrations.prestashop.edit') }}">
                        <div class="col-md-8">
                            <label class="form-label" for="prestashop-order-identifier">ID lub numer referencyjny</label>
                            <input
                                id="prestashop-order-identifier"
                                class="form-control @error('order_identifier') is-invalid @enderror"
                                name="order_identifier"
                                type="text"
                                maxlength="64"
                                value="{{ old('order_identifier', $orderIdentifier) }}"
                                placeholder="np. 13581"
                            >
                        </div>
                        <div class="col-md-4 d-grid">
                            <button class="btn btn-outline-primary" type="submit" @disabled(! $canActivate)>
                                <i class="bi bi-search" aria-hidden="true"></i>
                                Pobierz podgląd
                            </button>
                        </div>
                    </form>

                    @if ($orderPreviewError)
                        <div class="alert alert-danger py-2 mt-3 mb-0" role="alert">{{ $orderPreviewError }}</div>
                    @endif

                    @if ($orderPreview)
                        <section class="prestashop-section" aria-labelledby="prestashop-preview-summary-heading">
                            <h2 class="prestashop-section-title" id="prestashop-preview-summary-heading">
                                Zamówienie {{ $orderPreview['reference'] }}
                            </h2>
                            <div class="prestashop-meta">
                                <div>
                                    <span class="prestashop-meta-label">ID PrestaShop</span>
                                    <span class="prestashop-meta-value">{{ $orderPreview['external_order_id'] }}</span>
                                </div>
                                <div>
                                    <span class="prestashop-meta-label">Status PrestaShop</span>
                                    <span class="prestashop-meta-value">{{ $orderPreview['external_status_name'] }}</span>
                                </div>
                                <div>
                                    <span class="prestashop-meta-label">Data zamówienia</span>
                                    <span class="prestashop-meta-value">
                                        {{ \Carbon\CarbonImmutable::parse($orderPreview['purchased_at'])->format('d.m.Y H:i') }}
                                    </span>
                                </div>
                                <div>
                                    <span class="prestashop-meta-label">Klient</span>
                                    <span class="prestashop-meta-value">
                                        {{ $orderPreview['shipping_address']['name'] ?: $orderPreview['customer_email'] ?: '-' }}
                                    </span>
                                </div>
                                <div>
                                    <span class="prestashop-meta-label">Wartość</span>
                                    <span class="prestashop-meta-value">
                                        {{ $moneyFormatter->format($orderPreview['total_gross']) }} {{ $orderPreview['currency'] }}
                                    </span>
                                </div>
                                <div>
                                    <span class="prestashop-meta-label">Zapłacono</span>
                                    <span class="prestashop-meta-value">
                                        {{ $moneyFormatter->format($orderPreview['paid_amount']) }} {{ $orderPreview['currency'] }}
                                    </span>
                                </div>
                                <div>
                                    <span class="prestashop-meta-label">Płatność</span>
                                    <span class="prestashop-meta-value">{{ $orderPreview['payment_method'] ?: '-' }}</span>
                                </div>
                                <div>
                                    <span class="prestashop-meta-label">Dostawa</span>
                                    <span class="prestashop-meta-value">{{ $orderPreview['shipping_method'] ?: '-' }}</span>
                                </div>
                                <div>
                                    <span class="prestashop-meta-label">Punkt odbioru</span>
                                    <span class="prestashop-meta-value">{{ $orderPreview['pickup_point']['name'] ?: '-' }}</span>
                                </div>
                            </div>
                        </section>

                        <section class="prestashop-section" aria-labelledby="prestashop-preview-addresses-heading">
                            <h2 class="prestashop-section-title" id="prestashop-preview-addresses-heading">Adresy</h2>
                            <div class="prestashop-preview-columns">
                                @foreach (['shipping_address' => 'Dostawa', 'billing_address' => 'Rozliczenie'] as $addressKey => $addressLabel)
                                    @php($address = $orderPreview[$addressKey])
                                    <div>
                                        <span class="prestashop-meta-label">{{ $addressLabel }}</span>
                                        <div class="prestashop-address-line">
                                            <strong>{{ $address['company_name'] ?: $address['name'] ?: '-' }}</strong><br>
                                            @if ($address['company_name'] && $address['name'])
                                                {{ $address['name'] }}<br>
                                            @endif
                                            {{ $address['street'] ?: '-' }}<br>
                                            {{ trim(($address['postal_code'] ?: '').' '.($address['city'] ?: '')) ?: '-' }}<br>
                                            {{ $address['country_code'] ?: '-' }}
                                            @if ($addressKey === 'billing_address' && $address['tax_id'])
                                                <br>NIP: {{ $address['tax_id'] }}
                                            @endif
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        </section>

                        <section class="prestashop-section" aria-labelledby="prestashop-preview-items-heading">
                            <h2 class="prestashop-section-title" id="prestashop-preview-items-heading">Produkty</h2>
                            <div class="table-responsive">
                                <table class="table table-sm align-middle mb-0">
                                    <thead>
                                        <tr>
                                            <th scope="col">ID pozycji</th>
                                            <th scope="col">Nazwa produktu</th>
                                            <th class="text-end" scope="col">Ilość</th>
                                            <th class="text-end" scope="col">Cena</th>
                                            <th class="text-end" scope="col">Wartość</th>
                                            <th class="text-end" scope="col">VAT</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach ($orderPreview['items'] as $item)
                                            <tr>
                                                <td>{{ $item['external_order_detail_id'] }}</td>
                                                <td>{{ $item['product_name'] }}</td>
                                                <td class="text-end">{{ $item['quantity'] }}</td>
                                                <td class="text-end">{{ $moneyFormatter->format($item['unit_price_gross']) }} {{ $item['currency'] }}</td>
                                                <td class="text-end">{{ $moneyFormatter->format($item['total_price_gross']) }} {{ $item['currency'] }}</td>
                                                <td class="text-end">{{ $item['vat_rate'] !== null ? str_replace('.', ',', $item['vat_rate']).'%' : '-' }}</td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        </section>

                        @if ($orderPreview['warnings'])
                            <div class="alert alert-warning py-2 mt-3 mb-0" role="alert">
                                @foreach ($orderPreview['warnings'] as $warning)
                                    <div>{{ $warning }}</div>
                                @endforeach
                            </div>
                        @endif

                        <div class="prestashop-actions">
                            @if ($orderPreview['existing_order_id'] && ! $orderPreview['existing_order_trashed'])
                                <a class="btn btn-primary" href="{{ route('orders.show', $orderPreview['existing_order_id']) }}">
                                    Przejdź do zamówienia OMS
                                </a>
                            @elseif ($orderPreview['existing_order_trashed'])
                                <div class="alert alert-warning py-2 mb-0 w-100" role="alert">
                                    To zamówienie znajduje się już w koszu OMS.
                                </div>
                            @else
                                <form method="POST" action="{{ route('integrations.prestashop.orders.import') }}">
                                    @csrf
                                    <input name="order_identifier" type="hidden" value="{{ $orderPreview['external_order_id'] }}">
                                    <button class="btn btn-primary" type="submit" @disabled(! $account->is_active)>
                                        <i class="bi bi-download" aria-hidden="true"></i>
                                        Importuj do OMS
                                    </button>
                                </form>
                                @if (! $account->is_active)
                                    <span class="small text-muted">Włącz integrację i zapisz ustawienia, aby rozpocząć import.</span>
                                @endif
                            @endif
                        </div>
                    @endif
                </div>
            </section>
        </div>
    </div>
@endsection
