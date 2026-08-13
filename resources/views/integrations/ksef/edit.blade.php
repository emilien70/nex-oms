@extends('layouts.app')

@section('title', 'KSeF - NEX-OMS')

@php
    $selectedEnvironment = old('environment', $settings->environment->value);
    $environmentNotices = collect($environmentOptions)
        ->mapWithKeys(fn ($environment) => [$environment->value => $environment->notice()])
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
        'include_sale_date' => 'Przekazuj datę sprzedaży',
    ];
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
                <button
                    class="ksef-tab"
                    type="button"
                    disabled
                    title="Funkcja będzie dostępna w kolejnym etapie."
                    data-ksef-tab="export"
                >Eksportuj dokumenty</button>
                <a
                    class="ksef-tab {{ $activeTab === 'series' ? 'is-active' : '' }}"
                    href="{{ route('integrations.ksef.edit', ['tab' => 'series']) }}"
                    data-ksef-tab="series"
                >Serie numeracji</a>
                <button
                    class="ksef-tab"
                    type="button"
                    disabled
                    title="Funkcja będzie dostępna w kolejnym etapie."
                    data-ksef-tab="payment-types"
                >Typy płatności</button>
            </nav>

            <div class="ksef-content">
                @if ($errors->any())
                    <div class="ksef-errors" role="alert">
                        {{ $errors->first() }}
                    </div>
                @endif

                @if ($activeTab === 'series')
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
                @else
                    <form class="ksef-form" method="POST" action="{{ route('integrations.ksef.update') }}" data-ksef-connection-form>
                        @csrf
                        @method('PUT')

                        <section class="ksef-section" aria-labelledby="ksef-connection-heading">
                            <h2 class="ksef-section-title" id="ksef-connection-heading">Połączenie z KSeF</h2>

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
                                            <option value="{{ $method->value }}" @selected(old('authentication_method', 'token') === $method->value)>{{ $method->label() }}</option>
                                        @endforeach
                                    </select>
                                    @error('authentication_method')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                </div>
                            </div>

                            <div class="ksef-field">
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
                                    >
                                    @error('api_token')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                    <div class="ksef-token-status" data-ksef-token-status aria-live="polite"></div>
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
                                    <button class="btn btn-outline-secondary btn-sm" type="button" disabled title="Test połączenia będzie dostępny po wdrożeniu komunikacji z API KSeF.">
                                        Przetestuj połączenie
                                    </button>
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
                                    </div>
                                </div>
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
            const environmentSelect = document.querySelector('[data-ksef-environment]');
            const notice = document.querySelector('[data-ksef-environment-notice]');
            const tokenStatus = document.querySelector('[data-ksef-token-status]');

            if (!environmentSelect || !notice || !tokenStatus) {
                return;
            }

            const notices = @json($environmentNotices);
            const configuredTokens = @json($tokenConfiguredByEnvironment);

            const refreshEnvironmentDetails = () => {
                const environment = environmentSelect.value;
                notice.textContent = notices[environment] || '';
                tokenStatus.textContent = configuredTokens[environment]
                    ? 'Token skonfigurowany dla wybranego środowiska.'
                    : 'Token nie został jeszcze skonfigurowany dla wybranego środowiska.';
            };

            environmentSelect.addEventListener('change', refreshEnvironmentDetails);
            refreshEnvironmentDetails();
        })();
    </script>
@endsection
