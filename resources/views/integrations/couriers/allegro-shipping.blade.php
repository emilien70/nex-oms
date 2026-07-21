@extends('layouts.app')

@section('title', 'Wysyłam z Allegro - NEX-OMS')

@section('content')
    @php
        $settings = $account->settings ?? [];
        $deviceAuthorization = session('allegro_device_authorization');
        $filterValue = fn (string $key, mixed $default = '') => old($key, $filters[$key] ?? $default);
        $activeFilters = collect($filters)->filter(fn ($value) => filled($value))->isNotEmpty();
        $shipmentTextSourceLabels = [
            'order_id' => 'Numer zam&oacute;wienia',
            'external_id' => 'Numer transakcji Allegro',
            'customer_login' => 'Login kupuj&#261;cego',
            'customer_email' => 'E-mail kupuj&#261;cego',
            'customer_phone' => 'Telefon kupuj&#261;cego',
        ];
        $configurationOpen = $errors->hasAny([
            'name', 'environment', 'organization_id', 'api_secret', 'allegro_device',
            'label_format', 'label_type', 'content_description_source', 'reference_number_source',
            'default_weight', 'default_length', 'default_width', 'default_height',
            'allegro_shipping_connection',
        ]) || session()->has('allegro_shipping_connection_success') || is_array($deviceAuthorization);
    @endphp

    @include('integrations.couriers.partials.inpost-panel-styles')

    <div class="inpost-page">
        <header class="inpost-page-header">
            <h1 class="inpost-page-title"><span class="inpost-title-dot"></span>Wysy&#322;am z Allegro</h1>
            <a class="btn btn-sm btn-outline-secondary" href="{{ route('integrations.couriers.index') }}">Powr&oacute;t do kurier&oacute;w</a>
        </header>

        @if (session('success'))<div class="alert alert-success py-2 small">{{ session('success') }}</div>@endif

        <section class="inpost-panel">
            <button class="inpost-panel-header inpost-filter-header w-100 border-0" type="button" data-bs-toggle="collapse" data-bs-target="#allegroShippingFilters" aria-expanded="{{ $activeFilters ? 'true' : 'false' }}">
                <span class="inpost-filter-title"><span class="inpost-filter-icon">&#128269;</span>Wyszukiwanie zaawansowane</span><span aria-hidden="true">&#8964;</span>
            </button>
            <div id="allegroShippingFilters" class="collapse {{ $activeFilters ? 'show' : '' }}">
                <form class="inpost-filter-body" method="GET" action="{{ route('integrations.couriers.allegro-shipping.edit') }}">
                    <div class="inpost-filter-grid">
                        <div class="inpost-field"><label for="allegro_filter_tracking">Numer przesy&#322;ki</label><input id="allegro_filter_tracking" class="form-control" name="tracking_number" value="{{ $filterValue('tracking_number') }}"></div>
                        <div class="inpost-field"><label for="allegro_filter_status">Status paczki</label><select id="allegro_filter_status" class="form-select" name="status"><option value="">Wszystkie</option>@foreach ($shipmentStatuses as $value => $label)<option value="{{ $value }}" @selected($filterValue('status') === $value)>{{ $label }}</option>@endforeach</select></div>
                        <div class="inpost-field"><label for="allegro_filter_order">Numer zam&oacute;wienia</label><input id="allegro_filter_order" class="form-control" name="order_id" value="{{ $filterValue('order_id') }}"></div>
                        <div class="inpost-field"><label for="allegro_filter_cod">Pobranie</label><select id="allegro_filter_cod" class="form-select" name="cod"><option value="">Wszystkie</option><option value="yes" @selected($filterValue('cod') === 'yes')>Tak</option><option value="no" @selected($filterValue('cod') === 'no')>Nie</option></select></div>
                        <div class="inpost-field"><label for="allegro_filter_errors">B&#322;&#281;dy</label><select id="allegro_filter_errors" class="form-select" name="has_errors"><option value="">Wszystkie</option><option value="yes" @selected($filterValue('has_errors') === 'yes')>Tylko z b&#322;&#281;dami</option><option value="no" @selected($filterValue('has_errors') === 'no')>Bez b&#322;&#281;d&oacute;w</option></select></div>
                        <div class="inpost-date-pair"><div class="inpost-field"><label for="allegro_created_from">Data utworzenia od</label><input id="allegro_created_from" class="form-control" type="date" name="created_from" value="{{ $filterValue('created_from') }}"></div><div class="inpost-field"><label for="allegro_created_to">do</label><input id="allegro_created_to" class="form-control" type="date" name="created_to" value="{{ $filterValue('created_to') }}"></div></div>
                        <div class="inpost-date-pair"><div class="inpost-field"><label for="allegro_status_from">Data statusu od</label><input id="allegro_status_from" class="form-control" type="date" name="status_from" value="{{ $filterValue('status_from') }}"></div><div class="inpost-field"><label for="allegro_status_to">do</label><input id="allegro_status_to" class="form-control" type="date" name="status_to" value="{{ $filterValue('status_to') }}"></div></div>
                    </div>
                    <div class="inpost-filter-actions"><a class="btn btn-sm btn-light border" href="{{ route('integrations.couriers.allegro-shipping.edit') }}">Wyczy&#347;&#263; filtry</a><button class="btn btn-sm btn-primary" type="submit">Ustaw filtry</button></div>
                </form>
            </div>
        </section>

        <section class="inpost-panel nex-pagination-dropdown-host">
            <div class="inpost-panel-header"><span>Utworzone przesy&#322;ki</span><span>{{ $shipments->total() }}</span></div>
            @if ($shipments->isEmpty())
                <div class="inpost-empty">Nie utworzono jeszcze &#380;adnej przesy&#322;ki przez Wysy&#322;am z Allegro.</div>
            @else
                <div class="inpost-table-wrap"><table class="table inpost-table">
                    <thead><tr><th><input class="form-check-input" type="checkbox" data-select-all-allegro aria-label="Zaznacz wszystkie"></th><th>Data utworzenia</th><th>Nazwa konta</th><th>Zam&oacute;wienie</th><th>Przewo&#378;nik</th><th>Numer nadawczy</th><th>Status</th><th class="text-end">Etykieta</th></tr></thead>
                    <tbody>@foreach ($shipments as $shipment)<tr>
                        <td><input class="form-check-input" type="checkbox" name="shipment_ids[]" value="{{ $shipment->id }}" form="bulkAllegroShipmentsForm" data-allegro-shipment-checkbox aria-label="Zaznacz przesy&#322;k&#281; {{ $shipment->tracking_number ?: $shipment->id }}"></td>
                        <td>{{ $shipment->created_at?->format('d.m.Y H:i') ?: '...' }}</td>
                        <td>{{ $shipment->courierAccount?->name ?: 'Wysylam z Allegro' }}</td>
                        <td>@if ($shipment->order)<a class="inpost-link" href="{{ route('orders.show', $shipment->order) }}">{{ $shipment->order->id }}</a>@else ... @endif</td>
                        <td>{{ $shipment->carrier_code ?: '...' }}</td>
                        <td>@if ($shipment->trackingUrl())<a class="inpost-link" href="{{ $shipment->trackingUrl() }}" target="_blank" rel="noopener noreferrer">{{ $shipment->tracking_number }}</a>@else {{ $shipment->tracking_number ?: '...' }} @endif</td>
                        <td><div class="shipment-status-line"><span class="shipment-status-track"><span class="shipment-status-fill {{ $shipment->omsStatusProgressClass() }}" style="width: {{ $shipment->omsStatusProgress() }}%"></span></span><span>{{ $shipment->statusLabel() }}</span></div>@if ($shipment->error_message)<div class="text-danger mt-1">{{ \Illuminate\Support\Str::limit($shipment->error_message, 90) }}</div>@endif</td>
                        <td class="text-end inpost-label-cell">@if ($shipment->canDownloadLabel())<a class="btn btn-sm btn-light border" href="{{ route('shipments.label', $shipment) }}">Etykieta</a>@endif</td>
                    </tr>@endforeach</tbody>
                </table></div>
                <div class="inpost-table-footer">
                    <form id="bulkAllegroShipmentsForm" class="inpost-bulk-actions" method="POST" action="{{ route('integrations.couriers.allegro-shipping.shipments.refresh') }}">@csrf
                        <button class="btn btn-sm btn-light border" type="submit">Od&#347;wie&#380; zaznaczone</button>
                        <button class="btn btn-sm btn-light border text-danger" type="submit" formaction="{{ route('integrations.couriers.allegro-shipping.shipments.delete') }}" data-delete-allegro>Usu&#324; zaznaczone</button>
                    </form>
                    <x-pagination-toolbar :paginator="$shipments" :per-page-options="$perPageOptions" :per-page="$perPage" aria-label="Paginacja przesy&#322;ek Allegro" />
                </div>
            @endif
        </section>

        <section class="inpost-panel">
            <div class="inpost-panel-header"><span>Konto w systemie kuriera (po&#322;&#261;czenie API)</span></div>
            <div class="inpost-table-wrap"><table class="table inpost-table inpost-account-table">
                <thead><tr><th>Nazwa konta</th><th>Dane</th><th>Status</th><th class="text-end"></th></tr></thead>
                <tbody><tr>
                    <td class="fw-semibold">{{ $account->name ?: 'Wysylam z Allegro' }}</td>
                    <td><div class="inpost-account-details"><span>Client ID: {{ $account->organization_id ?: '...' }}</span><span>&#346;rodowisko: {{ $account->environment === 'production' ? 'Produkcja' : 'Sandbox' }}</span><span>OAuth: {{ $account->hasCompleteCredentials() ? 'Po&#322;&#261;czono' : 'Nie po&#322;&#261;czono' }}</span><span>Etykieta: {{ data_get($settings, 'label_format', 'PDF') }} / {{ data_get($settings, 'label_type', 'A6') }}</span><span>Opis zawarto&#347;ci: {!! $shipmentTextSourceLabels[data_get($settings, 'content_description_source', 'order_id')] ?? $shipmentTextSourceLabels['order_id'] !!}</span><span>Numer referencyjny: {!! $shipmentTextSourceLabels[data_get($settings, 'reference_number_source', 'order_id')] ?? $shipmentTextSourceLabels['order_id'] !!}</span><span>Ostatni test: {{ $account->last_tested_at?->format('d.m.Y H:i') ?: '...' }}</span></div>@if ($account->last_error)<div class="text-danger mt-2">Ostatni b&#322;&#261;d: {{ $account->last_error }}</div>@endif</td>
                    <td><span class="account-state"><span class="account-state-dot {{ $account->isOperational() ? 'is-active' : '' }}"></span>{{ $account->isOperational() ? 'Konto aktywne' : ($account->hasCompleteCredentials() ? 'Po&#322;&#261;czone, nieaktywne' : 'Niepo&#322;&#261;czone') }}</span></td>
                    <td class="text-end"><button class="account-action bg-white" type="button" data-bs-toggle="modal" data-bs-target="#allegroShippingAccountModal" title="Edytuj konto" aria-label="Edytuj konto Wysy&#322;am z Allegro">&#9998;</button></td>
                </tr></tbody>
            </table></div>
        </section>

        <section class="inpost-panel">
            <div class="inpost-panel-header">
                <span>Szablony wymiar&oacute;w i wag przesy&#322;ek</span>
                <button
                    class="btn btn-sm btn-light border"
                    type="button"
                    data-bs-toggle="modal"
                    data-bs-target="#allegroShippingTemplateModal"
                    data-allegro-template-create
                    @disabled(! $account->exists)
                    title="{{ $account->exists ? 'Dodaj nowy szablon' : 'Najpierw zapisz konto Wysylam z Allegro' }}"
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
                                    <td><div class="inpost-template-details"><span>Waga: {{ $template['weight'] }} kg</span><span>D&#322;ugo&#347;&#263;: {{ $template['length'] }} cm</span><span>Szeroko&#347;&#263;: {{ $template['width'] }} cm</span><span>Wysoko&#347;&#263;: {{ $template['height'] }} cm</span></div></td>
                                    <td class="text-end">
                                        <div class="d-inline-flex gap-2">
                                            <button
                                                class="account-action bg-white"
                                                type="button"
                                                data-bs-toggle="modal"
                                                data-bs-target="#allegroShippingTemplateModal"
                                                data-allegro-template-edit
                                                data-template-id="{{ $template['id'] }}"
                                                data-template-name="{{ $template['name'] }}"
                                                data-template-weight="{{ $template['weight'] }}"
                                                data-template-length="{{ $template['length'] }}"
                                                data-template-width="{{ $template['width'] }}"
                                                data-template-height="{{ $template['height'] }}"
                                                title="Edytuj szablon"
                                                aria-label="Edytuj szablon {{ $template['name'] }}"
                                            >&#9998;</button>
                                            <form method="POST" action="{{ route('integrations.couriers.allegro-shipping.templates.destroy', ['templateId' => $template['id']]) }}" onsubmit="return confirm('Czy na pewno usunac ten szablon?')">
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

    <div class="modal fade inpost-modal" id="allegroShippingAccountModal" tabindex="-1" aria-labelledby="allegroShippingAccountModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-lg"><div class="modal-content">
            <div class="modal-header"><h2 class="modal-title fs-6" id="allegroShippingAccountModalLabel">Konfiguracja Wysy&#322;am z Allegro</h2><button class="btn-close" type="button" data-bs-dismiss="modal" aria-label="Zamknij"></button></div>
            <form id="allegroShippingAccountForm" method="POST" action="{{ route('integrations.couriers.allegro-shipping.update') }}" novalidate>@csrf @method('PUT')
                <div class="modal-body">
                    <div id="allegroValidationAlert" class="alert alert-danger py-2 small d-none">Uzupe&#322;nij wymagane pola oznaczone na czerwono.</div>
                    @error('allegro_shipping_connection')<div class="alert alert-danger py-2 small">{{ $message }}</div>@enderror
                    @error('allegro_device')<div class="alert alert-danger py-2 small">{{ $message }}</div>@enderror
                    @if (session('allegro_shipping_connection_success'))<div class="alert alert-success py-2 small">{{ session('allegro_shipping_connection_success') }}</div>@endif
                    <ul class="nav nav-tabs mb-3" role="tablist"><li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#allegroConnection" type="button">Po&#322;&#261;czenie</button></li><li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#allegroDefaults" type="button">Przesy&#322;ki</button></li></ul>
                    <div class="tab-content">
                        <div class="tab-pane fade show active" id="allegroConnection"><div class="row g-3">
                            <div class="col-md-6"><label class="form-label" for="allegro_name">Nazwa konta</label><input id="allegro_name" class="form-control" name="name" value="{{ old('name', $account->name ?: 'Wysylam z Allegro') }}" required></div>
                            <div class="col-md-6"><label class="form-label" for="allegro_environment">&#346;rodowisko</label><select id="allegro_environment" class="form-select" name="environment" required><option value="sandbox" @selected(old('environment', $account->environment ?: 'sandbox') === 'sandbox')>Sandbox</option><option value="production" @selected(old('environment', $account->environment) === 'production')>Produkcja</option></select></div>
                            <div class="col-md-6"><label class="form-label" for="allegro_client_id">Client ID</label><input id="allegro_client_id" class="form-control" name="organization_id" value="{{ old('organization_id', $account->organization_id ?: config('services.allegro_shipping.client_id')) }}" required></div>
                            <div class="col-md-6"><label class="form-label" for="allegro_client_secret">Client Secret</label><input id="allegro_client_secret" class="form-control" type="password" name="api_secret"><div class="inpost-modal-help">Pozostaw puste, aby zachowa&#263; zapisany sekret.</div></div>
                            <div class="col-12">
                                <div class="border rounded-3 p-3 bg-light" id="allegroDevicePanel">
                                    @if (is_array($deviceAuthorization))
                                        @php($readableUserCode = strtoupper(implode(' ', str_split(preg_replace('/\s+/', '', (string) $deviceAuthorization['user_code']), 3))))
                                        <div class="fw-semibold mb-1">Potwierd&#378; po&#322;&#261;czenie na stronie Allegro</div>
                                        <div class="small text-muted mb-2">Kod jest wa&#380;ny przez ograniczony czas. Otw&oacute;rz Allegro i zaakceptuj dost&#281;p aplikacji NEX-OMS.</div>
                                        <div class="d-flex flex-wrap align-items-center gap-2">
                                            <code class="fs-5 px-2 py-1 bg-white border rounded" id="allegroDeviceCode">{{ $readableUserCode }}</code>
                                            <a class="btn btn-sm btn-primary" href="{{ $deviceAuthorization['verification_uri_complete'] }}" target="_blank" rel="noopener noreferrer">Otw&oacute;rz Allegro</a>
                                            <button class="btn btn-sm btn-light border" type="submit" formaction="{{ route('integrations.couriers.allegro-shipping.device.cancel') }}" formmethod="POST">Anuluj &#322;&#261;czenie</button>
                                        </div>
                                        <div class="small text-primary mt-2" id="allegroDeviceStatus" role="status">Oczekiwanie na potwierdzenie w Allegro...</div>
                                    @elseif ($account->hasCompleteCredentials())
                                        <div class="d-flex align-items-center gap-2"><span class="account-state-dot is-active"></span><strong>Konto Allegro jest po&#322;&#261;czone</strong></div>
                                        <div class="inpost-modal-help mt-1">Token dost&#281;pu jest odnawiany automatycznie za pomoc&#261; zaszyfrowanego refresh tokenu.</div>
                                    @else
                                        <div class="fw-semibold">Konto Allegro nie jest jeszcze po&#322;&#261;czone</div>
                                        <div class="inpost-modal-help mt-1">Zapisz Client ID i Client Secret, a nast&#281;pnie u&#380;yj przycisku Po&#322;&#261;cz z Allegro.</div>
                                    @endif
                                </div>
                            </div>
                            <div class="col-12"><label class="form-check"><input class="form-check-input" type="checkbox" name="is_active" value="1" @checked(old('is_active', $account->is_active))><span class="form-check-label">Konto aktywne</span></label></div>
                        </div></div>
                        <div class="tab-pane fade" id="allegroDefaults"><div class="row g-3">
                            <div class="col-md-6"><label class="form-label" for="allegro_label_format">Format etykiety</label><select id="allegro_label_format" class="form-select" name="label_format" required><option value="PDF" @selected(old('label_format', data_get($settings, 'label_format', 'PDF')) === 'PDF')>PDF</option><option value="ZPL" @selected(old('label_format', data_get($settings, 'label_format')) === 'ZPL')>ZPL</option></select></div>
                            <div class="col-md-6"><label class="form-label" for="allegro_label_type">Rozmiar strony</label><select id="allegro_label_type" class="form-select" name="label_type" required><option value="A6" @selected(old('label_type', data_get($settings, 'label_type', 'A6')) === 'A6')>A6</option><option value="A4" @selected(old('label_type', data_get($settings, 'label_type')) === 'A4')>A4</option></select></div>
                            <div class="col-md-6"><label class="form-label" for="allegro_content_description_source">Opis zawarto&#347;ci</label><select id="allegro_content_description_source" class="form-select" name="content_description_source" required>@foreach ($shipmentTextSourceLabels as $value => $label)<option value="{{ $value }}" @selected(old('content_description_source', data_get($settings, 'content_description_source', 'order_id')) === $value)>{!! $label !!}</option>@endforeach</select></div>
                            <div class="col-md-6"><label class="form-label" for="allegro_reference_number_source">Numer referencyjny</label><select id="allegro_reference_number_source" class="form-select" name="reference_number_source" required>@foreach ($shipmentTextSourceLabels as $value => $label)<option value="{{ $value }}" @selected(old('reference_number_source', data_get($settings, 'reference_number_source', 'order_id')) === $value)>{!! $label !!}</option>@endforeach</select></div>
                            <div class="col-12"><div class="fw-semibold small border-top pt-3">Domy&#347;lne parametry przesy&#322;ki</div></div>
                            @foreach (['default_weight' => ['Waga', 1], 'default_length' => ['D&#322;ugo&#347;&#263;', 25], 'default_width' => ['Szeroko&#347;&#263;', 20], 'default_height' => ['Wysoko&#347;&#263;', 10]] as $field => [$label, $default])
                                <div class="col-md-3"><label class="form-label" for="allegro_{{ $field }}">{!! $label !!}</label><input id="allegro_{{ $field }}" class="form-control" type="text" inputmode="decimal" name="{{ $field }}" value="{{ old($field, data_get($settings, $field, $default)) }}" required></div>
                            @endforeach
                            <div class="col-12 inpost-modal-help">Warto&#347;ci s&#261; domy&#347;lnie wpisywane do ka&#380;dej paczki. Przed nadaniem mo&#380;esz je zmieni&#263; w formularzu zam&oacute;wienia.</div>
                        </div></div>
                    </div>
                </div>
                <div class="modal-footer">
                    @if (! is_array($deviceAuthorization))
                        <button class="btn btn-sm btn-outline-primary" type="submit" formaction="{{ route('integrations.couriers.allegro-shipping.device.start') }}" formmethod="POST">
                            @if ($account->hasCompleteCredentials()) Po&#322;&#261;cz ponownie @else Po&#322;&#261;cz z Allegro @endif
                        </button>
                    @endif
                    @if ($account->hasCompleteCredentials())<button class="btn btn-sm btn-outline-secondary" type="submit" formaction="{{ route('integrations.couriers.allegro-shipping.test') }}" formmethod="POST">Testuj po&#322;&#261;czenie</button>@endif
                    <button class="btn btn-sm btn-light border" type="button" data-bs-dismiss="modal">Anuluj</button><button class="btn btn-sm btn-primary" type="submit">Zapisz</button>
                </div>
            </form>
        </div></div>
    </div>

    @include('integrations.couriers.partials.allegro-shipping-template-modal')

    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const all = document.querySelector('[data-select-all-allegro]');
            const boxes = Array.from(document.querySelectorAll('[data-allegro-shipment-checkbox]'));
            all?.addEventListener('change', () => boxes.forEach(box => { box.checked = all.checked; }));
            document.querySelector('[data-delete-allegro]')?.addEventListener('click', event => {
                if (!boxes.some(box => box.checked) || !confirm('Czy anulowac zaznaczone przesylki w Allegro i usunac je z NEX-OMS?')) event.preventDefault();
            });
            const form = document.getElementById('allegroShippingAccountForm');
            form?.addEventListener('submit', event => {
                if (event.submitter?.formAction?.endsWith('/test') || event.submitter?.formAction?.endsWith('/device/cancel')) return;
                form.querySelectorAll(':invalid').forEach(field => field.classList.add('is-invalid'));
                if (!form.checkValidity()) { event.preventDefault(); document.getElementById('allegroValidationAlert')?.classList.remove('d-none'); }
            });
            const templateModal = document.getElementById('allegroShippingTemplateModal');
            const templateForm = templateModal?.querySelector('[data-allegro-template-form]');
            const templateMethod = templateForm?.querySelector('[data-allegro-template-method]');
            const templateIdInput = templateForm?.querySelector('[data-allegro-template-id-input]');
            const templateModalTitle = templateModal?.querySelector('[data-allegro-template-modal-title]');
            const configureTemplateForm = (trigger = null, preserveValues = false) => {
                if (!templateForm) return;
                const templateId = trigger?.dataset.templateId || (preserveValues ? templateIdInput?.value : '') || '';
                templateForm.action = templateId
                    ? templateForm.dataset.updateUrl.replace('__TEMPLATE_ID__', encodeURIComponent(templateId))
                    : templateForm.dataset.storeUrl;
                if (templateMethod) templateMethod.disabled = templateId === '';
                if (templateIdInput) templateIdInput.value = templateId;
                if (templateModalTitle) templateModalTitle.textContent = templateId ? 'Edytuj szablon przesy\u0142ki' : 'Nowy szablon przesy\u0142ki';
                if (!preserveValues) {
                    ['name', 'weight', 'length', 'width', 'height'].forEach(field => {
                        const input = templateForm.querySelector(`[data-allegro-template-field="${field}"]`);
                        if (input) {
                            input.value = trigger?.dataset[`template${field.charAt(0).toUpperCase()}${field.slice(1)}`] || '';
                            input.classList.remove('is-invalid');
                        }
                    });
                }
            };
            templateModal?.addEventListener('show.bs.modal', event => {
                if (event.relatedTarget instanceof HTMLElement) configureTemplateForm(event.relatedTarget);
            });
            if (window.nexOmsOpenAllegroTemplateModal) {
                configureTemplateForm(null, true);
                bootstrap.Modal.getOrCreateInstance(templateModal).show();
            }
            @if (is_array($deviceAuthorization))
                const deviceStatus = document.getElementById('allegroDeviceStatus');
                let deviceInterval = {{ max(5, (int) ($deviceAuthorization['interval'] ?? 5)) }};
                const pollDeviceAuthorization = () => {
                    window.setTimeout(async () => {
                        try {
                            const response = await fetch(@json(route('integrations.couriers.allegro-shipping.device.poll')), {
                                method: 'POST',
                                headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': @json(csrf_token()) },
                            });
                            const result = await response.json();
                            if (result.status === 'connected') {
                                if (deviceStatus) deviceStatus.textContent = 'Konto zosta\u0142o po\u0142\u0105czone. Od\u015bwie\u017cam panel...';
                                window.setTimeout(() => window.location.reload(), 700);
                                return;
                            }
                            if (!response.ok) {
                                if (deviceStatus) { deviceStatus.textContent = result.message || 'Nie uda\u0142o si\u0119 po\u0142\u0105czy\u0107 konta.'; deviceStatus.classList.replace('text-primary', 'text-danger'); }
                                return;
                            }
                            deviceInterval = Math.max(5, Number(result.interval || deviceInterval));
                            pollDeviceAuthorization();
                        } catch (error) {
                            if (deviceStatus) deviceStatus.textContent = 'Chwilowy problem z po\u0142\u0105czeniem. Ponawiam sprawdzenie...';
                            pollDeviceAuthorization();
                        }
                    }, deviceInterval * 1000);
                };
                pollDeviceAuthorization();
            @endif
            @if ($configurationOpen)
                bootstrap.Modal.getOrCreateInstance(document.getElementById('allegroShippingAccountModal')).show();
            @endif
        });
    </script>
@endsection
