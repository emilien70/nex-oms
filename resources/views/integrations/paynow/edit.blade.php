@extends('layouts.app')

@section('title', 'PayNow - NEX-OMS')

@section('content')
    <style>
        .paynow-page {
            background: #f4f6f8;
            margin: -1.5rem;
            min-height: 100vh;
            padding: 24px;
        }

        .paynow-shell {
            max-width: 980px;
        }

        .paynow-heading {
            align-items: center;
            display: flex;
            justify-content: space-between;
            margin-bottom: 16px;
        }

        .paynow-heading h1 {
            font-size: 20px;
            font-weight: 700;
            margin: 0;
        }

        .paynow-card {
            background: #fff;
            border: 1px solid #d7dde5;
            border-radius: 6px;
            box-shadow: 0 1px 3px rgba(17, 24, 39, .06);
        }

        .paynow-card-header {
            align-items: center;
            border-bottom: 1px solid #e3e7ed;
            display: flex;
            justify-content: space-between;
            padding: 16px 18px;
        }

        .paynow-card-header strong {
            font-size: 15px;
        }

        .paynow-card-body {
            padding: 18px;
        }

        .paynow-status {
            align-items: center;
            display: inline-flex;
            font-size: 12px;
            font-weight: 700;
            gap: 6px;
        }

        .paynow-status-dot {
            border-radius: 50%;
            height: 8px;
            width: 8px;
        }

        .paynow-status-success {
            color: #137333;
        }

        .paynow-status-success .paynow-status-dot {
            background: #198754;
        }

        .paynow-status-error {
            color: #b42318;
        }

        .paynow-status-error .paynow-status-dot {
            background: #dc3545;
        }

        .paynow-status-idle {
            color: #667085;
        }

        .paynow-status-idle .paynow-status-dot {
            background: #98a2b3;
        }

        .paynow-actions {
            align-items: center;
            border-top: 1px solid #e3e7ed;
            display: flex;
            gap: 8px;
            justify-content: flex-end;
            margin-top: 18px;
            padding-top: 16px;
        }

        @media (max-width: 767.98px) {
            .paynow-page {
                margin: -1rem;
                padding: 16px;
            }

            .paynow-heading {
                align-items: flex-start;
                flex-direction: column;
                gap: 10px;
            }

            .paynow-actions {
                align-items: stretch;
                flex-direction: column-reverse;
            }

            .paynow-actions .btn,
            .paynow-actions form {
                width: 100%;
            }

            .paynow-actions form .btn {
                width: 100%;
            }
        }
    </style>

    <div class="paynow-page">
        <div class="paynow-shell">
            <div class="paynow-heading">
                <h1>PayNow</h1>
                <a class="btn btn-sm btn-outline-secondary" href="{{ route('integrations.index') }}">
                    <i class="bi bi-arrow-left" aria-hidden="true"></i>
                    Lista integracji
                </a>
            </div>

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

            <section class="paynow-card" aria-labelledby="paynow-settings-heading">
                <div class="paynow-card-header">
                    <strong id="paynow-settings-heading">Konto PayNow</strong>

                    @if ($account->last_test_status?->value === 'success')
                        <span class="paynow-status paynow-status-success">
                            <span class="paynow-status-dot" aria-hidden="true"></span>
                            Połączono
                        </span>
                    @elseif ($account->last_test_status?->value === 'error')
                        <span class="paynow-status paynow-status-error">
                            <span class="paynow-status-dot" aria-hidden="true"></span>
                            Błąd połączenia
                        </span>
                    @else
                        <span class="paynow-status paynow-status-idle">
                            <span class="paynow-status-dot" aria-hidden="true"></span>
                            Nie testowano
                        </span>
                    @endif
                </div>

                <div class="paynow-card-body">
                    @if ($account->last_test_message)
                        <div class="alert {{ $account->last_test_status?->value === 'success' ? 'alert-success' : 'alert-danger' }} py-2 small">
                            {{ $account->last_test_message }}
                            @if ($account->last_tested_at)
                                <span class="d-block mt-1 text-muted">{{ $account->last_tested_at->format('d.m.Y H:i') }}</span>
                            @endif
                        </div>
                    @endif

                    <form id="paynow-settings-form" method="POST" action="{{ route('integrations.paynow.update') }}" novalidate>
                        @csrf
                        @method('PUT')

                        <div class="row g-3">
                            <div class="col-md-7">
                                <label class="form-label" for="paynow-name">Nazwa konta</label>
                                <input
                                    id="paynow-name"
                                    class="form-control @error('name') is-invalid @enderror"
                                    name="name"
                                    type="text"
                                    maxlength="120"
                                    value="{{ old('name', $account->name) }}"
                                    required
                                >
                            </div>

                            <div class="col-md-5">
                                <label class="form-label" for="paynow-environment">Środowisko</label>
                                <select id="paynow-environment" class="form-select @error('environment') is-invalid @enderror" name="environment" required>
                                    @foreach ($environments as $environment)
                                        <option
                                            value="{{ $environment->value }}"
                                            @selected(old('environment', $account->environment->value) === $environment->value)
                                        >
                                            {{ $environment->label() }}
                                        </option>
                                    @endforeach
                                </select>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label" for="paynow-api-key">Klucz API</label>
                                <input
                                    id="paynow-api-key"
                                    class="form-control @error('api_key') is-invalid @enderror"
                                    name="api_key"
                                    type="password"
                                    maxlength="500"
                                    autocomplete="new-password"
                                    placeholder="{{ filled($account->api_key) ? 'Zapisano - pozostaw puste bez zmian' : 'Wpisz klucz API' }}"
                                >
                            </div>

                            <div class="col-md-6">
                                <label class="form-label" for="paynow-signature-key">Klucz podpisu</label>
                                <input
                                    id="paynow-signature-key"
                                    class="form-control @error('signature_key') is-invalid @enderror"
                                    name="signature_key"
                                    type="password"
                                    maxlength="500"
                                    autocomplete="new-password"
                                    placeholder="{{ filled($account->signature_key) ? 'Zapisano - pozostaw puste bez zmian' : 'Wpisz klucz podpisu' }}"
                                >
                            </div>

                            <div class="col-md-5">
                                <label class="form-label" for="paynow-validity">Ważność linku</label>
                                <select id="paynow-validity" class="form-select @error('link_validity_seconds') is-invalid @enderror" name="link_validity_seconds" required>
                                    @foreach ([3600 => '1 godzina', 86400 => '1 dzień', 259200 => '3 dni', 604800 => '7 dni', 1209600 => '14 dni', 2592000 => '30 dni', 7862400 => '91 dni'] as $seconds => $label)
                                        <option value="{{ $seconds }}" @selected((int) old('link_validity_seconds', $account->link_validity_seconds) === $seconds)>
                                            {{ $label }}
                                        </option>
                                    @endforeach
                                </select>
                            </div>

                            <div class="col-md-7">
                                <label class="form-label" for="paynow-description">Opis płatności</label>
                                <input
                                    id="paynow-description"
                                    class="form-control @error('description_template') is-invalid @enderror"
                                    name="description_template"
                                    type="text"
                                    maxlength="100"
                                    value="{{ old('description_template', $account->description_template) }}"
                                    required
                                >
                            </div>

                            <div class="col-12">
                                <label class="form-label" for="paynow-continue-url">Adres powrotu klienta</label>
                                <input
                                    id="paynow-continue-url"
                                    class="form-control @error('continue_url') is-invalid @enderror"
                                    name="continue_url"
                                    type="url"
                                    maxlength="2048"
                                    value="{{ old('continue_url', $account->continue_url) }}"
                                    placeholder="https://sklep.example/podziekowanie"
                                >
                            </div>

                            <div class="col-12">
                                <label class="form-label" for="paynow-notification-url">Adres powiadomień PayNow</label>
                                <div class="input-group">
                                    <input
                                        id="paynow-notification-url"
                                        class="form-control"
                                        type="text"
                                        value="{{ $notificationUrl }}"
                                        readonly
                                    >
                                    <button class="btn btn-outline-secondary" type="button" data-copy-paynow-notification-url title="Kopiuj adres">
                                        <i class="bi bi-clipboard" aria-hidden="true"></i>
                                        <span class="visually-hidden">Kopiuj adres powiadomień</span>
                                    </button>
                                </div>
                                <div class="form-text">Wpisz ten adres jako adres powiadomień w panelu PayNow. Na serwerze VPS musi prowadzić przez publiczny HTTPS.</div>
                            </div>

                            <div class="col-12">
                                <input name="is_active" type="hidden" value="0">
                                <div class="form-check form-switch">
                                    <input
                                        id="paynow-active"
                                        class="form-check-input @error('is_active') is-invalid @enderror"
                                        name="is_active"
                                        type="checkbox"
                                        value="1"
                                        @checked(old('is_active', $account->is_active))
                                        @disabled(! $canActivate && ! $account->is_active)
                                    >
                                    <label class="form-check-label" for="paynow-active">Integracja aktywna</label>
                                </div>
                                @if (! $canActivate && ! $account->is_active)
                                    <div class="form-text">Najpierw zapisz klucze i wykonaj poprawny test połączenia.</div>
                                @endif
                            </div>
                        </div>
                    </form>

                    <div class="paynow-actions">
                        <form method="POST" action="{{ route('integrations.paynow.test-connection') }}">
                            @csrf
                            <button class="btn btn-outline-primary" type="submit" @disabled(! $credentialsConfigured)>
                                <i class="bi bi-wifi" aria-hidden="true"></i>
                                Testuj połączenie
                            </button>
                        </form>

                        <button class="btn btn-primary" type="submit" form="paynow-settings-form">
                            Zapisz
                        </button>
                    </div>
                </div>
            </section>
        </div>
    </div>

    <script>
        document.querySelector('[data-copy-paynow-notification-url]')?.addEventListener('click', async () => {
            const input = document.getElementById('paynow-notification-url');
            if (!input) {
                return;
            }

            try {
                await navigator.clipboard.writeText(input.value);
            } catch (error) {
                input.select();
                document.execCommand('copy');
            }
        });
    </script>
@endsection
