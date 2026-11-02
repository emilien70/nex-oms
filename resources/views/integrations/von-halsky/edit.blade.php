@extends('layouts.app')

@section('title', 'InPost Von Halsky - NEX-OMS')

@section('content')
    <style>
        .halsky-page { max-width: 1180px; color: #3c4654; }
        .halsky-page h1 { font-size: 20px; font-weight: 700; margin: 0; }
        .halsky-page h2 { font-size: 15px; font-weight: 600; margin: 0 0 18px; }
        .halsky-page .nav-link { font-size: 13px; }
        .halsky-form-row { display: grid; grid-template-columns: 180px minmax(0, 1fr); align-items: center; gap: 12px; margin-bottom: 16px; }
        .halsky-form-row label { font-size: 13px; }
        .halsky-section { padding: 24px 0; border-bottom: 1px solid #dce1e7; }
        .halsky-details { display: grid; grid-template-columns: 150px minmax(0, 1fr); gap: 8px 16px; font-size: 13px; margin: 0; }
        .halsky-details dt { font-weight: 400; color: #7a838f; }
        .halsky-details dd { margin: 0; overflow-wrap: anywhere; }
        .halsky-preview-grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 24px; }
        .halsky-contact { font-size: 13px; overflow-wrap: anywhere; }
        .halsky-page .table { font-size: 13px; }
        .halsky-page .table th { font-weight: 500; color: #667085; }
        .halsky-page .nav-tabs { flex-wrap: nowrap; overflow-x: auto; overflow-y: hidden; }
        .halsky-page .nav-tabs .nav-link { flex-shrink: 0; white-space: nowrap; }
        .halsky-comment { white-space: pre-wrap; overflow-wrap: anywhere; font-size: 13px; }
        @media (max-width: 767px) {
            .halsky-form-row { grid-template-columns: minmax(0, 1fr); gap: 5px; }
            .halsky-preview-grid { grid-template-columns: minmax(0, 1fr); }
            .halsky-details { grid-template-columns: 110px minmax(0, 1fr); gap: 8px; }
        }
    </style>

    <div class="halsky-page">
        <header class="d-flex align-items-center justify-content-between gap-3 mb-4">
            <h1>InPost Von Halsky @if ($account->exists) <span class="text-muted fw-normal">» {{ $account->name }}</span> @endif</h1>
            <a href="{{ route('integrations.index') }}" class="btn btn-sm btn-outline-secondary" title="Lista integracji" aria-label="Lista integracji"><i class="bi bi-arrow-left" aria-hidden="true"></i></a>
        </header>

        @if (session('error'))
            <div class="alert alert-danger py-2" role="alert">{{ session('error') }}</div>
        @endif

        <nav class="nav nav-tabs" aria-label="Konfiguracja Von Halsky">
            <a class="nav-link {{ $activeTab === 'connection' ? 'active' : '' }}" @if ($activeTab === 'connection') aria-current="page" @endif href="{{ route('integrations.von-halsky.edit') }}">Połączenie</a>
            <a class="nav-link {{ $activeTab === 'orders' ? 'active' : '' }}" @if ($activeTab === 'orders') aria-current="page" @endif href="{{ route('integrations.von-halsky.edit', ['tab' => 'orders']) }}">Zamówienia</a>
            <a class="nav-link {{ $activeTab === 'statuses' ? 'active' : '' }}" @if ($activeTab === 'statuses') aria-current="page" @endif href="{{ route('integrations.von-halsky.edit', ['tab' => 'statuses']) }}">Statusy zamówień</a>
        </nav>

        @if ($activeTab === 'connection')
            <section class="halsky-section">
                <div class="d-flex align-items-center justify-content-between gap-3 mb-4">
                    <h2 class="mb-0">Konfiguracja połączenia</h2>
                    <span class="badge {{ $account->is_active ? 'bg-success' : 'bg-secondary' }}">{{ $account->is_active ? 'Połączono' : 'Nie połączono' }}</span>
                </div>
                <form method="POST" action="{{ route('integrations.von-halsky.update') }}" data-halsky-submit>
                    @csrf
                    @method('PUT')
                    <input type="hidden" name="expected_lock_version" value="{{ $account->lock_version }}">
                    <div class="halsky-form-row">
                        <label for="halsky-name">Nazwa integracji</label>
                        <input id="halsky-name" name="name" class="form-control form-control-sm" maxlength="120" required value="{{ old('name', $account->name) }}">
                    </div>
                    <div class="halsky-form-row">
                        <label for="halsky-environment">Środowisko</label>
                        <select id="halsky-environment" name="environment" class="form-select form-select-sm" required>
                            @foreach ($environments as $environment)
                                <option value="{{ $environment->value }}" @selected(old('environment', $account->environment->value) === $environment->value)>{{ $environment->label() }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="halsky-form-row">
                        <label for="halsky-organization">Organization ID <i class="bi bi-info-circle" title="Identyfikator UUID sklepu/marki Von Halsky (poziom 2), nie numer organizacji ShipX." aria-label="Identyfikator sklepu/marki Von Halsky"></i></label>
                        <input id="halsky-organization" name="organization_id" class="form-control form-control-sm" maxlength="36" required autocomplete="off" spellcheck="false" value="{{ old('organization_id', $account->organization_id) }}">
                    </div>
                    <div class="halsky-form-row">
                        <label for="halsky-client">Client ID</label>
                        <input id="halsky-client" name="client_id" class="form-control form-control-sm" maxlength="255" required autocomplete="off" spellcheck="false" value="{{ old('client_id', $account->client_id) }}">
                    </div>
                    <div class="halsky-form-row">
                        <label for="halsky-secret">Client Secret <i class="bi bi-info-circle" title="Puste pole zachowuje zapisany sekret. Dane własnej aplikacji sprzedawcy; nie dane aplikacji Base." aria-label="Zasady zapisu Client Secret"></i></label>
                        <input id="halsky-secret" name="client_secret" type="password" class="form-control form-control-sm" maxlength="4096" autocomplete="new-password" @required(! filled($account->client_secret)) placeholder="{{ filled($account->client_secret) ? 'Zapisany — pozostaw puste, aby zachować' : '' }}">
                    </div>
                    <div class="d-flex justify-content-end">
                        <button class="btn btn-primary btn-sm" type="submit"><i class="bi bi-check-lg me-1" aria-hidden="true"></i>Zapisz</button>
                    </div>
                </form>
            </section>
            <section class="halsky-section">
                <dl class="halsky-details mb-3">
                    <dt>Ostatni test</dt><dd>{{ $account->last_tested_at?->format('d.m.Y H:i:s') ?? 'Nie wykonano' }}</dd>
                    <dt>Wynik</dt><dd>{{ $account->last_test_message ?? '—' }}</dd>
                </dl>
                <div class="d-flex gap-2 flex-wrap justify-content-end">
                    <form method="POST" action="{{ route('integrations.von-halsky.test-connection') }}" data-halsky-submit>
                        @csrf
                        <input type="hidden" name="expected_lock_version" value="{{ $account->lock_version }}">
                        <button class="btn btn-outline-primary btn-sm" type="submit" @disabled(! $account->hasCompleteCredentials())><i class="bi bi-plug me-1" aria-hidden="true"></i>Testuj połączenie</button>
                    </form>
                    <form method="POST" action="{{ route('integrations.von-halsky.disconnect') }}" data-halsky-submit>
                        @csrf
                        @method('DELETE')
                        <input type="hidden" name="expected_lock_version" value="{{ $account->lock_version }}">
                        <button class="btn btn-outline-secondary btn-sm" type="submit" @disabled(! $account->is_active)><i class="bi bi-plug-fill me-1" aria-hidden="true"></i>Rozłącz w OMS</button>
                    </form>
                </div>
            </section>
        @elseif ($activeTab === 'statuses')
            @include('integrations.von-halsky._status-mappings')
        @else
            <section class="halsky-section">
                <h2>Ustawienia importu</h2>
                <form method="POST" action="{{ route('integrations.von-halsky.import-settings') }}" data-halsky-submit>
                    @csrf
                    <input type="hidden" name="expected_lock_version" value="{{ $account->lock_version }}">
                    <div class="halsky-form-row">
                        <label for="halsky-initial-status">Status początkowy</label>
                        <select id="halsky-initial-status" name="initial_status_setting_id" class="form-select form-select-sm" @disabled(! $account->exists)>
                            <option value="">{{ 'Domyślny: '.($orderStatuses->firstWhere('code', 'new')['name'] ?? 'brak statusu Nowe') }}</option>
                            @foreach ($orderStatuses as $status)
                                <option value="{{ $status['id'] ?? '' }}" @selected((string) old('initial_status_setting_id', $account->initial_status_setting_id) === (string) ($status['id'] ?? ''))>{{ $status['name'] }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="halsky-form-row">
                        <label for="halsky-default-vat">Domyślna stawka VAT</label>
                        <div class="input-group input-group-sm">
                            <input id="halsky-default-vat" name="default_vat_rate" class="form-control" type="number" min="0" max="100" step="0.01" placeholder="Nie ustawiaj automatycznie" value="{{ old('default_vat_rate', $account->default_vat_rate) }}" @disabled(! $account->exists)>
                            <span class="input-group-text">%</span>
                        </div>
                    </div>
                    @foreach (['use_linked_products' => 'Korzystaj z powiązanych produktów OMS', 'use_catalog_product_names' => 'Pobieraj nazwy z produktów OMS', 'use_catalog_vat' => 'Pobieraj VAT z produktów OMS'] as $field => $label)
                        <div class="halsky-form-row">
                            <label for="halsky-{{ $field }}">{{ $label }}</label>
                            <select id="halsky-{{ $field }}" name="{{ $field }}" class="form-select form-select-sm" @disabled(! $account->exists)>
                                <option value="0" @selected(! old($field, $account->{$field}))>Nie</option>
                                <option value="1" @selected((bool) old($field, $account->{$field}))>Tak</option>
                            </select>
                        </div>
                    @endforeach
                    <div class="d-flex justify-content-end"><button class="btn btn-primary btn-sm" type="submit" @disabled(! $account->exists)><i class="bi bi-check-lg me-1" aria-hidden="true"></i>Zapisz</button></div>
                </form>
            </section>
            <section class="halsky-section">
                <h2>Pobieranie zamówień</h2>
                <form method="POST" action="{{ route('integrations.von-halsky.automatic-import') }}" data-halsky-submit>
                    @csrf
                    <input type="hidden" name="expected_lock_version" value="{{ $account->lock_version }}">
                    <div class="halsky-form-row">
                        <label for="halsky-auto-enabled">Automatyczne pobieranie</label>
                        <select id="halsky-auto-enabled" name="order_import_enabled" class="form-select form-select-sm" @disabled(! $account->exists || ! $discoveryReady)>
                            <option value="0" @selected(! old('order_import_enabled', $account->order_import_enabled))>Nie</option>
                            <option value="1" @selected((bool) old('order_import_enabled', $account->order_import_enabled))>Tak</option>
                        </select>
                    </div>
                    <div class="halsky-form-row">
                        <label for="halsky-auto-interval">Częstotliwość pobierania</label>
                        <select id="halsky-auto-interval" name="order_import_interval_minutes" class="form-select form-select-sm" @disabled(! $account->exists || ! $discoveryReady)>
                            @foreach ([1 => 'Co 1 minutę', 10 => 'Co 10 minut'] as $minutes => $label)
                                <option value="{{ $minutes }}" @selected((int) old('order_import_interval_minutes', $account->order_import_interval_minutes ?? 10) === $minutes)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="halsky-form-row">
                        <label for="halsky-auto-from">Pobieraj zamówienia od</label>
                        <input id="halsky-auto-from" type="datetime-local" name="order_import_started_at" class="form-control form-control-sm"
                            value="{{ old('order_import_started_at', $account->order_import_started_at?->format('Y-m-d\TH:i')) }}" @disabled(! $account->exists || ! $discoveryReady)>
                    </div>
                    <div class="d-flex justify-content-end"><button class="btn btn-primary btn-sm" type="submit" @disabled(! $account->exists || ! $discoveryReady)><i class="bi bi-check-lg me-1" aria-hidden="true"></i>Zapisz</button></div>
                </form>
                <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mt-3">
                    <div class="small text-muted">Ostatnie poprawne sprawdzenie: {{ $account->last_order_scan_at?->format('d.m.Y H:i:s') ?? '—' }}</div>
                    <form method="POST" action="{{ route('integrations.von-halsky.check-now') }}" data-halsky-submit>
                        @csrf
                        <input type="hidden" name="expected_lock_version" value="{{ $account->lock_version }}">
                        <button class="btn btn-outline-primary btn-sm" type="submit" @disabled(! $discoveryReady || ! $account->is_active || (! $account->order_import_started_at && ! $canCheckKnownStatuses))><i class="bi bi-arrow-repeat me-1" aria-hidden="true"></i>Sprawdź teraz</button>
                    </form>
                </div>
                @if ($account->last_order_import_error)<div class="alert alert-warning py-2 small mt-3 mb-0" role="status">{{ $account->last_order_import_error }}</div>@endif
            </section>
            <section class="halsky-section">
                <h2>Podgląd zamówienia</h2>
                @if (! $account->is_active)
                    <div class="alert alert-secondary py-2 small" role="status">Połączenie nie zostało jeszcze potwierdzone.</div>
                @endif
                <form method="POST" action="{{ route('integrations.von-halsky.preview') }}" data-halsky-submit>
                    @csrf
                    <input type="hidden" name="expected_lock_version" value="{{ $account->lock_version }}">
                    <div class="halsky-form-row mb-0">
                        <label for="halsky-order">ID zamówienia Von Halsky</label>
                        <div class="d-flex gap-2 align-items-start">
                            <input id="halsky-order" name="order_identifier" class="form-control form-control-sm" required maxlength="255" autocomplete="off" value="{{ $orderIdentifier ?? old('order_identifier') }}" @disabled(! $account->is_active)>
                            <button class="btn btn-outline-primary btn-sm text-nowrap" type="submit" @disabled(! $account->is_active)><i class="bi bi-search me-1" aria-hidden="true"></i>Pobierz podgląd</button>
                        </div>
                    </div>
                </form>
            </section>
            @if ($previewError)
                <div class="alert alert-danger mt-3" role="alert">{{ $previewError }}</div>
            @endif
            @if ($orderPreview)
                @include('integrations.von-halsky._preview', ['preview' => $orderPreview])
            @endif
            @include('integrations.von-halsky._import-history')
        @endif
    </div>
    <script>
        document.querySelectorAll('[data-halsky-submit]').forEach(form => {
            form.addEventListener('submit', event => {
                if (form.dataset.submitting === '1') { event.preventDefault(); return; }
                form.dataset.submitting = '1';
                form.querySelectorAll('button[type="submit"]').forEach(button => button.disabled = true);
            });
        });
        window.addEventListener('pageshow', event => { if (event.persisted) window.location.reload(); });
    </script>
@endsection
