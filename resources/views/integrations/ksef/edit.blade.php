@extends('layouts.app')

@section('title', 'KSeF - NEX-OMS')

@php
    $selectedEnvironment = old('environment', $settings->environment->value);
    $persistedEnvironment = $settings->environment->value;
    $persistedAuthenticationMethod = $authenticationMethodByEnvironment[$persistedEnvironment] ?? 'token';
    $selectedAuthenticationMethod = old(
        'authentication_method',
        $authenticationMethodByEnvironment[$selectedEnvironment] ?? 'token',
    );
    $persistedTokenConfigured = $tokenConfiguredByEnvironment[$persistedEnvironment] ?? false;
    $persistedCertificateConfigured = $certificateConfiguredByEnvironment[$persistedEnvironment] ?? false;
    $persistedAuthenticationConfigured = $persistedAuthenticationMethod === 'certificate'
        ? $persistedCertificateConfigured
        : $persistedTokenConfigured;
    $environmentNotices = collect($environmentOptions)
        ->mapWithKeys(fn ($environment) => [$environment->value => $environment->notice()])
        ->all();
    $environmentLabels = collect($environmentOptions)
        ->mapWithKeys(fn ($environment) => [$environment->value => $environment->label()])
        ->all();
    $authenticationMethodLabels = collect($authenticationMethods)
        ->mapWithKeys(fn ($method) => [$method->value => $method->label()])
        ->all();
    $booleanOptions = [
        '0' => 'Nie',
        '1' => 'Tak',
    ];
    $configurationFields = [
        'automatic_submission' => 'Przekazuj faktury automatycznie',
        'send_without_buyer_nip' => 'Przekazuj dokumenty bez NIP nabywcy',
        'include_recipient_data' => 'Przekazuj dane odbiorcy',
        'include_buyer_contact_data' => 'Przekazuj dane kontaktowe',
        'include_additional_information' => 'Przekazuj informacje dodatkowe',
        'include_order_reference' => 'Przekazuj numer zamówienia',
        'include_bank_account' => 'Przekazuj rachunek bankowy',
        'include_gtu' => 'Przekazuj oznaczenia GTU',
        'include_seller_vat_prefix' => 'Dodaj prefiks VAT dla sprzedawcy',
    ];
    $oldPaymentMappings = collect(old('mappings', []))
        ->filter(fn ($mapping) => is_array($mapping)
            && is_string($mapping['source_kind'] ?? null)
            && is_string($mapping['source_key'] ?? null))
        ->mapWithKeys(fn ($mapping) => [
            $mapping['source_kind'].'|'.$mapping['source_key'] => $mapping['target_type'] ?? null,
        ]);
@endphp

@section('content')
    <style>
        .ksef-page {
            background: #f4f6f8;
            margin: -1.5rem;
            min-height: 100vh;
            padding: 24px;
        }

        .ksef-title {
            color: #111827;
            font-size: 20px;
            font-weight: 700;
            margin: 0 0 18px;
        }

        .ksef-panel {
            background: #fff;
            border: 1px solid #dfe4ea;
            border-radius: 8px;
            box-shadow: 0 1px 3px rgba(15, 23, 42, .06);
            overflow: hidden;
        }

        .ksef-tabs {
            align-items: stretch;
            border-bottom: 1px solid #dfe4ea;
            display: flex;
            gap: 0;
            overflow-x: auto;
            padding: 0 18px;
        }

        .ksef-tab {
            background: transparent;
            border: 0;
            border-bottom: 2px solid transparent;
            color: #596273;
            display: inline-flex;
            font-size: 13px;
            font-weight: 500;
            justify-content: center;
            padding: 15px 17px 13px;
            text-decoration: none;
            white-space: nowrap;
        }

        .ksef-tab:hover,
        .ksef-tab:focus-visible,
        .ksef-tab.is-active {
            border-bottom-color: #0d6efd;
            color: #0d6efd;
        }

        .ksef-tab:disabled {
            border-bottom-color: transparent;
            color: #a0a8b5;
            cursor: not-allowed;
        }

        .ksef-content {
            padding: 24px 32px 28px;
        }

        .ksef-form {
            max-width: 900px;
        }

        .ksef-section + .ksef-section {
            border-top: 1px solid #d8dee6;
            margin-top: 22px;
            padding-top: 22px;
        }

        .ksef-section-title {
            color: #374151;
            font-size: 12px;
            font-weight: 700;
            margin: 0 0 14px;
            text-transform: uppercase;
        }

        .ksef-field {
            align-items: start;
            column-gap: 18px;
            display: grid;
            grid-template-columns: 200px minmax(0, 1fr);
            padding: 6px 0;
        }

        .ksef-field > label {
            color: #4b5563;
            font-size: 13px;
            padding-top: 9px;
            text-align: right;
        }

        .ksef-control {
            min-width: 0;
        }

        .ksef-control .form-control,
        .ksef-control .form-select {
            font-size: 13px;
            min-height: 39px;
        }

        .ksef-help,
        .ksef-token-status {
            color: #6b7280;
            font-size: 12px;
            line-height: 1.45;
            margin-top: 5px;
        }

        .ksef-environment-notice {
            background: #f8fafc;
            border: 1px solid #dfe4ea;
            border-left: 3px solid #0d6efd;
            border-radius: 6px;
            color: #4b5563;
            font-size: 12px;
            line-height: 1.45;
            margin-top: 8px;
            padding: 10px 12px;
        }

        .ksef-connection-status {
            background: #f8fafc;
            border: 1px solid #dfe4ea;
            border-radius: 6px;
            color: #4b5563;
            font-size: 12px;
            line-height: 1.5;
            padding: 12px;
        }

        .ksef-connection-status[data-status="success"] {
            border-left: 3px solid #198754;
        }

        .ksef-connection-status[data-status="warning"] {
            border-left: 3px solid #f59e0b;
        }

        .ksef-connection-status[data-status="error"] {
            border-left: 3px solid #dc3545;
        }

        .ksef-connection-status strong {
            color: #374151;
        }

        .ksef-connection-status-line + .ksef-connection-status-line {
            margin-top: 4px;
        }

        .ksef-form-actions {
            display: flex;
            gap: 10px;
            justify-content: flex-end;
            margin-top: 20px;
        }

        .ksef-form-actions .btn {
            border-radius: 18px;
            font-size: 13px;
            min-width: 86px;
            padding: 7px 18px;
        }

        .ksef-errors {
            background: #fff4f2;
            border: 1px solid #f2c7c1;
            border-radius: 6px;
            color: #a1352a;
            font-size: 13px;
            margin-bottom: 18px;
            padding: 11px 14px;
        }

        .ksef-series-table {
            font-size: 13px;
            margin: 0;
        }

        .ksef-series-table th {
            background: #f8fafc;
            color: #4b5563;
            font-size: 11px;
            font-weight: 700;
            padding: 11px 14px;
            text-transform: uppercase;
        }

        .ksef-series-table td {
            border-color: #e2e8f0;
            color: #374151;
            padding: 12px 14px;
            vertical-align: middle;
        }

        .ksef-series-toggle {
            text-align: center;
            width: 180px;
        }

        .ksef-series-empty {
            color: #6b7280;
            font-size: 13px;
            padding: 24px 14px;
            text-align: center;
        }

        .ksef-payment-intro {
            color: #596273;
            font-size: 13px;
            line-height: 1.55;
            margin: 0 0 14px;
            max-width: 720px;
        }

        .ksef-payment-list {
            border-top: 1px solid #e2e8f0;
            margin-top: 14px;
            padding-top: 8px;
        }

        .ksef-payment-label {
            overflow-wrap: anywhere;
        }

        .ksef-export-row {
            align-items: end;
            display: grid;
            gap: 12px;
            grid-template-columns: minmax(220px, 360px) auto;
            max-width: 560px;
        }

        .ksef-export-row .btn {
            min-height: 38px;
        }

        @media (max-width: 720px) {
            .ksef-page {
                margin: -1rem;
                padding: 14px;
            }

            .ksef-content {
                padding: 20px 16px 24px;
            }

            .ksef-field {
                display: block;
            }

            .ksef-field > label {
                display: block;
                padding: 0 0 6px;
                text-align: left;
            }

            .ksef-export-row {
                align-items: stretch;
                grid-template-columns: 1fr;
            }
        }
    </style>

    <div class="ksef-page">
        <h1 class="ksef-title">KSeF</h1>

        <section class="ksef-panel" aria-label="Konfiguracja KSeF">
            <nav class="ksef-tabs" aria-label="Sekcje konfiguracji KSeF">
                <a
                    class="ksef-tab {{ $activeTab === 'connection' ? 'is-active' : '' }}"
                    href="{{ route('integrations.ksef.edit') }}"
                    data-ksef-tab="connection"
                >Połączenie</a>
                <a
                    class="ksef-tab {{ $activeTab === 'export' ? 'is-active' : '' }}"
                    href="{{ route('integrations.ksef.edit', ['tab' => 'export']) }}"
                    data-ksef-tab="export"
                >Eksportuj dokumenty</a>
                <a
                    class="ksef-tab {{ $activeTab === 'series' ? 'is-active' : '' }}"
                    href="{{ route('integrations.ksef.edit', ['tab' => 'series']) }}"
                    data-ksef-tab="series"
                >Serie numeracji</a>
                <a
                    class="ksef-tab {{ $activeTab === 'payment-types' ? 'is-active' : '' }}"
                    href="{{ route('integrations.ksef.edit', ['tab' => 'payment-types']) }}"
                    data-ksef-tab="payment-types"
                >Typy płatności</a>
            </nav>

            <div class="ksef-content">
                @if ($errors->any())
                    <div class="ksef-errors" role="alert">
                        {{ $errors->first() }}
                    </div>
                @endif

                @if ($activeTab === 'export')
                    <form
                        class="ksef-form"
                        method="POST"
                        action="{{ route('integrations.ksef.export') }}"
                        data-ksef-export-form
                        data-ksef-export-environment="{{ strtoupper($settings->environment->value) }}"
                        @if ($settings->environment === \Modules\Ksef\Enums\KsefEnvironment::Demo) data-ksef-export-demo @endif
                    >
                        @csrf

                        <section class="ksef-section" aria-labelledby="ksef-export-heading">
                            <h2 class="ksef-section-title" id="ksef-export-heading">Eksportuj dokumenty</h2>

                            @if ($settings->environment === \Modules\Ksef\Enums\KsefEnvironment::Demo)
                                <div class="ksef-environment-notice mb-3" role="note" data-ksef-export-demo-warning>
                                    Środowisko DEMO / przedprodukcyjne. Eksportuj wyłącznie dokumenty zawierające dane testowe lub fikcyjne.
                                </div>
                            @endif

                            <div class="ksef-export-row">
                                <div>
                                    <label class="form-label" for="ksef-export-month">Eksportuj faktury z miesiąca</label>
                                    <select class="form-select @error('month') is-invalid @enderror" id="ksef-export-month" name="month" required data-ksef-export-month>
                                        @foreach ($monthlyExportPeriods as $month => $label)
                                            <option value="{{ $month }}" @selected(old('month', array_key_first($monthlyExportPeriods)) === $month)>{{ $label }}</option>
                                        @endforeach
                                    </select>
                                    @error('month')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                </div>
                                <button class="btn btn-primary" type="submit" @disabled(! $monthlyExportGateEnabled || ! $settings->is_active || ! $monthlyExportEnvironmentAllowed)>Eksportuj</button>
                            </div>

                            @if (! $monthlyExportGateEnabled)
                                <p class="ksef-help mt-3">Wysyłka KSeF jest wyłączona na poziomie wdrożenia.</p>
                            @elseif (! $settings->is_active)
                                <p class="ksef-help mt-3">Integracja KSeF nie jest aktywna.</p>
                            @elseif (! $monthlyExportEnvironmentAllowed)
                                <p class="ksef-help mt-3">Operacyjny transport Faktur do środowiska produkcyjnego KSeF nie został jeszcze odblokowany.</p>
                            @endif
                        </section>
                    </form>
                @elseif ($activeTab === 'series')
                    <form method="POST" action="{{ route('integrations.ksef.series.update') }}" data-ksef-series-form>
                        @csrf
                        @method('PUT')

                        <section class="ksef-section" aria-labelledby="ksef-series-heading">
                            <h2 class="ksef-section-title" id="ksef-series-heading">Serie numeracji</h2>

                            @if ($series->isEmpty())
                                <div class="ksef-series-empty">Brak aktywnych serii Faktur VAT lub Korekt.</div>
                            @else
                                <div class="table-responsive">
                                    <table class="table ksef-series-table">
                                        <thead>
                                            <tr>
                                                <th scope="col">Seria</th>
                                                <th scope="col">Typ dokumentu</th>
                                                <th class="ksef-series-toggle" scope="col">Przekazuj do KSeF</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            @foreach ($series as $seriesRow)
                                                <tr data-ksef-series-id="{{ $seriesRow['id'] }}">
                                                    <td>{{ $seriesRow['name'] }}</td>
                                                    <td>{{ $seriesRow['document_type_label'] }}</td>
                                                    <td class="ksef-series-toggle">
                                                        <input
                                                            class="form-check-input"
                                                            type="checkbox"
                                                            name="series_ids[]"
                                                            value="{{ $seriesRow['id'] }}"
                                                            @checked($seriesRow['is_enabled'])
                                                            aria-label="Przekazuj serię {{ $seriesRow['name'] }} do KSeF"
                                                        >
                                                    </td>
                                                </tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                </div>
                            @endif
                        </section>

                        <div class="ksef-form-actions">
                            <button class="btn btn-primary" type="submit">Zapisz</button>
                        </div>
                    </form>
                @elseif ($activeTab === 'payment-types')
                    <form class="ksef-form" method="POST" action="{{ route('integrations.ksef.payment-types.update') }}" data-ksef-payment-types-form>
                        @csrf
                        @method('PUT')

                        <section class="ksef-section" aria-labelledby="ksef-payment-types-heading">
                            <h2 class="ksef-section-title" id="ksef-payment-types-heading">Typy płatności</h2>
                            <p class="ksef-payment-intro">
                                Mapuj formy płatności używane w zamówieniach NEX na formy płatności FA(3).
                                Rozwiązane mapowanie zostanie utrwalone na Fakturze VAT podczas jej wystawienia.
                            </p>

                            <div class="ksef-field">
                                <label for="ksef-default-payment-type">Domyślny typ płatności dla nieustawionych poniżej</label>
                                <div class="ksef-control">
                                    <select class="form-select @error('default_payment_type') is-invalid @enderror" id="ksef-default-payment-type" name="default_payment_type" required>
                                        @foreach ($paymentTypes as $paymentType)
                                            <option value="{{ $paymentType->value }}" @selected(old('default_payment_type', $settings->default_payment_type->value) === $paymentType->value)>{{ $paymentType->label() }}</option>
                                        @endforeach
                                    </select>
                                    @error('default_payment_type')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                </div>
                            </div>

                            <div class="ksef-payment-list">
                                @foreach ($paymentMethods as $index => $paymentMethod)
                                    @php
                                        $paymentSourceIdentity = $paymentMethod['source_kind'].'|'.$paymentMethod['source_key'];
                                        $selectedPaymentType = $oldPaymentMappings->has($paymentSourceIdentity)
                                            ? $oldPaymentMappings->get($paymentSourceIdentity)
                                            : $paymentMethod['target_type'];
                                    @endphp
                                    <div class="ksef-field" data-ksef-payment-source-kind="{{ $paymentMethod['source_kind'] }}" data-ksef-payment-source="{{ $paymentMethod['source_key'] }}">
                                        <label class="ksef-payment-label" for="ksef-payment-type-{{ $index }}">{{ $paymentMethod['source_label'] }}</label>
                                        <div class="ksef-control">
                                            <input type="hidden" name="mappings[{{ $index }}][source_kind]" value="{{ $paymentMethod['source_kind'] }}">
                                            <input type="hidden" name="mappings[{{ $index }}][source_key]" value="{{ $paymentMethod['source_key'] }}">
                                            <select class="form-select @error("mappings.{$index}.target_type") is-invalid @enderror" id="ksef-payment-type-{{ $index }}" name="mappings[{{ $index }}][target_type]">
                                                <option value="" @selected($selectedPaymentType === null)>--- użyj domyślnego ---</option>
                                                @foreach ($paymentTypes as $paymentType)
                                                    <option value="{{ $paymentType->value }}" @selected($selectedPaymentType === $paymentType->value)>{{ $paymentType->label() }}</option>
                                                @endforeach
                                            </select>
                                            @error("mappings.{$index}.target_type")<div class="invalid-feedback">{{ $message }}</div>@enderror
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        </section>

                        <div class="ksef-form-actions">
                            <button class="btn btn-primary" type="submit">Zapisz</button>
                        </div>
                    </form>
                @else
                    <form id="ksef-test-connection-form" method="POST" action="{{ route('integrations.ksef.test-connection') }}" data-ksef-test-form>
                        @csrf
                    </form>

                    <form class="ksef-form" method="POST" action="{{ route('integrations.ksef.update') }}" enctype="multipart/form-data" data-ksef-connection-form>
                        @csrf
                        @method('PUT')

                        <section class="ksef-section" aria-labelledby="ksef-connection-heading">
                            <h2 class="ksef-section-title" id="ksef-connection-heading">Połączenie z KSeF</h2>

                            <div class="ksef-field">
                                <label for="ksef-is-active">Integracja KSeF aktywna</label>
                                <div class="ksef-control">
                                    <select class="form-select @error('is_active') is-invalid @enderror" id="ksef-is-active" name="is_active" required>
                                        @foreach ($booleanOptions as $value => $optionLabel)
                                            <option value="{{ $value }}" @selected((string) old('is_active', $settings->is_active ? '1' : '0') === (string) $value)>{{ $optionLabel }}</option>
                                        @endforeach
                                    </select>
                                    @error('is_active')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                </div>
                            </div>

                            <div class="ksef-field">
                                <label for="ksef-name">Nazwa integracji</label>
                                <div class="ksef-control">
                                    <input class="form-control @error('name') is-invalid @enderror" id="ksef-name" name="name" type="text" maxlength="120" value="{{ old('name', $settings->name) }}" required>
                                    @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                </div>
                            </div>

                            <div class="ksef-field">
                                <label for="ksef-authentication-method">Metoda uwierzytelnienia</label>
                                <div class="ksef-control">
                                    <select class="form-select @error('authentication_method') is-invalid @enderror" id="ksef-authentication-method" name="authentication_method" required>
                                        @foreach ($authenticationMethods as $method)
                                            <option value="{{ $method->value }}" @selected($selectedAuthenticationMethod === $method->value)>{{ $method->label() }}</option>
                                        @endforeach
                                    </select>
                                    @error('authentication_method')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                </div>
                            </div>

                            <div class="ksef-field" data-ksef-token-section @if ($selectedAuthenticationMethod !== 'token') hidden @endif>
                                <label for="ksef-api-token">Token KSeF</label>
                                <div class="ksef-control">
                                    <input
                                        class="form-control @error('api_token') is-invalid @enderror"
                                        id="ksef-api-token"
                                        name="api_token"
                                        type="password"
                                        maxlength="4096"
                                        autocomplete="new-password"
                                        placeholder="Wartość ukryta — wprowadź nową, aby zmienić"
                                        data-ksef-api-token
                                    >
                                    <input
                                        name="api_token_environment"
                                        type="hidden"
                                        value="{{ $selectedEnvironment }}"
                                        data-ksef-token-environment
                                    >
                                    @error('api_token')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                    @error('api_token_environment')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                                    <div class="ksef-token-status" data-ksef-token-status aria-live="polite"></div>
                                </div>
                            </div>

                            <div class="ksef-field" data-ksef-certificate-section @if ($selectedAuthenticationMethod !== 'certificate') hidden @endif>
                                <label for="ksef-authentication-certificate">Certyfikat</label>
                                <div class="ksef-control">
                                    <input
                                        class="form-control @error('authentication_certificate') is-invalid @enderror"
                                        id="ksef-authentication-certificate"
                                        name="authentication_certificate"
                                        type="file"
                                        accept=".pem,.crt,.cer"
                                        data-ksef-certificate-file
                                    >
                                    @error('authentication_certificate')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                </div>
                            </div>

                            <div class="ksef-field" data-ksef-certificate-section @if ($selectedAuthenticationMethod !== 'certificate') hidden @endif>
                                <label for="ksef-authentication-private-key">Klucz prywatny</label>
                                <div class="ksef-control">
                                    <input
                                        class="form-control @error('authentication_private_key') is-invalid @enderror"
                                        id="ksef-authentication-private-key"
                                        name="authentication_private_key"
                                        type="file"
                                        accept=".pem,.key"
                                        data-ksef-private-key-file
                                    >
                                    @error('authentication_private_key')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                </div>
                            </div>

                            <div class="ksef-field" data-ksef-certificate-section @if ($selectedAuthenticationMethod !== 'certificate') hidden @endif>
                                <label for="ksef-authentication-private-key-passphrase">Hasło klucza prywatnego</label>
                                <div class="ksef-control">
                                    <input
                                        class="form-control @error('authentication_private_key_passphrase') is-invalid @enderror"
                                        id="ksef-authentication-private-key-passphrase"
                                        name="authentication_private_key_passphrase"
                                        type="password"
                                        maxlength="1024"
                                        autocomplete="new-password"
                                        data-ksef-private-key-passphrase
                                    >
                                    @error('authentication_private_key_passphrase')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                </div>
                            </div>

                            <div class="ksef-field" data-ksef-certificate-section @if ($selectedAuthenticationMethod !== 'certificate') hidden @endif>
                                <span></span>
                                <div class="ksef-control">
                                    <div class="ksef-token-status" data-ksef-certificate-status aria-live="polite"></div>
                                    <div class="ksef-help" data-ksef-certificate-metadata hidden>
                                        <div><strong>Ważny od:</strong> <span data-ksef-certificate-valid-from></span></div>
                                        <div><strong>Ważny do:</strong> <span data-ksef-certificate-valid-until></span></div>
                                        <div><strong>Klucz:</strong> <span data-ksef-certificate-key></span></div>
                                        <div><strong>Fingerprint SHA-256:</strong> <span data-ksef-certificate-fingerprint></span></div>
                                    </div>
                                </div>
                            </div>

                            <div class="ksef-field">
                                <label for="ksef-context-nip">NIP</label>
                                <div class="ksef-control">
                                    <input class="form-control @error('context_nip') is-invalid @enderror" id="ksef-context-nip" name="context_nip" type="text" inputmode="numeric" maxlength="20" value="{{ old('context_nip', $settings->context_nip) }}" required>
                                    <div class="ksef-help">NIP kontekstu używanego przez konfigurację KSeF.</div>
                                    @error('context_nip')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                </div>
                            </div>

                            <div class="ksef-field">
                                <label for="ksef-environment">Środowisko</label>
                                <div class="ksef-control">
                                    <select class="form-select @error('environment') is-invalid @enderror" id="ksef-environment" name="environment" required data-ksef-environment>
                                        @foreach ($environmentOptions as $environment)
                                            <option value="{{ $environment->value }}" @selected($selectedEnvironment === $environment->value)>{{ $environment->label() }}</option>
                                        @endforeach
                                    </select>
                                    @error('environment')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                    <div class="ksef-environment-notice" data-ksef-environment-notice aria-live="polite"></div>
                                </div>
                            </div>

                            <div class="ksef-field">
                                <span></span>
                                <div class="ksef-control">
                                    <button
                                        class="btn btn-outline-secondary btn-sm"
                                        type="submit"
                                        form="ksef-test-connection-form"
                                        data-ksef-test-button
                                        @disabled(! $persistedAuthenticationConfigured)
                                    >
                                        Przetestuj połączenie
                                    </button>
                                    <div class="ksef-help" data-ksef-test-help>
                                        {{ $persistedAuthenticationConfigured
                                            ? 'Test połączenia używa zapisanej konfiguracji. Zapisz zmiany przed testem połączenia.'
                                            : ($persistedAuthenticationMethod === 'certificate'
                                                ? 'Najpierw zapisz certyfikat KSeF i klucz prywatny.'
                                                : 'Najpierw zapisz Token KSeF.') }}
                                    </div>
                                </div>
                            </div>

                            <div class="ksef-field">
                                <span></span>
                                <div class="ksef-connection-status" data-ksef-connection-status data-status="">
                                    <div class="ksef-connection-status-line" data-ksef-test-message>Połączenie nie było jeszcze testowane.</div>
                                    <div class="ksef-connection-status-line">
                                        <strong>Środowisko:</strong>
                                        <span data-ksef-status-environment></span>
                                    </div>
                                    <div class="ksef-connection-status-line">
                                        <strong>NIP:</strong>
                                        <span data-ksef-status-nip></span>
                                    </div>
                                    <div class="ksef-connection-status-line">
                                        <strong>Uwierzytelnienie:</strong>
                                        <span data-ksef-status-authentication-method></span>
                                    </div>
                                    <div class="ksef-connection-status-line" data-ksef-tested-at-row hidden>
                                        <strong>Ostatni test:</strong>
                                        <span data-ksef-tested-at></span>
                                    </div>
                                    <div class="ksef-connection-status-line" data-ksef-system-warning-row hidden>
                                        <strong>Ostrzeżenie KSeF:</strong>
                                        <span data-ksef-system-warning></span>
                                    </div>
                                </div>
                            </div>

                            <div class="ksef-field">
                                <span></span>
                                <div class="ksef-environment-notice" role="note">
                                    KSeF będzie obsługiwany bezpośrednio przez NEX-OMS. Nie konfiguruj równoległego automatycznego przekazywania tych samych Faktur do KSeF w innym systemie księgowym lub ERP.
                                </div>
                            </div>
                        </section>

                        <section class="ksef-section" aria-labelledby="ksef-submission-heading">
                            <h2 class="ksef-section-title" id="ksef-submission-heading">Przekazywanie dokumentów</h2>

                            @foreach ($configurationFields as $field => $label)
                                <div class="ksef-field">
                                    <label for="ksef-{{ str_replace('_', '-', $field) }}">{{ $label }}</label>
                                    <div class="ksef-control">
                                        <select class="form-select @error($field) is-invalid @enderror" id="ksef-{{ str_replace('_', '-', $field) }}" name="{{ $field }}" required>
                                            @foreach ($booleanOptions as $value => $optionLabel)
                                                <option value="{{ $value }}" @selected((string) old($field, $settings->{$field} ? '1' : '0') === (string) $value)>{{ $optionLabel }}</option>
                                            @endforeach
                                        </select>
                                        @error($field)<div class="invalid-feedback">{{ $message }}</div>@enderror
                                        @if ($field === 'automatic_submission')
                                            <div class="ksef-help">Automatyczna transmisja nie jest jeszcze uruchomiona. Obecny workflow wysyłki Faktur jest ręczny.</div>
                                        @endif
                                    </div>
                                </div>

                                @if ($field === 'include_additional_information')
                                    <div class="ksef-field">
                                        <label for="ksef-zero-vat-classification">Traktuj stawkę VAT 0% jako</label>
                                        <div class="ksef-control">
                                            <select class="form-select @error('zero_vat_classification') is-invalid @enderror" id="ksef-zero-vat-classification" name="zero_vat_classification" required>
                                                @foreach ($zeroVatClassifications as $classification)
                                                    <option value="{{ $classification->value }}" @selected(old('zero_vat_classification', $settings->zero_vat_classification->value) === $classification->value)>{{ $classification->label() }}</option>
                                                @endforeach
                                            </select>
                                            @error('zero_vat_classification')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                        </div>
                                    </div>

                                    <div class="ksef-field">
                                        <label for="ksef-default-split-payment">MPP – Mechanizm podzielonej płatności</label>
                                        <div class="ksef-control">
                                            <select class="form-select @error('default_split_payment') is-invalid @enderror" id="ksef-default-split-payment" name="default_split_payment" required>
                                                @foreach ($booleanOptions as $value => $optionLabel)
                                                    <option value="{{ $value }}" @selected((string) old('default_split_payment', $settings->default_split_payment ? '1' : '0') === (string) $value)>{{ $optionLabel }}</option>
                                                @endforeach
                                            </select>
                                            @error('default_split_payment')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                            <div class="ksef-help">Domyślna wartość dla nowych Faktur VAT, zapisywana w snapshotcie dokumentu.</div>
                                        </div>
                                    </div>
                                @endif
                            @endforeach
                        </section>

                        <div class="ksef-form-actions">
                            <button class="btn btn-primary" type="submit">Zapisz</button>
                        </div>
                    </form>
                @endif
            </div>
        </section>
    </div>

    <script>
        (() => {
            const exportForm = document.querySelector('[data-ksef-export-form]');
            const exportMonth = document.querySelector('[data-ksef-export-month]');

            if (exportForm && exportMonth) {
                exportForm.addEventListener('submit', (event) => {
                    const monthLabel = exportMonth.options[exportMonth.selectedIndex]?.text || exportMonth.value;
                    const environment = exportForm.dataset.ksefExportEnvironment || '';
                    let message = `Wyeksportować niewysłane Faktury z ${monthLabel} do KSeF ${environment}?`;

                    if (exportForm.hasAttribute('data-ksef-export-demo')) {
                        message += ' Upewnij się, że dokumenty zawierają wyłącznie dane testowe lub fikcyjne.';
                    }

                    if (!window.confirm(message)) {
                        event.preventDefault();
                    }
                });
            }

            const environmentSelect = document.querySelector('[data-ksef-environment]');
            const notice = document.querySelector('[data-ksef-environment-notice]');
            const tokenInput = document.querySelector('[data-ksef-api-token]');
            const tokenEnvironmentInput = document.querySelector('[data-ksef-token-environment]');
            const tokenStatus = document.querySelector('[data-ksef-token-status]');
            const tokenSections = document.querySelectorAll('[data-ksef-token-section]');
            const certificateSections = document.querySelectorAll('[data-ksef-certificate-section]');
            const certificateInput = document.querySelector('[data-ksef-certificate-file]');
            const privateKeyInput = document.querySelector('[data-ksef-private-key-file]');
            const privateKeyPassphraseInput = document.querySelector('[data-ksef-private-key-passphrase]');
            const certificateStatus = document.querySelector('[data-ksef-certificate-status]');
            const certificateMetadataPanel = document.querySelector('[data-ksef-certificate-metadata]');
            const certificateValidFrom = document.querySelector('[data-ksef-certificate-valid-from]');
            const certificateValidUntil = document.querySelector('[data-ksef-certificate-valid-until]');
            const certificateKey = document.querySelector('[data-ksef-certificate-key]');
            const certificateFingerprint = document.querySelector('[data-ksef-certificate-fingerprint]');
            const contextNipInput = document.querySelector('#ksef-context-nip');
            const authenticationMethodSelect = document.querySelector('#ksef-authentication-method');
            const testButton = document.querySelector('[data-ksef-test-button]');
            const testHelp = document.querySelector('[data-ksef-test-help]');
            const connectionStatus = document.querySelector('[data-ksef-connection-status]');
            const testMessage = document.querySelector('[data-ksef-test-message]');
            const statusEnvironment = document.querySelector('[data-ksef-status-environment]');
            const statusNip = document.querySelector('[data-ksef-status-nip]');
            const statusAuthenticationMethod = document.querySelector('[data-ksef-status-authentication-method]');
            const testedAtRow = document.querySelector('[data-ksef-tested-at-row]');
            const testedAt = document.querySelector('[data-ksef-tested-at]');
            const systemWarningRow = document.querySelector('[data-ksef-system-warning-row]');
            const systemWarning = document.querySelector('[data-ksef-system-warning]');

            if (!environmentSelect || !notice || !tokenInput || !tokenEnvironmentInput || !tokenStatus
                || !certificateInput || !privateKeyInput || !privateKeyPassphraseInput
                || !certificateStatus || !certificateMetadataPanel || !certificateValidFrom
                || !certificateValidUntil || !certificateKey || !certificateFingerprint
                || !contextNipInput || !authenticationMethodSelect || !testButton || !testHelp
                || !connectionStatus || !testMessage || !statusEnvironment || !statusNip || !statusAuthenticationMethod
                || !testedAtRow || !testedAt || !systemWarningRow || !systemWarning) {
                return;
            }

            const notices = @json($environmentNotices);
            const environmentLabels = @json($environmentLabels);
            const authenticationMethodLabels = @json($authenticationMethodLabels);
            const configuredTokens = @json($tokenConfiguredByEnvironment);
            const configuredCertificates = @json($certificateConfiguredByEnvironment);
            const certificateMetadata = @json($certificateMetadataByEnvironment);
            const authenticationMethods = @json($authenticationMethodByEnvironment);
            const connectionStatuses = @json($connectionStatusByEnvironment);
            const persistedEnvironment = @json($persistedEnvironment);
            const persistedContextNip = @json((string) $settings->context_nip);
            const persistedAuthenticationMethod = @json($persistedAuthenticationMethod);

            const refreshConnectionStatus = () => {
                const status = connectionStatuses[environmentSelect.value] || {};
                connectionStatus.dataset.status = status.status || '';
                testMessage.textContent = status.message || 'Połączenie nie było jeszcze testowane.';
                statusEnvironment.textContent = environmentLabels[environmentSelect.value] || '';
                statusNip.textContent = persistedContextNip || 'Nie skonfigurowano';
                statusAuthenticationMethod.textContent = authenticationMethodLabels[authenticationMethods[environmentSelect.value]] || '';

                testedAtRow.hidden = !status.tested_at;
                testedAt.textContent = status.tested_at || '';

                systemWarningRow.hidden = !status.system_warning;
                systemWarning.textContent = status.system_warning || '';
            };

            const refreshAuthenticationFields = () => {
                const certificateSelected = authenticationMethodSelect.value === 'certificate';

                tokenSections.forEach((section) => {
                    section.hidden = certificateSelected;
                });
                certificateSections.forEach((section) => {
                    section.hidden = !certificateSelected;
                });
            };

            const refreshTestAvailability = () => {
                const hasUnsavedAuthenticationChanges = environmentSelect.value !== persistedEnvironment
                    || contextNipInput.value !== persistedContextNip
                    || authenticationMethodSelect.value !== persistedAuthenticationMethod
                    || tokenInput.value !== ''
                    || certificateInput.files.length > 0
                    || privateKeyInput.files.length > 0
                    || privateKeyPassphraseInput.value !== '';
                const persistedTokenConfigured = configuredTokens[persistedEnvironment] === true;
                const persistedCertificateConfigured = configuredCertificates[persistedEnvironment] === true;
                const persistedAuthenticationConfigured = persistedAuthenticationMethod === 'certificate'
                    ? persistedCertificateConfigured
                    : persistedTokenConfigured;

                testButton.disabled = hasUnsavedAuthenticationChanges
                    || !persistedAuthenticationConfigured;

                if (hasUnsavedAuthenticationChanges) {
                    testHelp.textContent = 'Zapisz zmiany przed testem połączenia.';
                } else if (!persistedAuthenticationConfigured) {
                    testHelp.textContent = persistedAuthenticationMethod === 'certificate'
                        ? 'Najpierw zapisz certyfikat KSeF i klucz prywatny.'
                        : 'Najpierw zapisz Token KSeF.';
                } else {
                    testHelp.textContent = 'Test połączenia używa zapisanej konfiguracji.';
                }
            };

            const refreshEnvironmentDetails = () => {
                const environment = environmentSelect.value;
                notice.textContent = notices[environment] || '';
                tokenStatus.textContent = configuredTokens[environment]
                    ? 'Token skonfigurowany dla wybranego środowiska.'
                    : 'Token nie został jeszcze skonfigurowany dla wybranego środowiska.';
                certificateStatus.textContent = configuredCertificates[environment]
                    ? 'Certyfikat skonfigurowany dla wybranego środowiska.'
                    : 'Certyfikat nie został jeszcze skonfigurowany dla wybranego środowiska.';

                const metadata = certificateMetadata[environment] || null;
                certificateMetadataPanel.hidden = !metadata;
                certificateValidFrom.textContent = metadata?.valid_from || '';
                certificateValidUntil.textContent = metadata?.valid_until || '';
                certificateKey.textContent = metadata?.key_label || '';
                certificateFingerprint.textContent = metadata?.fingerprint_sha256 || '';
                refreshAuthenticationFields();
                refreshConnectionStatus();
                refreshTestAvailability();
            };

            const changeEnvironment = () => {
                tokenInput.value = '';
                certificateInput.value = '';
                privateKeyInput.value = '';
                privateKeyPassphraseInput.value = '';
                tokenEnvironmentInput.value = environmentSelect.value;
                authenticationMethodSelect.value = authenticationMethods[environmentSelect.value] || 'token';
                refreshEnvironmentDetails();
            };

            environmentSelect.addEventListener('change', changeEnvironment);
            contextNipInput.addEventListener('input', refreshTestAvailability);
            authenticationMethodSelect.addEventListener('change', () => {
                refreshAuthenticationFields();
                refreshTestAvailability();
            });
            tokenInput.addEventListener('input', refreshTestAvailability);
            certificateInput.addEventListener('change', refreshTestAvailability);
            privateKeyInput.addEventListener('change', refreshTestAvailability);
            privateKeyPassphraseInput.addEventListener('input', refreshTestAvailability);
            tokenEnvironmentInput.value = environmentSelect.value;
            refreshEnvironmentDetails();
        })();
    </script>
@endsection
