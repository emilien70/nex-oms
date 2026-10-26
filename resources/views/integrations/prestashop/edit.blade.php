@extends('layouts.app')

@section('title', 'PrestaShop - NEX-OMS')
@section('suppress-layout-success', true)

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

        .prestashop-tabs {
            margin-bottom: 16px;
        }

        .prestashop-tabs .nav-link {
            color: #475467;
            font-size: 13px;
            font-weight: 600;
        }

        .prestashop-tabs .nav-link.active {
            color: #0d6efd;
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

        .prestashop-mapping-select {
            min-width: 260px;
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
                <div
                    class="alert alert-success fade show py-2"
                    data-prestashop-success-alert
                    role="status"
                    aria-live="polite"
                >{{ session('success') }}</div>
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

            <nav class="prestashop-tabs" aria-label="Ustawienia integracji PrestaShop">
                <div class="nav nav-tabs">
                    <a
                        class="nav-link {{ $activeTab === 'connection' ? 'active' : '' }}"
                        href="{{ route('integrations.prestashop.edit', ['tab' => 'connection']) }}"
                    >Połączenie</a>
                    <a
                        class="nav-link {{ $activeTab === 'orders' ? 'active' : '' }}"
                        href="{{ route('integrations.prestashop.edit', ['tab' => 'orders']) }}"
                    >Zamówienia</a>
                    <a
                        class="nav-link {{ $activeTab === 'statuses' ? 'active' : '' }}"
                        href="{{ route('integrations.prestashop.edit', ['tab' => 'statuses']) }}"
                    >Statusy</a>
                    <a
                        class="nav-link {{ $activeTab === 'payments' ? 'active' : '' }}"
                        href="{{ route('integrations.prestashop.edit', ['tab' => 'payments']) }}"
                    >Płatności</a>
                </div>
            </nav>

            @if ($activeTab === 'connection')
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
                                Statusy zamówień ({{ $account->orderStates->where('is_available', true)->count() }})
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
                                                    @if (! $state->is_available)
                                                        <span class="badge text-bg-warning ms-1">Niedostępny</span>
                                                    @endif
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

            @elseif ($activeTab === 'orders')
            <section class="prestashop-card" aria-labelledby="prestashop-automatic-import-heading">
                <div class="prestashop-card-header">
                    <strong id="prestashop-automatic-import-heading">Automatyczne pobieranie zamówień</strong>
                    <span class="prestashop-status {{ $account->order_import_enabled ? 'prestashop-status-success' : 'prestashop-status-idle' }}">
                        <span class="prestashop-status-dot" aria-hidden="true"></span>
                        {{ $account->order_import_enabled ? 'Włączone' : 'Wyłączone' }}
                    </span>
                </div>

                <div class="prestashop-card-body">
                    <form method="POST" action="{{ route('integrations.prestashop.order-import.update') }}">
                        @csrf
                        @method('PUT')

                        <div class="row g-3 align-items-end">
                            <div class="col-lg-7">
                                <input name="order_import_enabled" type="hidden" value="0">
                                <div class="form-check form-switch">
                                    <input
                                        id="prestashop-order-import-enabled"
                                        class="form-check-input @error('order_import_enabled') is-invalid @enderror"
                                        name="order_import_enabled"
                                        type="checkbox"
                                        value="1"
                                        @checked(old('order_import_enabled', $account->order_import_enabled))
                                        @disabled(! $account->is_active)
                                    >
                                    <label class="form-check-label" for="prestashop-order-import-enabled">
                                        Pobieraj automatycznie nowe zamówienia
                                    </label>
                                </div>
                                <div class="form-text">
                                    Sprawdzanie odbywa się co 1 minutę. Import rozpoczyna się od chwili włączenia tej opcji i nie pobiera wcześniejszych zamówień. Jednominutowe opóźnienie chroni przed pobraniem zamówienia, które PrestaShop jeszcze zapisuje.
                                </div>
                            </div>
                            <div class="col-lg-5 d-grid d-lg-flex justify-content-lg-end">
                                <button class="btn btn-primary" type="submit" @disabled(! $account->is_active)>
                                    Zapisz ustawienia
                                </button>
                            </div>
                        </div>

                        <section class="prestashop-section" aria-labelledby="prestashop-order-product-settings-heading">
                            <h2 class="prestashop-section-title" id="prestashop-order-product-settings-heading">
                                Produkty w importowanych zamówieniach
                            </h2>

                            <div class="row g-3">
                                <div class="col-lg-6">
                                    <input name="use_linked_products" type="hidden" value="0">
                                    <div class="form-check form-switch">
                                        <input
                                            id="prestashop-use-linked-products"
                                            class="form-check-input @error('use_linked_products') is-invalid @enderror"
                                            name="use_linked_products"
                                            type="checkbox"
                                            value="1"
                                            @checked(old('use_linked_products', $account->use_linked_products))
                                        >
                                        <label class="form-check-label" for="prestashop-use-linked-products">
                                            Podmieniaj produkty na ręcznie powiązane produkty OMS
                                        </label>
                                    </div>
                                    <div class="form-text">
                                        Powiązany produkt otrzyma wewnętrzne ID i zdjęcie z katalogu OMS. Brak powiązania nie zatrzyma importu.
                                    </div>
                                </div>

                                <div class="col-lg-6">
                                    <input name="use_catalog_product_names" type="hidden" value="0">
                                    <div class="form-check form-switch">
                                        <input
                                            id="prestashop-use-catalog-product-names"
                                            class="form-check-input @error('use_catalog_product_names') is-invalid @enderror"
                                            name="use_catalog_product_names"
                                            type="checkbox"
                                            value="1"
                                            @checked(old('use_catalog_product_names', $account->use_catalog_product_names))
                                        >
                                        <label class="form-check-label" for="prestashop-use-catalog-product-names">
                                            Używaj nazw produktów z katalogu OMS
                                        </label>
                                    </div>
                                    <div class="form-text">
                                        Opcja działa wyłącznie dla ręcznie powiązanych produktów.
                                    </div>
                                </div>

                                <div class="col-12">
                                    <label class="form-label" for="prestashop-catalog-vat-mode">
                                        Stawki VAT z katalogu OMS
                                    </label>
                                    <select
                                        id="prestashop-catalog-vat-mode"
                                        class="form-select @error('catalog_vat_mode') is-invalid @enderror"
                                        name="catalog_vat_mode"
                                    >
                                        @foreach (\Modules\Integrations\Prestashop\Enums\PrestashopCatalogVatMode::cases() as $vatMode)
                                            <option
                                                value="{{ $vatMode->value }}"
                                                @selected(old('catalog_vat_mode', $account->catalog_vat_mode?->value) === $vatMode->value)
                                            >
                                                {{ $vatMode->label() }}
                                            </option>
                                        @endforeach
                                    </select>
                                    @error('catalog_vat_mode')
                                        <div class="invalid-feedback">{{ $message }}</div>
                                    @enderror
                                </div>
                                <div class="col-12">
                                    <label class="form-label" for="prestashop-default-vat-rate">Domyślna stawka VAT</label>
                                    <div class="input-group" style="max-width: 280px">
                                        <input id="prestashop-default-vat-rate" class="form-control @error('default_vat_rate') is-invalid @enderror" type="number" min="0" max="100" step="1" name="default_vat_rate" value="{{ old('default_vat_rate', $account->default_vat_rate === null ? '' : (int) $account->default_vat_rate) }}" placeholder="Nie ustawiaj automatycznie">
                                        <span class="input-group-text">%</span>
                                        @error('default_vat_rate')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                    </div>
                                </div>
                            </div>

                            <div class="form-text mt-3">
                                Cena, ilość i wartość pozycji zawsze pozostają zgodne z zamówieniem PrestaShop.
                            </div>
                        </section>
                    </form>

                    @if (! $account->is_active)
                        <div class="alert alert-warning py-2 mt-3 mb-0" role="alert">
                            Najpierw włącz integrację PrestaShop w zakładce Połączenie.
                        </div>
                    @endif

                    <section class="prestashop-section" aria-labelledby="prestashop-order-import-status-heading">
                        <h2 class="prestashop-section-title" id="prestashop-order-import-status-heading">Stan pobierania</h2>

                        <div class="prestashop-meta">
                            <div>
                                <span class="prestashop-meta-label">Uruchomiono od</span>
                                <span class="prestashop-meta-value">
                                    {{ $account->order_import_started_at?->format('d.m.Y H:i:s') ?? '-' }}
                                </span>
                            </div>
                            <div>
                                <span class="prestashop-meta-label">Sprawdzono zamówienia do</span>
                                <span class="prestashop-meta-value">
                                    {{ $account->last_order_import_scan_at?->format('d.m.Y H:i:s') ?? '-' }}
                                </span>
                            </div>
                            <div>
                                <span class="prestashop-meta-label">Ostatni błąd</span>
                                <span class="prestashop-meta-value">
                                    {{ $account->last_order_import_error_at?->format('d.m.Y H:i:s') ?? '-' }}
                                </span>
                            </div>
                        </div>

                        @if ($account->last_order_import_error)
                            <div class="alert alert-danger py-2 mt-3 mb-0" role="alert">
                                {{ $account->last_order_import_error }}
                            </div>
                        @endif

                        <div class="d-flex justify-content-end mt-3">
                            <form method="POST" action="{{ route('integrations.prestashop.order-import.discover') }}">
                                @csrf
                                <button
                                    class="btn btn-outline-primary"
                                    type="submit"
                                    @disabled(! $account->is_active || ! $account->order_import_enabled)
                                >
                                    <i class="bi bi-arrow-repeat" aria-hidden="true"></i>
                                    Sprawdź nowe zamówienia
                                </button>
                            </form>
                        </div>
                    </section>

                    @if ($recentImportAttempts->isNotEmpty())
                        <section class="prestashop-section" aria-labelledby="prestashop-order-import-history-heading">
                            <h2 class="prestashop-section-title" id="prestashop-order-import-history-heading">Ostatnie próby importu</h2>

                            <div class="table-responsive">
                                <table class="table table-sm align-middle mb-0">
                                    <thead>
                                        <tr>
                                            <th scope="col">Zamówienie PrestaShop</th>
                                            <th scope="col">Wykryto</th>
                                            <th scope="col">Status</th>
                                            <th class="text-end" scope="col">Próby</th>
                                            <th class="text-end" scope="col">Wynik</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach ($recentImportAttempts as $attempt)
                                            @php
                                                $attemptStatus = match ($attempt->status) {
                                                    'pending' => ['Oczekuje', 'text-bg-secondary'],
                                                    'processing' => ['Importowanie', 'text-bg-primary'],
                                                    'imported' => ['Zaimportowano', 'text-bg-success'],
                                                    'failed' => ['Błąd', 'text-bg-danger'],
                                                    default => [$attempt->status, 'text-bg-secondary'],
                                                };
                                            @endphp
                                            <tr>
                                                <td>
                                                    <strong>{{ $attempt->external_reference ?: '#'.$attempt->external_order_id }}</strong>
                                                    <div class="small text-muted">ID {{ $attempt->external_order_id }}</div>
                                                </td>
                                                <td>{{ $attempt->discovered_at?->format('d.m.Y H:i:s') ?? '-' }}</td>
                                                <td>
                                                    <span class="badge {{ $attemptStatus[1] }}">{{ $attemptStatus[0] }}</span>
                                                    @if ($attempt->last_error)
                                                        <div class="small text-danger mt-1">{{ $attempt->last_error }}</div>
                                                    @endif
                                                </td>
                                                <td class="text-end">{{ $attempt->attempts }}</td>
                                                <td class="text-end">
                                                    @if ($attempt->order && ! $attempt->order->trashed())
                                                        <a class="btn btn-sm btn-outline-primary" href="{{ route('orders.show', $attempt->order) }}">
                                                            Otwórz
                                                        </a>
                                                    @elseif ($attempt->status === 'failed')
                                                        <form method="POST" action="{{ route('integrations.prestashop.order-import.retry', $attempt) }}">
                                                            @csrf
                                                            <button class="btn btn-sm btn-outline-primary" type="submit">
                                                                Ponów
                                                            </button>
                                                        </form>
                                                    @else
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
                </div>
            </section>

            <section class="prestashop-card" aria-labelledby="prestashop-import-heading">
                <div class="prestashop-card-header">
                    <strong id="prestashop-import-heading">Testowy import zamówienia</strong>
                </div>

                <div class="prestashop-card-body">
                    <form class="row g-2 align-items-end" method="GET" action="{{ route('integrations.prestashop.edit') }}">
                        <input name="tab" type="hidden" value="orders">
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
                                    <span class="prestashop-meta-value">
                                        {{ $orderPreview['external_status_name'] ?: 'Brak przypisanego statusu' }}
                                    </span>
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
            @elseif ($activeTab === 'statuses')
                <section class="prestashop-card" aria-labelledby="prestashop-status-mappings-heading">
                    <div class="prestashop-card-header">
                        <strong id="prestashop-status-mappings-heading">Mapowanie statusów</strong>
                    </div>

                    <div class="prestashop-card-body">
                        @if (! $canActivate || $account->orderStates->isEmpty())
                            <div class="alert alert-warning mb-0" role="alert">
                                Wykonaj poprawny test połączenia, aby pobrać statusy zamówień PrestaShop.
                            </div>
                        @else
                            <form
                                id="prestashop-status-sync-form"
                                method="POST"
                                action="{{ route('integrations.prestashop.status-sync') }}"
                            >
                                @csrf
                            </form>

                            <form method="POST" action="{{ route('integrations.prestashop.status-mappings.update') }}">
                                @csrf
                                @method('PUT')

                                <section aria-labelledby="prestashop-inbound-statuses-heading">
                                    <h2 class="prestashop-section-title" id="prestashop-inbound-statuses-heading">
                                        Pobieranie statusów z PrestaShop
                                    </h2>

                                    <div class="row g-3 align-items-end mb-3">
                                        <div class="col-lg-5">
                                            <div class="form-check form-switch">
                                                <input name="status_sync_enabled" type="hidden" value="0">
                                                <input
                                                    class="form-check-input"
                                                    id="prestashop-status-sync-enabled"
                                                    name="status_sync_enabled"
                                                    type="checkbox"
                                                    value="1"
                                                    @checked(old('status_sync_enabled', $account->status_sync_enabled))
                                                    @disabled(! $account->is_active)
                                                >
                                                <label class="form-check-label" for="prestashop-status-sync-enabled">
                                                    Automatycznie pobieraj zmiany statusów
                                                </label>
                                            </div>
                                            <div class="form-text">
                                                Synchronizacja obejmuje tylko zamówienia wcześniej zaimportowane do OMS.
                                            </div>
                                        </div>

                                        <div class="col-sm-6 col-lg-3">
                                            <label class="form-label" for="prestashop-status-sync-interval">
                                                Sprawdzaj co
                                            </label>
                                            <select
                                                class="form-select form-select-sm @error('status_sync_interval_minutes') is-invalid @enderror"
                                                id="prestashop-status-sync-interval"
                                                name="status_sync_interval_minutes"
                                            >
                                                @foreach ([1 => '1 minutę', 5 => '5 minut', 10 => '10 minut'] as $minutes => $label)
                                                    <option
                                                        value="{{ $minutes }}"
                                                        @selected((int) old('status_sync_interval_minutes', $account->status_sync_interval_minutes ?: 1) === $minutes)
                                                    >{{ $label }}</option>
                                                @endforeach
                                            </select>
                                        </div>

                                        <div class="col-sm-6 col-lg-4">
                                            <button
                                                class="btn btn-sm btn-outline-primary"
                                                type="submit"
                                                form="prestashop-status-sync-form"
                                                @disabled(! $account->is_active)
                                            >
                                                <i class="bi bi-arrow-repeat" aria-hidden="true"></i>
                                                Synchronizuj teraz
                                            </button>
                                        </div>
                                    </div>

                                    <div class="small text-muted mb-3">
                                        @if ($account->last_status_sync_at)
                                            Ostatnia poprawna synchronizacja:
                                            {{ $account->last_status_sync_at->format('d.m.Y H:i:s') }}
                                        @else
                                            Statusy nie były jeszcze synchronizowane.
                                        @endif
                                    </div>

                                    @if ($account->last_status_sync_error)
                                        <div class="alert alert-danger py-2 small" role="alert">
                                            <strong>Ostatni błąd synchronizacji:</strong>
                                            {{ $account->last_status_sync_error }}
                                            @if ($account->last_status_sync_error_at)
                                                <span class="d-block mt-1 text-muted">
                                                    {{ $account->last_status_sync_error_at->format('d.m.Y H:i:s') }}
                                                </span>
                                            @endif
                                        </div>
                                    @endif

                                    <div class="table-responsive">
                                        <table class="table table-sm align-middle mb-0">
                                            <thead>
                                                <tr>
                                                    <th scope="col">Status PrestaShop</th>
                                                    <th scope="col">Znaczenie</th>
                                                    <th scope="col">Status OMS</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                @foreach ($account->orderStates as $state)
                                                    @php($selectedInbound = old('inbound.'.$state->external_id, $inboundStatusMappings->get((string) $state->external_id, '')))
                                                    <tr>
                                                        <td>
                                                            @if ($state->color)
                                                                <span class="prestashop-color" style="background-color: {{ $state->color }}" aria-hidden="true"></span>
                                                            @endif
                                                            {{ $state->name }}
                                                            <span class="text-muted">(ID {{ $state->external_id }})</span>
                                                            @if (! $state->is_available)
                                                                <span class="badge text-bg-warning ms-1">Niedostępny</span>
                                                            @elseif ($state->deleted)
                                                                <span class="badge text-bg-secondary ms-1">Usunięty</span>
                                                            @endif
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
                                                        <td>
                                                            <select
                                                                class="form-select form-select-sm prestashop-mapping-select @error('inbound.'.$state->external_id) is-invalid @enderror"
                                                                name="inbound[{{ $state->external_id }}]"
                                                            >
                                                                <option value="">Nie zmieniaj statusu w OMS</option>
                                                                @foreach ($orderStatuses as $status)
                                                                    <option value="{{ $status['code'] }}" @selected($selectedInbound === $status['code'])>
                                                                        {{ $status['name'] }}
                                                                    </option>
                                                                @endforeach
                                                            </select>
                                                        </td>
                                                    </tr>
                                                @endforeach
                                            </tbody>
                                        </table>
                                    </div>

                                </section>

                                <section class="prestashop-section" aria-labelledby="prestashop-outbound-statuses-heading">
                                    <h2 class="prestashop-section-title" id="prestashop-outbound-statuses-heading">
                                        Przekazywanie statusów do PrestaShop
                                    </h2>

                                    <div class="table-responsive">
                                        <table class="table table-sm align-middle mb-0">
                                            <thead>
                                                <tr>
                                                    <th scope="col">Status OMS</th>
                                                    <th scope="col">Status PrestaShop</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                @foreach ($orderStatuses as $status)
                                                    @php($selectedOutbound = old('outbound.'.$status['code'], $outboundStatusMappings->get($status['code'], '')))
                                                    <tr>
                                                        <td>
                                                            <span class="prestashop-color" style="background-color: {{ $status['color'] }}" aria-hidden="true"></span>
                                                            {{ $status['name'] }}
                                                        </td>
                                                        <td>
                                                            <select
                                                                class="form-select form-select-sm prestashop-mapping-select @error('outbound.'.$status['code']) is-invalid @enderror"
                                                                name="outbound[{{ $status['code'] }}]"
                                                            >
                                                                <option value="">Nie przekazuj do PrestaShop</option>
                                                                @foreach ($account->orderStates as $state)
                                                                    <option
                                                                        value="{{ $state->external_id }}"
                                                                        @selected((string) $selectedOutbound === (string) $state->external_id)
                                                                        @disabled(! $state->is_available || $state->deleted)
                                                                    >
                                                                        {{ $state->name }} (ID {{ $state->external_id }}){{ ! $state->is_available || $state->deleted ? ' - niedostępny' : '' }}
                                                                    </option>
                                                                @endforeach
                                                            </select>
                                                        </td>
                                                    </tr>
                                                @endforeach
                                            </tbody>
                                        </table>
                                    </div>

                                    <div class="form-check form-switch mt-3">
                                        <input name="notify_customer_on_status_change" type="hidden" value="0">
                                        <input
                                            class="form-check-input"
                                            id="prestashop-notify-customer-on-status-change"
                                            name="notify_customer_on_status_change"
                                            type="checkbox"
                                            value="1"
                                            @checked(old('notify_customer_on_status_change', $account->notify_customer_on_status_change))
                                        >
                                        <label class="form-check-label" for="prestashop-notify-customer-on-status-change">
                                            Wysyłaj klientowi e-mail z PrestaShop po przekazaniu statusu
                                        </label>
                                    </div>
                                    <div class="form-text">
                                        Wiadomość zostanie wysłana przez PrestaShop, jeśli wybrany status posiada skonfigurowany szablon e-mail.
                                    </div>
                                </section>

                                <div class="prestashop-actions">
                                    <button class="btn btn-primary" type="submit">
                                        <i class="bi bi-floppy" aria-hidden="true"></i>
                                        Zapisz ustawienia
                                    </button>
                                </div>
                            </form>
                        @endif
                    </div>
                </section>
            @else
                <section class="prestashop-card" aria-labelledby="prestashop-payment-sync-heading">
                    <div class="prestashop-card-header">
                        <strong id="prestashop-payment-sync-heading">Synchronizacja płatności</strong>
                        <span class="prestashop-status {{ $account->payment_sync_enabled ? 'prestashop-status-success' : 'prestashop-status-idle' }}">
                            <span class="prestashop-status-dot" aria-hidden="true"></span>
                            {{ $account->payment_sync_enabled ? 'Włączona' : 'Wyłączona' }}
                        </span>
                    </div>

                    <div class="prestashop-card-body">
                        <form method="POST" action="{{ route('integrations.prestashop.payment-sync.update') }}">
                            @csrf
                            @method('PUT')

                            <div class="row g-3 align-items-end">
                                <div class="col-lg-8">
                                    <input name="payment_sync_enabled" type="hidden" value="0">
                                    <div class="form-check form-switch">
                                        <input
                                            id="prestashop-payment-sync-enabled"
                                            class="form-check-input @error('payment_sync_enabled') is-invalid @enderror"
                                            name="payment_sync_enabled"
                                            type="checkbox"
                                            value="1"
                                            @checked(old('payment_sync_enabled', $account->payment_sync_enabled))
                                            @disabled(! $account->is_active)
                                        >
                                        <label class="form-check-label" for="prestashop-payment-sync-enabled">
                                            Automatycznie pobieraj nowe wpłaty i dopłaty
                                        </label>
                                    </div>
                                    <div class="form-text">
                                        Sprawdzanie odbywa się co 1 minutę i obejmuje wyłącznie zamówienia wcześniej zaimportowane do OMS. Pierwsze uruchomienie sprawdzi wszystkie powiązane zamówienia.
                                    </div>
                                    <div class="mt-3">
                                        <label class="form-label" for="prestashop-warn-missing-transaction-id">Wyświetlaj ostrzeżenie w przypadku braku identyfikatora transakcji:</label>
                                        <select id="prestashop-warn-missing-transaction-id" name="warn_missing_transaction_id" class="form-select form-select-sm w-auto @error('warn_missing_transaction_id') is-invalid @enderror" @disabled(! $account->is_active)>
                                            <option value="0" @selected(! old('warn_missing_transaction_id', $account->warn_missing_transaction_id))>Nie</option>
                                            <option value="1" @selected(old('warn_missing_transaction_id', $account->warn_missing_transaction_id))>Tak</option>
                                        </select>
                                        @error('warn_missing_transaction_id')
                                            <div class="invalid-feedback">{{ $message }}</div>
                                        @enderror
                                    </div>
                                </div>
                                <div class="col-lg-4 d-grid d-lg-flex justify-content-lg-end">
                                    <button class="btn btn-primary" type="submit" @disabled(! $account->is_active)>
                                        Zapisz ustawienia
                                    </button>
                                </div>
                            </div>
                        </form>

                        @if (! $account->is_active)
                            <div class="alert alert-warning py-2 mt-3 mb-0" role="alert">
                                Najpierw włącz integrację PrestaShop w zakładce Połączenie.
                            </div>
                        @endif

                        <section class="prestashop-section" aria-labelledby="prestashop-payment-sync-status-heading">
                            <h2 class="prestashop-section-title" id="prestashop-payment-sync-status-heading">Stan synchronizacji</h2>

                            <div class="prestashop-meta">
                                <div>
                                    <span class="prestashop-meta-label">Ostatnia poprawna synchronizacja</span>
                                    <span class="prestashop-meta-value">
                                        {{ $account->last_payment_sync_at?->format('d.m.Y H:i:s') ?? '-' }}
                                    </span>
                                </div>
                                <div>
                                    <span class="prestashop-meta-label">Ostatni błąd</span>
                                    <span class="prestashop-meta-value">
                                        {{ $account->last_payment_sync_error_at?->format('d.m.Y H:i:s') ?? '-' }}
                                    </span>
                                </div>
                            </div>

                            @if ($account->last_payment_sync_error)
                                <div class="alert alert-danger py-2 mt-3 mb-0" role="alert">
                                    {{ $account->last_payment_sync_error }}
                                </div>
                            @endif

                            <div class="d-flex justify-content-end mt-3">
                                <form method="POST" action="{{ route('integrations.prestashop.payment-sync.run') }}">
                                    @csrf
                                    <button
                                        class="btn btn-outline-primary"
                                        type="submit"
                                        @disabled(! $account->is_active)
                                    >
                                        <i class="bi bi-arrow-repeat" aria-hidden="true"></i>
                                        Sprawdź płatności teraz
                                    </button>
                                </form>
                            </div>
                        </section>
                    </div>
                </section>
            @endif
        </div>
    </div>

    @if (session('success'))
        <script>
            document.addEventListener('DOMContentLoaded', () => {
                const alert = document.querySelector('[data-prestashop-success-alert]');

                if (!alert) {
                    return;
                }

                window.setTimeout(() => {
                    if (window.bootstrap?.Alert) {
                        window.bootstrap.Alert.getOrCreateInstance(alert).close();
                        return;
                    }

                    alert.remove();
                }, 5000);
            });
        </script>
    @endif
@endsection
