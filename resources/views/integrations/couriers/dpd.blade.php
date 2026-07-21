@extends('layouts.app')

@section('title', 'DPD - NEX-OMS')

@section('content')
    @php
        $settings = $account->settings ?? [];
        $filterValue = fn (string $key, mixed $default = '') => old($key, $filters[$key] ?? $default);
        $activeFilters = collect($filters)->filter(fn ($value) => filled($value))->isNotEmpty();
        $configurationOpen = $errors->hasAny([
            'name', 'environment', 'api_login', 'api_token', 'organization_id', 'info_channel',
            'default_service', 'default_weight', 'default_length', 'default_width', 'default_height',
            'default_insurance_amount', 'label_format', 'label_type', 'content_description_source',
            'sender_company_name', 'sender_contact_name', 'sender_street', 'sender_building_number',
            'sender_postal_code', 'sender_city', 'sender_country_code', 'sender_phone', 'sender_email',
        ]) || $errors->has('dpd_connection') || session()->has('dpd_connection_success');
        $contentDescriptionLabels = [
            'order_id' => 'Numer zamowienia',
            'customer_login' => 'Login kupujacego',
            'customer_email' => 'E-mail kupujacego',
            'customer_phone' => 'Telefon kupujacego',
        ];
    @endphp

    @include('integrations.couriers.partials.inpost-panel-styles')

    <div class="inpost-page">
        <header class="inpost-page-header">
            <h1 class="inpost-page-title"><span class="inpost-title-dot"></span>DPD</h1>
            <a class="btn btn-sm btn-outline-secondary" href="{{ route('integrations.couriers.index') }}">Powrot do kurierow</a>
        </header>

        @if (session('success'))
            <div class="alert alert-success py-2 small">{{ session('success') }}</div>
        @endif

        <section class="inpost-panel">
            <button class="inpost-panel-header inpost-filter-header w-100 border-0" type="button" data-bs-toggle="collapse" data-bs-target="#dpdFilters" aria-expanded="{{ $activeFilters ? 'true' : 'false' }}">
                <span class="inpost-filter-title"><span class="inpost-filter-icon">&#128269;</span>Wyszukiwanie zaawansowane</span>
                <span aria-hidden="true">&#8964;</span>
            </button>
            <div id="dpdFilters" class="collapse {{ $activeFilters ? 'show' : '' }}">
                <form class="inpost-filter-body" method="GET" action="{{ route('integrations.couriers.dpd.edit') }}">
                    <div class="inpost-filter-grid">
                        <div class="inpost-field"><label for="dpd_filter_tracking">Numer przesylki</label><input id="dpd_filter_tracking" class="form-control" name="tracking_number" value="{{ $filterValue('tracking_number') }}"></div>
                        <div class="inpost-field"><label for="dpd_filter_status">Status paczki</label><select id="dpd_filter_status" class="form-select" name="status"><option value="">Wszystkie</option>@foreach ($shipmentStatuses as $value => $label)<option value="{{ $value }}" @selected($filterValue('status') === $value)>{{ $label }}</option>@endforeach</select></div>
                        <div class="inpost-field"><label for="dpd_filter_service">Usluga</label><select id="dpd_filter_service" class="form-select" name="service"><option value="">Wszystkie</option>@foreach ($serviceLabels as $value => $label)<option value="{{ $value }}" @selected($filterValue('service') === $value)>{{ $label }}</option>@endforeach</select></div>
                        <div class="inpost-field"><label for="dpd_filter_order">Numer zamowienia</label><input id="dpd_filter_order" class="form-control" name="order_id" value="{{ $filterValue('order_id') }}"></div>
                        <div class="inpost-field"><label for="dpd_filter_cod">Pobranie</label><select id="dpd_filter_cod" class="form-select" name="cod"><option value="">Wszystkie</option><option value="yes" @selected($filterValue('cod') === 'yes')>Tak</option><option value="no" @selected($filterValue('cod') === 'no')>Nie</option></select></div>
                        <div class="inpost-field"><label for="dpd_filter_errors">Bledy</label><select id="dpd_filter_errors" class="form-select" name="has_errors"><option value="">Wszystkie</option><option value="yes" @selected($filterValue('has_errors') === 'yes')>Tylko z bledami</option><option value="no" @selected($filterValue('has_errors') === 'no')>Bez bledow</option></select></div>
                        <div class="inpost-date-pair"><div class="inpost-field"><label for="dpd_created_from">Data utworzenia od</label><input id="dpd_created_from" class="form-control" type="date" name="created_from" value="{{ $filterValue('created_from') }}"></div><div class="inpost-field"><label for="dpd_created_to">do</label><input id="dpd_created_to" class="form-control" type="date" name="created_to" value="{{ $filterValue('created_to') }}"></div></div>
                        <div class="inpost-date-pair"><div class="inpost-field"><label for="dpd_status_from">Data statusu od</label><input id="dpd_status_from" class="form-control" type="date" name="status_from" value="{{ $filterValue('status_from') }}"></div><div class="inpost-field"><label for="dpd_status_to">do</label><input id="dpd_status_to" class="form-control" type="date" name="status_to" value="{{ $filterValue('status_to') }}"></div></div>
                    </div>
                    <div class="inpost-filter-actions"><a class="btn btn-sm btn-light border" href="{{ route('integrations.couriers.dpd.edit') }}">Wyczysc filtry</a><button class="btn btn-sm btn-primary" type="submit">Ustaw filtry</button></div>
                </form>
            </div>
        </section>

        <section class="inpost-panel nex-pagination-dropdown-host">
            <div class="inpost-panel-header"><span>Utworzone przesylki</span><span>{{ $shipments->total() }}</span></div>
            @if ($shipments->isEmpty())
                <div class="inpost-empty">Nie utworzono jeszcze zadnej przesylki DPD.</div>
            @else
                <div class="inpost-table-wrap">
                    <table class="table inpost-table">
                        <thead><tr><th><input class="form-check-input" type="checkbox" data-select-all-dpd aria-label="Zaznacz wszystkie"></th><th>Data utworzenia</th><th>Nazwa konta</th><th>Zamowienie</th><th>Numer nadawczy</th><th>Usluga</th><th>Status</th><th class="text-end">Etykieta</th></tr></thead>
                        <tbody>
                            @foreach ($shipments as $shipment)
                                <tr>
                                    <td><input class="form-check-input" type="checkbox" name="shipment_ids[]" value="{{ $shipment->id }}" form="bulkDpdShipmentsForm" data-dpd-shipment-checkbox aria-label="Zaznacz przesylke {{ $shipment->tracking_number ?: $shipment->id }}"></td>
                                    <td>{{ $shipment->created_at?->format('d.m.Y H:i') ?: '...' }}</td>
                                    <td>{{ $shipment->courierAccount?->name ?: 'DPD' }}</td>
                                    <td>@if ($shipment->order)<a class="inpost-link" href="{{ route('orders.show', $shipment->order) }}">{{ $shipment->order->id }}</a>@else ... @endif</td>
                                    <td>@if ($shipment->trackingUrl())<a class="inpost-link" href="{{ $shipment->trackingUrl() }}" target="_blank" rel="noopener noreferrer">{{ $shipment->tracking_number }}</a>@else {{ $shipment->tracking_number ?: '...' }} @endif</td>
                                    <td>{{ $serviceLabels[$shipment->service] ?? $shipment->service }}</td>
                                    <td><div class="shipment-status-line"><span class="shipment-status-track"><span class="shipment-status-fill {{ $shipment->omsStatusProgressClass() }}" style="width: {{ $shipment->omsStatusProgress() }}%"></span></span><span>{{ $shipment->statusLabel() }}</span></div>@if ($shipment->error_message)<div class="text-danger mt-1">{{ \Illuminate\Support\Str::limit($shipment->error_message, 90) }}</div>@endif</td>
                                    <td class="text-end inpost-label-cell">@if ($shipment->canDownloadLabel())<a class="btn btn-sm btn-light border" href="{{ route('shipments.label', $shipment) }}">Etykieta</a>@endif</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <div class="inpost-table-footer">
                    <form id="bulkDpdShipmentsForm" class="inpost-bulk-actions" method="POST" action="{{ route('integrations.couriers.dpd.shipments.refresh') }}">
                        @csrf
                        <button class="btn btn-sm btn-light border" type="submit">Odswiez zaznaczone</button>
                        <button class="btn btn-sm btn-light border text-danger" type="submit" formaction="{{ route('integrations.couriers.dpd.shipments.delete') }}" data-delete-dpd>Usun zaznaczone</button>
                    </form>
                    <x-pagination-toolbar
                        :paginator="$shipments"
                        :per-page-options="$perPageOptions"
                        :per-page="$perPage"
                        aria-label="Paginacja przesylek DPD"
                    />
                </div>
            @endif
        </section>

        <section class="inpost-panel">
            <div class="inpost-panel-header"><span>Konto w systemie kuriera (polaczenie API)</span></div>
            <div class="inpost-table-wrap">
                <table class="table inpost-table inpost-account-table">
                    <thead><tr><th>Nazwa konta</th><th>Dane</th><th>Status</th><th class="text-end"></th></tr></thead>
                    <tbody><tr>
                        <td class="fw-semibold">{{ $account->name ?: 'DPD' }}</td>
                        <td><div class="inpost-account-details"><span>Login: {{ $account->resolvedApiLogin() ?: '...' }}</span><span>Master FID: {{ $account->resolvedOrganizationId() ?: '...' }}</span><span>Kanal InfoServices: {{ $account->resolvedInfoChannel() ?: '...' }}</span><span>Srodowisko: {{ $account->environment === 'production' ? 'Produkcja' : 'Demo' }}</span><span>Usluga: {{ $serviceLabels[data_get($settings, 'default_service')] ?? '...' }}</span><span>Opis zawartosci: {{ $contentDescriptionLabels[data_get($settings, 'content_description_source', 'order_id')] ?? $contentDescriptionLabels['order_id'] }}</span><span>Nadawca: {{ data_get($settings, 'sender_company_name') ?: '...' }}</span><span>Ostatni test: {{ $account->last_tested_at?->format('d.m.Y H:i') ?: '...' }}</span></div>@if ($account->last_error)<div class="text-danger mt-2">Ostatni blad: {{ $account->last_error }}</div>@endif</td>
                        <td><span class="account-state"><span class="account-state-dot {{ $account->is_active ? 'is-active' : '' }}"></span>{{ $account->is_active ? 'Konto aktywne' : 'Konto nieaktywne' }}</span></td>
                        <td class="text-end"><button class="account-action bg-white" type="button" data-bs-toggle="modal" data-bs-target="#dpdAccountModal" title="Edytuj konto" aria-label="Edytuj konto DPD">&#9998;</button></td>
                    </tr></tbody>
                </table>
            </div>
        </section>

        <section class="inpost-panel">
            <div class="inpost-panel-header">
                <span>Szablony wymiar&oacute;w i wag przesy&#322;ek</span>
                <button
                    class="btn btn-sm btn-light border"
                    type="button"
                    data-bs-toggle="modal"
                    data-bs-target="#dpdTemplateModal"
                    data-dpd-template-create
                    @disabled(! $account->exists)
                    title="{{ $account->exists ? 'Dodaj nowy szablon' : 'Najpierw zapisz konto DPD' }}"
                >+ Nowy szablon</button>
            </div>
            @if ($parcelTemplates === [])
                <div class="inpost-empty">Nie zdefiniowano jeszcze &#380;adnego szablonu wymiar&oacute;w i wagi.</div>
            @else
                <div class="inpost-table-wrap">
                    <table class="table inpost-table inpost-template-table">
                        <thead><tr><th>Nazwa szablonu</th><th>Dane</th><th class="text-end">Akcje</th></tr></thead>
                        <tbody>
                            @foreach ($parcelTemplates as $template)
                                <tr>
                                    <td class="fw-semibold">{{ $template['name'] }}</td>
                                    <td>
                                        <div class="inpost-template-details">
                                            <span>Waga: {{ $template['weight'] }} kg</span>
                                            <span>D&#322;ugo&#347;&#263;: {{ $template['length'] }} cm</span>
                                            <span>Szeroko&#347;&#263;: {{ $template['width'] }} cm</span>
                                            <span>Wysoko&#347;&#263;: {{ $template['height'] }} cm</span>
                                        </div>
                                    </td>
                                    <td class="text-end">
                                        <div class="d-inline-flex gap-2">
                                            <button
                                                class="account-action bg-white"
                                                type="button"
                                                data-bs-toggle="modal"
                                                data-bs-target="#dpdTemplateModal"
                                                data-dpd-template-edit
                                                data-template-id="{{ $template['id'] }}"
                                                data-template-name="{{ $template['name'] }}"
                                                data-template-weight="{{ $template['weight'] }}"
                                                data-template-length="{{ $template['length'] }}"
                                                data-template-width="{{ $template['width'] }}"
                                                data-template-height="{{ $template['height'] }}"
                                                title="Edytuj szablon"
                                                aria-label="Edytuj szablon {{ $template['name'] }}"
                                            >&#9998;</button>
                                            <form method="POST" action="{{ route('integrations.couriers.dpd.templates.destroy', ['templateId' => $template['id']]) }}" onsubmit="return confirm('Czy na pewno usunac ten szablon?')">
                                                @csrf
                                                @method('DELETE')
                                                <button class="account-action bg-white" type="submit" title="Usu&#324; szablon" aria-label="Usu&#324; szablon {{ $template['name'] }}">&times;</button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>
    </div>

    <div class="modal fade inpost-modal" id="dpdAccountModal" tabindex="-1" aria-labelledby="dpdAccountModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-lg"><div class="modal-content">
            <div class="modal-header"><h2 class="modal-title fs-6" id="dpdAccountModalLabel">Konfiguracja konta DPD</h2><button class="btn-close" type="button" data-bs-dismiss="modal" aria-label="Zamknij"></button></div>
            <form id="dpdAccountForm" method="POST" action="{{ route('integrations.couriers.dpd.update') }}" novalidate>
                @csrf @method('PUT')
                <div class="modal-body">
                    <div id="dpdValidationAlert" class="alert alert-danger py-2 small d-none">Uzupelnij wymagane pola oznaczone na czerwono.</div>
                    @error('dpd_connection')<div class="alert alert-danger py-2 small">{{ $message }}</div>@enderror
                    @if (session('dpd_connection_success'))<div class="alert alert-success py-2 small">{{ session('dpd_connection_success') }}</div>@endif
                    <ul class="nav nav-tabs mb-3" role="tablist"><li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#dpdConnection" type="button">Polaczenie</button></li><li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#dpdDefaults" type="button">Przesylki</button></li><li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#dpdSender" type="button">Nadawca</button></li></ul>
                    <div class="tab-content">
                        <div class="tab-pane fade show active" id="dpdConnection"><div class="row g-3">
                            <div class="col-md-6"><label class="form-label" for="dpd_name">Nazwa konta</label><input id="dpd_name" class="form-control" name="name" value="{{ old('name', $account->name ?: 'DPD') }}" required></div>
                            <div class="col-md-6"><label class="form-label" for="dpd_environment">Srodowisko</label><select id="dpd_environment" class="form-select" name="environment" required><option value="sandbox" @selected(old('environment', $account->environment ?: 'sandbox') === 'sandbox')>Demo</option><option value="production" @selected(old('environment', $account->environment) === 'production')>Produkcja</option></select></div>
                            <div class="col-md-6"><label class="form-label" for="dpd_login">Login API</label><input id="dpd_login" class="form-control" name="api_login" value="{{ old('api_login', data_get($settings, 'api_login', config('services.dpd.login'))) }}" required></div>
                            <div class="col-md-6"><label class="form-label" for="dpd_password">Haslo API</label><input id="dpd_password" class="form-control" type="password" name="api_token" @required(blank($account->resolvedApiToken()))><div class="inpost-modal-help">Pozostaw puste, aby zachowac zapisane haslo.</div></div>
                            <div class="col-md-6"><label class="form-label" for="dpd_fid">Master FID</label><input id="dpd_fid" class="form-control" name="organization_id" value="{{ old('organization_id', $account->resolvedOrganizationId()) }}" required></div>
                            <div class="col-md-6"><label class="form-label" for="dpd_channel">Kanal InfoServices</label><input id="dpd_channel" class="form-control" name="info_channel" value="{{ old('info_channel', data_get($settings, 'info_channel', config('services.dpd.info_channel'))) }}" required></div>
                            <div class="col-12"><label class="form-check"><input class="form-check-input" type="checkbox" name="is_active" value="1" @checked(old('is_active', $account->is_active))><span class="form-check-label">Konto aktywne</span></label></div>
                        </div></div>
                        <div class="tab-pane fade" id="dpdDefaults"><div class="row g-3">
                            <div class="col-md-6"><label class="form-label" for="dpd_service">Domyslna usluga</label><select id="dpd_service" class="form-select" name="default_service" required>@foreach ($serviceLabels as $value => $label)<option value="{{ $value }}" @selected(old('default_service', data_get($settings, 'default_service', \Modules\Shipments\Models\Shipment::SERVICE_DPD_DOMESTIC)) === $value)>{{ $label }}</option>@endforeach</select></div>
                            <div class="col-md-6"><label class="form-label" for="dpd_content_source">Opis zawartosci</label><select id="dpd_content_source" class="form-select" name="content_description_source" required>@foreach ($contentDescriptionLabels as $value => $label)<option value="{{ $value }}" @selected(old('content_description_source', data_get($settings, 'content_description_source', 'order_id')) === $value)>{{ $label }}</option>@endforeach</select></div>
                            @foreach (['default_weight' => ['Waga', 700, 1], 'default_length' => ['Dlugosc', 300, 25], 'default_width' => ['Szerokosc', 300, 20], 'default_height' => ['Wysokosc', 300, 10]] as $field => [$label, $max, $default])<div class="col-6 col-md-3"><label class="form-label" for="dpd_{{ $field }}">{{ $label }}</label><input id="dpd_{{ $field }}" class="form-control" type="number" name="{{ $field }}" min="0.01" max="{{ $max }}" step="0.01" value="{{ old($field, data_get($settings, $field, $default)) }}" required></div>@endforeach
                            <div class="col-md-4"><label class="form-label" for="dpd_insurance">Domyslna wartosc deklarowana</label><input id="dpd_insurance" class="form-control" type="number" name="default_insurance_amount" min="0" step="0.01" value="{{ old('default_insurance_amount', data_get($settings, 'default_insurance_amount', 0)) }}"></div>
                            <div class="col-md-4"><label class="form-label" for="dpd_label_format">Format dokumentu</label><select id="dpd_label_format" class="form-select" name="label_format" required>@foreach (['PDF', 'ZPL', 'EPL'] as $value)<option value="{{ $value }}" @selected(old('label_format', data_get($settings, 'label_format', 'PDF')) === $value)>{{ $value }}</option>@endforeach</select></div>
                            <div class="col-md-4"><label class="form-label" for="dpd_label_type">Format etykiety</label><select id="dpd_label_type" class="form-select" name="label_type" required><option value="LABEL" @selected(old('label_type', data_get($settings, 'label_type', 'LABEL')) === 'LABEL')>Drukarka etykiet</option><option value="A4" @selected(old('label_type', data_get($settings, 'label_type')) === 'A4')>A4</option></select></div>
                            <div class="col-md-6"><label class="form-check"><input class="form-check-input" type="checkbox" name="default_saturday" value="1" @checked(old('default_saturday', data_get($settings, 'default_saturday', false)))><span class="form-check-label">Domyslnie doreczenie w sobote</span></label></div>
                            <div class="col-md-6"><label class="form-check"><input class="form-check-input" type="checkbox" name="default_return_documents" value="1" @checked(old('default_return_documents', data_get($settings, 'default_return_documents', false)))><span class="form-check-label">Domyslnie zwrot dokumentow</span></label></div>
                        </div></div>
                        <div class="tab-pane fade" id="dpdSender"><div class="row g-3">
                            <div class="col-md-6"><label class="form-label">Nazwa firmy</label><input class="form-control" name="sender_company_name" value="{{ old('sender_company_name', data_get($settings, 'sender_company_name')) }}" required></div>
                            <div class="col-md-6"><label class="form-label">Osoba kontaktowa</label><input class="form-control" name="sender_contact_name" value="{{ old('sender_contact_name', data_get($settings, 'sender_contact_name')) }}" required></div>
                            <div class="col-md-6"><label class="form-label">Ulica</label><input class="form-control" name="sender_street" value="{{ old('sender_street', data_get($settings, 'sender_street')) }}" required></div>
                            <div class="col-md-3"><label class="form-label">Numer budynku</label><input class="form-control" name="sender_building_number" value="{{ old('sender_building_number', data_get($settings, 'sender_building_number')) }}" required></div>
                            <div class="col-md-3"><label class="form-label">Numer lokalu</label><input class="form-control" name="sender_apartment_number" value="{{ old('sender_apartment_number', data_get($settings, 'sender_apartment_number')) }}"></div>
                            <div class="col-md-3"><label class="form-label">Kod pocztowy</label><input class="form-control" name="sender_postal_code" value="{{ old('sender_postal_code', data_get($settings, 'sender_postal_code')) }}" required></div>
                            <div class="col-md-6"><label class="form-label">Miasto</label><input class="form-control" name="sender_city" value="{{ old('sender_city', data_get($settings, 'sender_city')) }}" required></div>
                            <div class="col-md-3"><label class="form-label">Kraj</label><input class="form-control" name="sender_country_code" maxlength="2" value="{{ old('sender_country_code', data_get($settings, 'sender_country_code', 'PL')) }}" required></div>
                            <div class="col-md-6"><label class="form-label">Telefon</label><input class="form-control" name="sender_phone" value="{{ old('sender_phone', data_get($settings, 'sender_phone')) }}" required></div>
                            <div class="col-md-6"><label class="form-label">E-mail</label><input class="form-control" type="email" name="sender_email" value="{{ old('sender_email', data_get($settings, 'sender_email')) }}" required></div>
                        </div></div>
                    </div>
                </div>
                <div class="modal-footer justify-content-between"><button class="btn btn-sm btn-outline-secondary" type="submit" form="dpdTestForm" @disabled(! $account->exists)>Testuj polaczenie</button><div class="d-flex gap-2"><button class="btn btn-sm btn-light border" type="button" data-bs-dismiss="modal">Anuluj</button><button class="btn btn-sm btn-primary" type="submit">Zapisz</button></div></div>
            </form>
            <form id="dpdTestForm" method="POST" action="{{ route('integrations.couriers.dpd.test') }}">@csrf</form>
        </div></div>
    </div>

    @include('integrations.couriers.partials.dpd-template-modal')

    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const checkAll = document.querySelector('[data-select-all-dpd]');
            const checkboxes = [...document.querySelectorAll('[data-dpd-shipment-checkbox]')];
            const bulkForm = document.getElementById('bulkDpdShipmentsForm');
            checkAll?.addEventListener('change', () => checkboxes.forEach((checkbox) => checkbox.checked = checkAll.checked));
            bulkForm?.addEventListener('submit', (event) => {
                if (!checkboxes.some((checkbox) => checkbox.checked)) {
                    event.preventDefault();
                    window.alert('Zaznacz co najmniej jedna przesylke.');
                    return;
                }
                if (event.submitter?.matches('[data-delete-dpd]') && !window.confirm('Czy usunac zaznaczone przesylki DPD z NEX-OMS?')) event.preventDefault();
            });

            const form = document.getElementById('dpdAccountForm');
            const alert = document.getElementById('dpdValidationAlert');
            const templateModal = document.getElementById('dpdTemplateModal');
            const templateForm = templateModal?.querySelector('[data-dpd-template-form]');
            const templateMethod = templateForm?.querySelector('[data-dpd-template-method]');
            const templateIdInput = templateForm?.querySelector('[data-dpd-template-id-input]');
            const templateModalTitle = templateModal?.querySelector('[data-dpd-template-modal-title]');

            const configureTemplateForm = (trigger = null, preserveValues = false) => {
                if (!templateForm) return;

                const templateId = preserveValues
                    ? templateIdInput?.value || ''
                    : trigger?.dataset.templateId || '';
                templateForm.action = templateId
                    ? templateForm.dataset.updateUrl.replace('__TEMPLATE_ID__', encodeURIComponent(templateId))
                    : templateForm.dataset.storeUrl;

                if (templateMethod) templateMethod.disabled = templateId === '';
                if (templateIdInput) templateIdInput.value = templateId;
                if (templateModalTitle) templateModalTitle.textContent = templateId ? 'Edytuj szablon przesylki' : 'Nowy szablon przesylki';

                if (!preserveValues) {
                    ['name', 'weight', 'length', 'width', 'height'].forEach((field) => {
                        const input = templateForm.querySelector(`[data-dpd-template-field="${field}"]`);
                        if (input) {
                            input.value = trigger?.dataset[`template${field.charAt(0).toUpperCase()}${field.slice(1)}`] || '';
                            input.classList.remove('is-invalid');
                        }
                    });
                }
            };

            templateModal?.addEventListener('show.bs.modal', (event) => {
                if (event.relatedTarget instanceof HTMLElement) configureTemplateForm(event.relatedTarget);
            });

            form?.addEventListener('submit', (event) => {
                if (form.checkValidity()) {
                    alert?.classList.add('d-none');
                    return;
                }
                event.preventDefault();
                event.stopPropagation();
                form.classList.add('was-validated');
                alert?.classList.remove('d-none');
                const field = form.querySelector(':invalid');
                const pane = field?.closest('.tab-pane');
                const tab = pane ? document.querySelector(`[data-bs-target="#${pane.id}"]`) : null;
                if (tab) bootstrap.Tab.getOrCreateInstance(tab).show();
                window.setTimeout(() => field?.focus(), 120);
            });

            @if ($configurationOpen)
                bootstrap.Modal.getOrCreateInstance(document.getElementById('dpdAccountModal')).show();
            @endif

            if (window.nexOmsOpenDpdTemplateModal) {
                configureTemplateForm(null, true);
                bootstrap.Modal.getOrCreateInstance(templateModal).show();
            }
        });
    </script>
@endsection
