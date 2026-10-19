@extends('layouts.app')

@section('title', 'Allegro - NEX-OMS')

@section('content')
    @php
        $deviceAuthorization = session('allegro_account_device_authorization');
        $status = $account->isOperational()
            ? ['label' => 'Połączono', 'class' => 'is-connected']
            : ($account->last_test_status === \Modules\Integrations\Allegro\Models\AllegroAccount::STATUS_ERROR
                ? ['label' => 'Błąd połączenia', 'class' => 'is-error']
                : ['label' => 'Niepołączono', 'class' => 'is-pending']);
        $scopeLabels = [
            'allegro:api:profile:read' => 'Dane konta',
            'allegro:api:orders:read' => 'Odczyt zamówień',
            'allegro:api:orders:write' => 'Obsługa zamówień',
            'allegro:api:shipments:read' => 'Odczyt przesyłek',
            'allegro:api:shipments:write' => 'Obsługa przesyłek',
        ];
    @endphp

    <style>
        .allegro-page { background: #f4f6f8; margin: -1.5rem; min-height: 100vh; overflow-x: hidden; padding: 24px; }
        .allegro-header { align-items: center; display: flex; justify-content: space-between; margin-bottom: 18px; }
        .allegro-title { color: #111827; font-size: 22px; font-weight: 700; margin: 0; }
        .allegro-tabs { border-bottom: 1px solid #d8dee7; display: flex; gap: 6px; margin-bottom: 16px; max-width: 100%; overflow-x: auto; }
        .allegro-tab { border-bottom: 2px solid transparent; color: #4b5563; flex: 0 0 auto; padding: 10px 16px; text-decoration: none; white-space: nowrap; }
        .allegro-tab.active { border-color: #ff5a00; color: #111827; font-weight: 700; }
        .allegro-tab.disabled { color: #9ca3af; cursor: default; }
        .allegro-panel { background: #fff; border: 1px solid #dce2e9; border-radius: 6px; box-shadow: 0 1px 3px rgba(15, 23, 42, .07); margin-bottom: 16px; }
        .allegro-panel-header { align-items: center; border-bottom: 1px solid #e5e7eb; display: flex; justify-content: space-between; padding: 16px 18px; }
        .allegro-panel-title { font-size: 15px; font-weight: 700; margin: 0; }
        .allegro-panel-body { padding: 18px; }
        .allegro-state { align-items: center; display: inline-flex; font-size: 12px; font-weight: 700; gap: 7px; }
        .allegro-state::before { background: #9ca3af; border-radius: 50%; content: ''; height: 8px; width: 8px; }
        .allegro-state.is-connected { color: #137a3d; }
        .allegro-state.is-connected::before { background: #198754; }
        .allegro-state.is-error { color: #b42318; }
        .allegro-state.is-error::before { background: #dc3545; }
        .allegro-details { display: grid; gap: 10px 22px; grid-template-columns: repeat(3, minmax(0, 1fr)); }
        .allegro-detail-label { color: #6b7280; display: block; font-size: 11px; margin-bottom: 3px; text-transform: uppercase; }
        .allegro-scope-list { display: flex; flex-wrap: wrap; gap: 7px; }
        .allegro-scope { background: #f8fafc; border: 1px solid #dbe2ea; border-radius: 4px; color: #374151; font-size: 12px; padding: 5px 8px; }
        .allegro-scope.is-missing { background: #fff5f4; border-color: #f4b4ad; color: #b42318; }
        .allegro-device { background: #f8fafc; border: 1px solid #dbe2ea; border-radius: 5px; padding: 14px; }
        .allegro-actions { align-items: center; border-top: 1px solid #e5e7eb; display: flex; flex-wrap: wrap; gap: 8px; justify-content: flex-end; margin-top: 18px; padding-top: 16px; }
        @media (max-width: 800px) { .allegro-details { grid-template-columns: 1fr; } .allegro-page { margin: -1rem; padding: 16px; } }
    </style>

    <div class="allegro-page">
        <header class="allegro-header">
            <h1 class="allegro-title">Allegro</h1>
            <a class="btn btn-sm btn-outline-secondary" href="{{ route('integrations.index') }}">Lista integracji</a>
        </header>

        <nav class="allegro-tabs" aria-label="Ustawienia Allegro">
            <a class="allegro-tab active" href="{{ route('integrations.allegro.edit') }}">Połączenie</a>
            <span class="allegro-tab disabled">Zamówienia</span>
            <span class="allegro-tab disabled">Statusy</span>
            <span class="allegro-tab disabled">Przesyłki i faktury</span>
        </nav>

        @if (session('success'))
            <div class="alert alert-success py-2 small">{{ session('success') }}</div>
        @endif
        @error('allegro_connection')
            <div class="alert alert-danger py-2 small">{{ $message }}</div>
        @enderror
        @error('allegro_device')
            <div class="alert alert-danger py-2 small">{{ $message }}</div>
        @enderror
        @if (session('allegro_connection_success'))
            <div class="alert alert-success py-2 small">{{ session('allegro_connection_success') }}</div>
        @endif

        <section class="allegro-panel">
            <div class="allegro-panel-header">
                <h2 class="allegro-panel-title">Konto Allegro</h2>
                <span class="allegro-state {{ $status['class'] }}">{{ $status['label'] }}</span>
            </div>
            <div class="allegro-panel-body">
                <div class="allegro-details">
                    <div><span class="allegro-detail-label">Nazwa integracji</span><strong>{{ $account->name }}</strong></div>
                    <div><span class="allegro-detail-label">Konto Allegro</span><strong>{{ $account->allegro_login ?: '...' }}</strong>@if ($account->allegro_user_id)<span class="text-muted small"> ({{ $account->allegro_user_id }})</span>@endif</div>
                    <div><span class="allegro-detail-label">Środowisko</span><strong>{{ $account->environment === 'production' ? 'Produkcja' : 'Sandbox' }}</strong></div>
                    <div><span class="allegro-detail-label">Marketplace</span><span>{{ $account->marketplace_id ?: '...' }}</span></div>
                    <div><span class="allegro-detail-label">Ważność tokenu</span><span>{{ $account->access_token_expires_at?->format('d.m.Y H:i') ?: '...' }}</span></div>
                    <div><span class="allegro-detail-label">Ostatni test</span><span>{{ $account->last_tested_at?->format('d.m.Y H:i') ?: '...' }}</span></div>
                </div>
                @if ($account->last_error)
                    <div class="alert alert-danger py-2 small mt-3 mb-0">{{ $account->last_error }}</div>
                @endif
            </div>
        </section>

        <section class="allegro-panel">
            <div class="allegro-panel-header"><h2 class="allegro-panel-title">Konfiguracja połączenia</h2></div>
            <div class="allegro-panel-body">
                <form id="allegroAccountForm" method="POST" action="{{ route('integrations.allegro.update') }}" novalidate>
                    @csrf
                    @method('PUT')
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label" for="allegro_name">Nazwa wyświetlana</label>
                            <input id="allegro_name" class="form-control @error('name') is-invalid @enderror" name="name" value="{{ old('name', $account->name) }}" required maxlength="255">
                            @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="allegro_environment">Środowisko</label>
                            <select id="allegro_environment" class="form-select @error('environment') is-invalid @enderror" name="environment" required>
                                <option value="production" @selected(old('environment', $account->environment) === 'production')>Produkcja</option>
                                <option value="sandbox" @selected(old('environment', $account->environment) === 'sandbox')>Sandbox</option>
                            </select>
                            @error('environment')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="allegro_client_id">Client ID</label>
                            <input id="allegro_client_id" class="form-control @error('client_id') is-invalid @enderror" name="client_id" value="{{ old('client_id', $account->client_id) }}" required maxlength="255" autocomplete="off">
                            @error('client_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="allegro_client_secret">Client Secret</label>
                            <input id="allegro_client_secret" class="form-control @error('client_secret') is-invalid @enderror" type="password" name="client_secret" autocomplete="new-password">
                            <div class="form-text">Pozostaw puste, aby zachować zapisany sekret.</div>
                            @error('client_secret')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                    </div>

                    <div class="mt-3">
                        <div class="small fw-semibold mb-2">Wymagane uprawnienia</div>
                        <div class="allegro-scope-list">
                            @foreach ($requiredScopes as $scope)
                                <span class="allegro-scope {{ $account->hasOAuthTokens() && ! in_array($scope, $account->granted_scopes ?? [], true) ? 'is-missing' : '' }}">{{ $scopeLabels[$scope] ?? $scope }}</span>
                            @endforeach
                        </div>
                        <div class="form-text mt-2">Integracja nie prosi o dostęp do tworzenia ani edytowania ofert.</div>
                    </div>

                    <div class="allegro-device mt-3" id="allegroDevicePanel">
                        @if (is_array($deviceAuthorization))
                            @php($readableCode = strtoupper(implode(' ', str_split(preg_replace('/\s+/', '', (string) $deviceAuthorization['user_code']), 3))))
                            <div class="fw-semibold mb-1">Potwierdź połączenie na stronie Allegro</div>
                            <div class="small text-muted mb-2">Zaloguj się na właściwe konto sprzedawcy i zaakceptuj wyświetlone uprawnienia.</div>
                            <div class="d-flex flex-wrap align-items-center gap-2">
                                <code class="fs-5 px-2 py-1 bg-white border rounded">{{ $readableCode }}</code>
                                <a class="btn btn-sm btn-primary" href="{{ $deviceAuthorization['verification_uri_complete'] }}" target="_blank" rel="noopener noreferrer">Otwórz Allegro</a>
                                <button class="btn btn-sm btn-light border" type="submit" formaction="{{ route('integrations.allegro.device.cancel') }}" formmethod="POST">Anuluj łączenie</button>
                            </div>
                            <div class="small text-primary mt-2" id="allegroDeviceStatus" role="status">Oczekiwanie na potwierdzenie w Allegro...</div>
                        @elseif ($account->isOperational())
                            <div class="fw-semibold">Konto jest połączone</div>
                            <div class="small text-muted">Token zostanie automatycznie odnowiony przed wygaśnięciem.</div>
                        @else
                            <div class="fw-semibold">Konto nie jest jeszcze połączone</div>
                            <div class="small text-muted">Zapisz dane aplikacji, a następnie rozpocznij bezpieczne łączenie przez Allegro.</div>
                        @endif
                    </div>

                    <div class="allegro-actions">
                        @if (! is_array($deviceAuthorization))
                            <button class="btn btn-sm btn-outline-primary" type="submit" formaction="{{ route('integrations.allegro.device.start') }}" formmethod="POST">
                                {{ $account->hasOAuthTokens() ? 'Połącz ponownie' : 'Połącz z Allegro' }}
                            </button>
                        @endif
                        <button class="btn btn-sm btn-primary" type="submit">Zapisz ustawienia</button>
                    </div>
                </form>

                @if ($account->hasOAuthTokens())
                    <div class="d-flex flex-wrap gap-2 justify-content-end mt-2">
                        <form method="POST" action="{{ route('integrations.allegro.test-connection') }}">@csrf
                            <button class="btn btn-sm btn-outline-secondary" type="submit">Sprawdź połączenie</button>
                        </form>
                        <form method="POST" action="{{ route('integrations.allegro.disconnect') }}" onsubmit="return confirm('Czy rozłączyć konto Allegro? Dane OMS nie zostaną usunięte.')">@csrf @method('DELETE')
                            <button class="btn btn-sm btn-outline-danger" type="submit">Rozłącz konto</button>
                        </form>
                    </div>
                @endif
            </div>
        </section>
    </div>

    @if (is_array($deviceAuthorization))
        <script>
            document.addEventListener('DOMContentLoaded', () => {
                const status = document.getElementById('allegroDeviceStatus');
                let interval = {{ max(5, (int) ($deviceAuthorization['interval'] ?? 5)) }};
                const poll = () => window.setTimeout(async () => {
                    try {
                        const response = await fetch(@json(route('integrations.allegro.device.poll')), {
                            method: 'POST',
                            headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': @json(csrf_token()) },
                        });
                        const result = await response.json();
                        if (result.status === 'connected') {
                            if (status) status.textContent = 'Konto zostało połączone. Odświeżam ekran...';
                            window.setTimeout(() => window.location.reload(), 600);
                            return;
                        }
                        if (!response.ok) {
                            if (status) { status.textContent = result.message || 'Nie udało się połączyć konta.'; status.classList.replace('text-primary', 'text-danger'); }
                            return;
                        }
                        interval = Math.max(5, Number(result.interval || interval));
                        poll();
                    } catch (error) {
                        if (status) status.textContent = 'Chwilowy problem z połączeniem. Ponawiam sprawdzenie...';
                        poll();
                    }
                }, interval * 1000);
                poll();
            });
        </script>
    @endif
@endsection
