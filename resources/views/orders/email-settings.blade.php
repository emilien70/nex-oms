@extends('layouts.app')

@section('title', ($activeTab === 'accounts' ? 'Konta E-Mail' : 'Szablony E-Mail').' - NEX-OMS')

@section('content')
    <style>
        .email-settings-page {
            background: #f4f6f8;
            margin: -1.5rem;
            min-height: calc(100vh - 58px);
        }

        .email-settings-tabs {
            align-items: flex-end;
            background: #ffffff;
            border-bottom: 1px solid #dfe4ea;
            display: flex;
            gap: 28px;
            min-height: 47px;
            overflow-x: auto;
            padding: 0 18px;
        }

        .email-settings-tab {
            border-bottom: 2px solid transparent;
            color: #526174;
            display: inline-flex;
            font-size: 13px;
            padding: 14px 8px 11px;
            text-decoration: none;
            white-space: nowrap;
        }

        .email-settings-tab:hover {
            color: #0d6efd;
        }

        .email-settings-tab.active {
            border-bottom-color: #0d6efd;
            color: #1f2937;
        }

        .email-accounts-content {
            padding: 34px 18px 24px;
        }

        .email-accounts-notice {
            align-items: flex-start;
            background: #f8fafc;
            border: 1px solid #d6dce3;
            border-left: 3px solid #0d6efd;
            border-radius: 7px;
            color: #4b5563;
            display: flex;
            font-size: 12px;
            gap: 14px;
            line-height: 1.55;
            margin-bottom: 20px;
            padding: 14px 16px;
        }

        .email-accounts-notice i {
            color: #526174;
            flex: 0 0 auto;
            font-size: 15px;
            margin-top: 1px;
        }

        .email-accounts-toolbar {
            display: flex;
            justify-content: flex-end;
            margin-bottom: 18px;
        }

        .email-account-add {
            align-items: center;
            border-radius: 22px;
            display: inline-flex;
            font-size: 13px;
            font-weight: 600;
            gap: 8px;
            padding: 7px 15px 7px 8px;
        }

        .email-account-add-icon {
            align-items: center;
            border: 1px solid currentColor;
            border-radius: 50%;
            display: inline-flex;
            font-size: 18px;
            height: 29px;
            justify-content: center;
            line-height: 1;
            width: 29px;
        }

        .email-accounts-table-wrap {
            background: #ffffff;
            border: 1px solid #dfe4ea;
            border-radius: 6px;
            box-shadow: 0 1px 4px rgba(15, 23, 42, .08);
            overflow: hidden;
        }

        .email-accounts-table {
            font-size: 12px;
            margin: 0;
            table-layout: fixed;
            width: 100%;
        }

        .email-accounts-table th {
            background: #ffffff;
            border-bottom: 1px solid #d7dde4;
            color: #111827;
            font-size: 10px;
            font-weight: 700;
            padding: 13px 12px;
            text-transform: uppercase;
        }

        .email-accounts-table td {
            border-bottom: 1px solid #e5e9ee;
            color: #526174;
            padding: 7px 12px;
            vertical-align: middle;
        }

        .email-accounts-table tbody tr:last-child td {
            border-bottom: 0;
        }

        .email-account-address {
            color: #3f5875;
            overflow-wrap: anywhere;
        }

        .email-account-actions {
            align-items: center;
            display: flex;
            gap: 8px;
            justify-content: flex-end;
        }

        .email-account-test {
            align-items: center;
            display: inline-flex;
            font-size: 10px;
            gap: 5px;
            white-space: nowrap;
        }

        .email-account-icon-button {
            align-items: center;
            background: #ffffff;
            border: 1px solid #edf0f3;
            border-radius: 50%;
            color: #31506d;
            display: inline-flex;
            height: 30px;
            justify-content: center;
            padding: 0;
            width: 30px;
        }

        .email-account-icon-button:hover {
            background: #f3f7fa;
            border-color: #d8e0e7;
            color: #0d6efd;
        }

        .email-account-test-state {
            font-size: 10px;
            margin-top: 3px;
        }

        .email-account-test-state.success { color: #198754; }
        .email-account-test-state.error { color: #b42318; }

        .email-accounts-footer {
            color: #6b7280;
            font-size: 12px;
            padding: 12px;
        }

        .email-accounts-empty {
            color: #6b7280;
            padding: 42px 20px;
            text-align: center;
        }

        .email-account-modal .modal-content {
            border: 0;
            border-radius: 8px;
        }

        .email-account-modal .modal-header,
        .email-account-modal .modal-footer {
            border: 0;
            padding: 20px 30px;
        }

        .email-account-modal .modal-body {
            padding: 10px 30px 24px;
        }

        .email-account-modal .form-label {
            color: #526174;
            font-size: 12px;
            margin-bottom: 0;
        }

        .email-account-modal .form-text {
            color: #6b7280;
            font-size: 11px;
        }

        .email-account-modal .form-control,
        .email-account-modal .form-select {
            font-size: 13px;
            min-height: 39px;
        }

        @media (max-width: 767.98px) {
            .email-settings-page {
                margin: -1rem;
            }

            .email-settings-tabs {
                gap: 18px;
                padding: 0 10px;
            }

            .email-accounts-content {
                padding: 20px 10px;
            }

            .email-accounts-table {
                min-width: 760px;
            }

            .email-accounts-table-wrap {
                overflow-x: auto;
            }

            .email-account-modal .modal-header,
            .email-account-modal .modal-footer {
                padding-left: 18px;
                padding-right: 18px;
            }

            .email-account-modal .modal-body {
                padding-left: 18px;
                padding-right: 18px;
            }
        }
    </style>

    <div class="email-settings-page">
        <h1 class="visually-hidden">{{ $activeTab === 'accounts' ? 'Konta E-Mail' : 'Szablony E-Mail' }}</h1>

        <nav class="email-settings-tabs" aria-label="Ustawienia wiadomości e-mail">
            <a
                class="email-settings-tab {{ $activeTab === 'templates' ? 'active' : '' }}"
                href="{{ route('orders.email-templates.index') }}"
                @if ($activeTab === 'templates') aria-current="page" @endif
            >Szablony E-Mail</a>
            <a
                class="email-settings-tab {{ $activeTab === 'accounts' ? 'active' : '' }}"
                href="{{ route('orders.email-accounts.index') }}"
                @if ($activeTab === 'accounts') aria-current="page" @endif
            >Konta E-Mail</a>
        </nav>

        @if ($activeTab === 'accounts')
            <div class="email-accounts-content">
                @if (session('error'))
                    <div class="alert alert-danger py-2 small" role="alert">{{ session('error') }}</div>
                @endif

                <div class="email-accounts-notice" role="note">
                    <i class="bi bi-info-circle" aria-hidden="true"></i>
                    <div>
                        Wiadomości e-mail do klientów będą wysyłane bezpośrednio z zapisanej skrzynki SMTP.
                        Odpowiedzi klientów trafią na adres tej skrzynki. Do połączenia potrzebne są adres serwera,
                        port, login i hasło SMTP. Po zapisaniu konta użyj opcji „Przetestuj połączenie”.
                    </div>
                </div>

                <div class="email-accounts-toolbar">
                    <button class="btn btn-success email-account-add" type="button" data-email-account-create>
                        <span class="email-account-add-icon" aria-hidden="true">+</span>
                        Dodaj nowe konto
                    </button>
                </div>

                <div class="email-accounts-table-wrap">
                    @if ($accounts->isEmpty())
                        <div class="email-accounts-empty">Nie dodano jeszcze żadnego konta SMTP.</div>
                    @else
                        <table class="table email-accounts-table">
                            <colgroup>
                                <col style="width: 32%">
                                <col style="width: 28%">
                                <col style="width: 40%">
                            </colgroup>
                            <thead>
                                <tr>
                                    <th scope="col">E-mail</th>
                                    <th scope="col">Nazwa</th>
                                    <th class="text-end" scope="col">Operacje</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($accounts as $account)
                                    <tr>
                                        <td>
                                            <div class="email-account-address">{{ $account->email }}</div>
                                            @if (! $account->hasConfiguredPassword())
                                                <div class="email-account-test-state error">Wymaga uzupełnienia hasła SMTP</div>
                                            @endif
                                        </td>
                                        <td>{{ $account->name }}</td>
                                        <td>
                                            <div class="email-account-actions">
                                                <div class="text-end">
                                                    <form method="POST" action="{{ route('orders.email-accounts.test', $account) }}">
                                                        @csrf
                                                        <button
                                                            class="btn btn-sm btn-outline-secondary email-account-test"
                                                            type="submit"
                                                            @disabled(! $account->hasConfiguredPassword())
                                                        >
                                                            <i class="bi bi-question-circle" aria-hidden="true"></i>
                                                            Przetestuj połączenie
                                                        </button>
                                                    </form>
                                                    @if ($account->last_test_status !== null)
                                                        <div
                                                            class="email-account-test-state {{ $account->last_test_status->value }}"
                                                            title="{{ $account->last_test_message }}"
                                                        >
                                                            {{ $account->last_test_status === \App\Enums\EmailAccountTestStatus::Success ? 'Połączenie działa' : 'Błąd połączenia' }}
                                                            @if ($account->last_tested_at !== null)
                                                                · {{ $account->last_tested_at->format('d.m.Y H:i') }}
                                                            @endif
                                                        </div>
                                                    @endif
                                                </div>

                                                <button
                                                    class="email-account-icon-button"
                                                    type="button"
                                                    data-email-account-edit="{{ $account->getKey() }}"
                                                    title="Edytuj konto"
                                                    aria-label="Edytuj konto {{ $account->email }}"
                                                ><i class="bi bi-pencil" aria-hidden="true"></i></button>

                                                <form method="POST" action="{{ route('orders.email-accounts.duplicate', $account) }}">
                                                    @csrf
                                                    <button
                                                        class="email-account-icon-button"
                                                        type="submit"
                                                        title="Kopiuj konto"
                                                        aria-label="Kopiuj konto {{ $account->email }}"
                                                    ><i class="bi bi-copy" aria-hidden="true"></i></button>
                                                </form>

                                                <form
                                                    method="POST"
                                                    action="{{ route('orders.email-accounts.destroy', $account) }}"
                                                    onsubmit="return confirm('Usunąć to konto e-mail?')"
                                                >
                                                    @csrf
                                                    @method('DELETE')
                                                    <button
                                                        class="email-account-icon-button"
                                                        type="submit"
                                                        title="Usuń konto"
                                                        aria-label="Usuń konto {{ $account->email }}"
                                                    ><i class="bi bi-trash3" aria-hidden="true"></i></button>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                        <div class="email-accounts-footer">
                            {{ $accounts->count() }} {{ $accounts->count() === 1 ? 'pozycja' : 'pozycji' }}
                        </div>
                    @endif
                </div>
            </div>

            <div class="modal fade email-account-modal" id="emailAccountModal" tabindex="-1" aria-labelledby="emailAccountModalTitle" aria-hidden="true">
                <div class="modal-dialog modal-lg modal-dialog-centered">
                    <div class="modal-content">
                        <form
                            method="POST"
                            action="{{ route('orders.email-accounts.store') }}"
                            data-email-account-form
                            data-store-action="{{ route('orders.email-accounts.store') }}"
                            data-update-action="{{ url('/orders/email-accounts/__ACCOUNT__') }}"
                        >
                            @csrf
                            <input type="hidden" name="_method" value="PATCH" data-email-account-method disabled>
                            <input type="hidden" name="email_account_id" value="" data-email-account-id>

                            <div class="modal-header">
                                <h2 class="modal-title fs-5" id="emailAccountModalTitle">Konto e-mail</h2>
                                <button class="btn-close" type="button" data-bs-dismiss="modal" aria-label="Zamknij"></button>
                            </div>
                            <div class="modal-body">
                                <div class="row align-items-start mb-3">
                                    <label class="col-sm-3 col-form-label text-sm-end" for="smtpHost">Adres serwera SMTP</label>
                                    <div class="col-sm-7">
                                        <input class="form-control @error('smtp_host') is-invalid @enderror" id="smtpHost" name="smtp_host" required maxlength="255" autocomplete="off">
                                        @error('smtp_host')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                        <div class="form-text">Np. smtp.poczta.pl. Adres wpisz bez protokołu i numeru portu.</div>
                                    </div>
                                    <div class="col-sm-2">
                                        <label class="visually-hidden" for="smtpPort">Port SMTP</label>
                                        <input class="form-control @error('smtp_port') is-invalid @enderror" id="smtpPort" name="smtp_port" type="number" min="1" max="65535" required placeholder="587">
                                        @error('smtp_port')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                    </div>
                                </div>

                                <div class="row align-items-start mb-3">
                                    <label class="col-sm-3 col-form-label text-sm-end" for="smtpUsername">Login SMTP</label>
                                    <div class="col-sm-9">
                                        <input class="form-control @error('smtp_username') is-invalid @enderror" id="smtpUsername" name="smtp_username" required maxlength="255" autocomplete="username">
                                        @error('smtp_username')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                        <div class="form-text">Login do skrzynki pocztowej, najczęściej pełny adres e-mail.</div>
                                    </div>
                                </div>

                                <div class="row align-items-start mb-3">
                                    <label class="col-sm-3 col-form-label text-sm-end" for="smtpPassword">Hasło SMTP</label>
                                    <div class="col-sm-9">
                                        <input class="form-control @error('smtp_password') is-invalid @enderror" id="smtpPassword" name="smtp_password" type="password" maxlength="4096" autocomplete="new-password">
                                        @error('smtp_password')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                        <div class="form-text" data-email-account-password-help>Hasło do skrzynki pocztowej.</div>
                                    </div>
                                </div>

                                <div class="row align-items-start mb-3">
                                    <label class="col-sm-3 col-form-label text-sm-end" for="smtpEncryption">Bezpieczeństwo połączenia</label>
                                    <div class="col-sm-9">
                                        <select class="form-select @error('encryption') is-invalid @enderror" id="smtpEncryption" name="encryption" required>
                                            @foreach ($encryptionOptions as $option)
                                                <option value="{{ $option->value }}">{{ $option->label() }}</option>
                                            @endforeach
                                        </select>
                                        @error('encryption')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                    </div>
                                </div>

                                <div class="row align-items-start mb-3">
                                    <label class="col-sm-3 col-form-label text-sm-end" for="senderEmail">Pełny adres e-mail</label>
                                    <div class="col-sm-9">
                                        <input class="form-control @error('email') is-invalid @enderror" id="senderEmail" name="email" type="email" required maxlength="255" autocomplete="email">
                                        @error('email')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                        <div class="form-text">Adres, z którego będą wysyłane wiadomości.</div>
                                    </div>
                                </div>

                                <div class="row align-items-start">
                                    <label class="col-sm-3 col-form-label text-sm-end" for="senderName">Nazwa wyświetlana</label>
                                    <div class="col-sm-9">
                                        <input class="form-control @error('name') is-invalid @enderror" id="senderName" name="name" required maxlength="160" autocomplete="organization">
                                        @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                        <div class="form-text">Nazwa nadawcy widoczna dla odbiorcy wiadomości.</div>
                                    </div>
                                </div>
                            </div>
                            <div class="modal-footer">
                                <button class="btn btn-primary px-4" type="submit">Zapisz</button>
                                <button class="btn btn-light border px-4" type="button" data-bs-dismiss="modal">Zamknij</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <script>
                document.addEventListener('DOMContentLoaded', () => {
                    const modalElement = document.getElementById('emailAccountModal');
                    const form = modalElement?.querySelector('[data-email-account-form]');

                    if (!modalElement || !form) {
                        return;
                    }

                    const modal = bootstrap.Modal.getOrCreateInstance(modalElement);
                    const accounts = {{ Illuminate\Support\Js::from($accountFormData) }};
                    const oldForm = {{ Illuminate\Support\Js::from([
                        'id' => old('email_account_id'),
                        'smtp_host' => old('smtp_host'),
                        'smtp_port' => old('smtp_port'),
                        'smtp_username' => old('smtp_username'),
                        'encryption' => old('encryption'),
                        'email' => old('email'),
                        'name' => old('name'),
                    ]) }};
                    const hasValidationErrors = {{ Illuminate\Support\Js::from($errors->any()) }};
                    const requestedEditId = {{ Illuminate\Support\Js::from(request()->query('edit')) }};
                    const methodInput = form.querySelector('[data-email-account-method]');
                    const idInput = form.querySelector('[data-email-account-id]');
                    const passwordInput = form.querySelector('[name="smtp_password"]');
                    const passwordHelp = form.querySelector('[data-email-account-password-help]');

                    const fields = ['smtp_host', 'smtp_port', 'smtp_username', 'encryption', 'email', 'name'];

                    function openForm(account = null, overrides = null) {
                        const editing = account !== null;
                        const values = overrides || account || {};

                        form.action = editing
                            ? form.dataset.updateAction.replace('__ACCOUNT__', account.id)
                            : form.dataset.storeAction;
                        methodInput.disabled = !editing;
                        idInput.value = editing ? account.id : '';

                        fields.forEach((field) => {
                            const input = form.elements.namedItem(field);

                            if (!input) {
                                return;
                            }

                            const fallback = field === 'smtp_port'
                                ? '587'
                                : (field === 'encryption' ? 'starttls' : '');
                            input.value = values[field] ?? fallback;
                        });

                        passwordInput.value = '';
                        passwordInput.required = !editing || account.has_password !== true;
                        passwordHelp.textContent = editing && account.has_password === true
                            ? 'Pozostaw puste, aby zachować zapisane hasło.'
                            : 'Wpisz hasło do skrzynki pocztowej.';

                        modal.show();
                    }

                    document.querySelector('[data-email-account-create]')?.addEventListener('click', () => openForm());

                    document.querySelectorAll('[data-email-account-edit]').forEach((button) => {
                        button.addEventListener('click', () => {
                            const account = accounts[String(button.dataset.emailAccountEdit)];

                            if (account) {
                                openForm(account);
                            }
                        });
                    });

                    if (hasValidationErrors) {
                        const account = oldForm.id ? accounts[String(oldForm.id)] : null;
                        openForm(account || null, oldForm);
                    } else if (requestedEditId && accounts[String(requestedEditId)]) {
                        openForm(accounts[String(requestedEditId)]);
                    }
                });
            </script>
        @else
            @include('orders._email-templates')
        @endif
    </div>
@endsection
