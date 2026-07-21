@extends('layouts.app')

@section('title', 'InPost Kurier - NEX-OMS')

@section('content')
    @php
        $settings = $account->settings ?? [];
        $filterValue = fn (string $key, mixed $default = '') => old($key, $filters[$key] ?? $default);
        $activeFilters = collect($filters)->filter(fn ($value) => filled($value))->isNotEmpty();
        $configurationFields = [
            'name', 'environment', 'api_token', 'organization_id', 'default_service',
            'default_weight', 'default_length', 'default_width', 'default_height',
            'default_insurance_amount', 'label_format', 'label_type',
            'content_description_source', 'default_sms', 'default_email',
            'default_saturday', 'default_return_documents', 'sender_company_name',
            'sender_contact_name', 'sender_street', 'sender_building_number',
            'sender_apartment_number', 'sender_postal_code', 'sender_city',
            'sender_country_code', 'sender_phone', 'sender_email', 'is_active',
        ];
        $configurationOpen = $errors->hasAny($configurationFields)
            || $errors->has('inpost_courier_connection')
            || session()->has('inpost_courier_connection_success')
            || session()->has('inpost_courier_connection_tested');
        $contentDescriptionLabels = [
            'order_id' => 'Numer zam&oacute;wienia',
            'customer_login' => 'Login kupuj&#261;cego',
            'customer_email' => 'E-mail kupuj&#261;cego',
            'customer_phone' => 'Telefon kupuj&#261;cego',
        ];
    @endphp

    @include('integrations.couriers.partials.inpost-panel-styles')

    <div class="inpost-page">
        <header class="inpost-page-header">
            <h1 class="inpost-page-title"><span class="inpost-title-dot"></span>InPost Kurier</h1>
            <a class="btn btn-sm btn-outline-secondary" href="{{ route('integrations.couriers.index') }}">Powr&oacute;t do kurier&oacute;w</a>
        </header>

        <section class="inpost-panel">
            <button class="inpost-panel-header inpost-filter-header w-100 border-0" type="button" data-bs-toggle="collapse" data-bs-target="#inpostCourierAdvancedFilters" aria-expanded="{{ $activeFilters ? 'true' : 'false' }}" aria-controls="inpostCourierAdvancedFilters">
                <span class="inpost-filter-title"><span class="inpost-filter-icon">&#9906;</span>Wyszukiwanie zaawansowane</span>
                <span aria-hidden="true">&#8964;</span>
            </button>
            <div id="inpostCourierAdvancedFilters" class="collapse {{ $activeFilters ? 'show' : '' }}">
                <form class="inpost-filter-body" method="GET" action="{{ route('integrations.couriers.inpost-courier.edit') }}">
                    <div class="inpost-filter-grid">
                        <div class="inpost-field"><label for="courier_tracking_number">Numer przesy&#322;ki</label><input id="courier_tracking_number" class="form-control form-control-sm" name="tracking_number" value="{{ $filterValue('tracking_number') }}"></div>
                        <div class="inpost-field"><label for="courier_status">Status paczki</label><select id="courier_status" class="form-select form-select-sm" name="status"><option value="">Wszystkie</option>@foreach ($shipmentStatuses as $status => $label)<option value="{{ $status }}" @selected($filterValue('status') === $status)>{{ $label }}</option>@endforeach</select></div>
                        <div class="inpost-field"><label for="courier_service">Rodzaj przesy&#322;ki</label><select id="courier_service" class="form-select form-select-sm" name="service"><option value="">Wszystkie</option>@foreach ($serviceLabels as $service => $label)<option value="{{ $service }}" @selected($filterValue('service') === $service)>{{ $label }}</option>@endforeach</select></div>
                        <div class="inpost-field"><label for="courier_order_id">Numer zam&oacute;wienia</label><input id="courier_order_id" class="form-control form-control-sm" name="order_id" value="{{ $filterValue('order_id') }}"></div>
                        <div class="inpost-field"><label>Nazwa konta</label><input class="form-control form-control-sm" value="{{ $account->name ?: 'InPost Kurier' }}" disabled></div>
                        <div class="inpost-field"><label for="courier_cod">Pobranie</label><select id="courier_cod" class="form-select form-select-sm" name="cod"><option value="">Wszystkie</option><option value="yes" @selected($filterValue('cod') === 'yes')>Tak</option><option value="no" @selected($filterValue('cod') === 'no')>Nie</option></select></div>
                        <div class="inpost-field"><label for="courier_errors">Poka&#380; paczki z b&#322;&#281;dami</label><select id="courier_errors" class="form-select form-select-sm" name="has_errors"><option value="">Wszystkie</option><option value="yes" @selected($filterValue('has_errors') === 'yes')>Tak</option><option value="no" @selected($filterValue('has_errors') === 'no')>Nie</option></select></div>
                        <div class="inpost-date-pair">
                            <div class="inpost-field"><label for="courier_created_from">Data utworzenia od</label><input id="courier_created_from" class="form-control form-control-sm" type="date" name="created_from" value="{{ $filterValue('created_from') }}"></div>
                            <div class="inpost-field"><label for="courier_created_to">do</label><input id="courier_created_to" class="form-control form-control-sm" type="date" name="created_to" value="{{ $filterValue('created_to') }}"></div>
                        </div>
                        <div class="inpost-date-pair">
                            <div class="inpost-field"><label for="courier_status_from">Data statusu od</label><input id="courier_status_from" class="form-control form-control-sm" type="date" name="status_from" value="{{ $filterValue('status_from') }}"></div>
                            <div class="inpost-field"><label for="courier_status_to">do</label><input id="courier_status_to" class="form-control form-control-sm" type="date" name="status_to" value="{{ $filterValue('status_to') }}"></div>
                        </div>
                    </div>
                    <div class="inpost-filter-actions">
                        <a class="btn btn-sm btn-light border" href="{{ route('integrations.couriers.inpost-courier.edit') }}">Wyczy&#347;&#263; filtry</a>
                        <button class="btn btn-sm btn-primary" type="submit">Ustaw filtry</button>
                    </div>
                </form>
            </div>
        </section>

        <section class="inpost-panel nex-pagination-dropdown-host">
            <div class="inpost-panel-header"><span>Utworzone przesy&#322;ki</span><span>{{ $shipments->total() }}</span></div>
            @if ($shipments->isEmpty())
                <div class="inpost-empty">Nie znaleziono przesy&#322;ek spe&#322;niaj&#261;cych wybrane kryteria.</div>
            @else
                <div class="inpost-table-wrap">
                    <table class="table inpost-table">
                        <thead><tr><th><input class="form-check-input" type="checkbox" data-select-all-courier-shipments aria-label="Zaznacz wszystkie przesy&#322;ki na stronie"></th><th>Data utworzenia</th><th>Nazwa konta</th><th>Zam&oacute;wienie</th><th>Numer nadawczy</th><th>Us&#322;uga</th><th>Status</th><th>Szczeg&oacute;&#322;y</th><th class="text-end"></th></tr></thead>
                        <tbody>
                            @foreach ($shipments as $shipment)
                                @php
                                    $progress = $shipment->omsStatusProgress();
                                    $progressClass = $shipment->omsStatusProgressClass();
                                    $creationPayload = $shipment->latestCreateApiLog?->request_payload ?? [];
                                    $firstParcel = $shipment->parcels->first();
                                    $parcelDetails = $firstParcel
                                        ? trim($firstParcel->weight.' kg, '.$firstParcel->length.' x '.$firstParcel->width.' x '.$firstParcel->height.' cm')
                                        : '...';
                                    if ($shipment->parcels->count() > 1) {
                                        $parcelDetails .= ' (+'.($shipment->parcels->count() - 1).')';
                                    }
                                    $receiverPhone = \App\Support\PhoneNumberFormatter::normalize(data_get($creationPayload, 'receiver.phone', $shipment->order?->customer_phone)) ?: '...';
                                    $receiverEmail = data_get($creationPayload, 'receiver.email', $shipment->order?->customer_email) ?: '...';
                                    $contentDescription = data_get($creationPayload, 'comments', $shipment->content_description) ?: '...';
                                    $shipmentDetailsTitle = $shipment->tracking_number ? 'Przesylka '.$shipment->tracking_number : 'Przesylka #'.$shipment->id;
                                @endphp
                                <tr>
                                    <td><input class="form-check-input" type="checkbox" name="shipment_ids[]" value="{{ $shipment->id }}" form="bulkCourierShipmentsForm" data-courier-shipment-checkbox aria-label="Zaznacz przesy&#322;k&#281; {{ $shipment->tracking_number ?: $shipment->id }}"></td>
                                    <td>{{ $shipment->created_at?->format('d.m.Y H:i') ?: '...' }}</td>
                                    <td>{{ $shipment->courierAccount?->name ?: 'InPost Kurier' }}</td>
                                    <td>@if ($shipment->order)<a class="inpost-link" href="{{ route('orders.show', $shipment->order) }}">{{ $shipment->order->id }}</a>@else ... @endif</td>
                                    <td>@if ($shipment->trackingUrl())<a class="inpost-link" href="{{ $shipment->trackingUrl() }}" target="_blank" rel="noopener noreferrer">{{ $shipment->tracking_number }}</a>@else {{ $shipment->tracking_number ?: '...' }} @endif</td>
                                    <td>{{ $serviceLabels[$shipment->service] ?? $shipment->service }}</td>
                                    <td>
                                        <div class="shipment-status-line"><span class="shipment-status-track"><span class="shipment-status-fill {{ $progressClass }}" style="width: {{ $progress }}%"></span></span><span>{{ $shipment->statusLabel() }}</span></div>
                                        @if ($shipment->error_message)<div class="text-danger mt-1" title="{{ $shipment->error_message }}">{{ \Illuminate\Support\Str::limit($shipment->error_message, 80) }}</div>@endif
                                    </td>
                                    <td>
                                        <button
                                            class="inpost-link inpost-link-button"
                                            type="button"
                                            data-bs-toggle="modal"
                                            data-bs-target="#courierShipmentDetailsModal"
                                            data-shipment-title="{{ $shipmentDetailsTitle }}"
                                            data-shipment-parcel="{{ $parcelDetails }}"
                                            data-shipment-phone="{{ $receiverPhone }}"
                                            data-shipment-email="{{ $receiverEmail }}"
                                            data-shipment-content="{{ $contentDescription }}"
                                            data-shipment-sending-method="Odbi&oacute;r przez kuriera"
                                            aria-label="Poka&#380; szczeg&oacute;&#322;y przesy&#322;ki {{ $shipment->tracking_number ?: $shipment->id }}"
                                        >Szczeg&oacute;&#322;y</button>
                                    </td>
                                    <td class="text-end inpost-label-cell">@if ($shipment->canDownloadLabel())<a class="btn btn-sm btn-light border" href="{{ route('shipments.label', $shipment) }}">Etykieta</a>@endif</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <div class="inpost-table-footer">
                    <form id="bulkCourierShipmentsForm" class="inpost-bulk-actions" method="POST" action="{{ route('integrations.couriers.inpost-courier.shipments.refresh') }}">
                        @csrf
                        <button class="btn btn-sm btn-light border" type="submit">Od&#347;wie&#380; zaznaczone</button>
                        <button
                            class="btn btn-sm btn-light border text-danger"
                            type="submit"
                            formaction="{{ route('integrations.couriers.inpost-courier.shipments.delete') }}"
                            data-delete-selected-courier-shipments
                        >Usu&#324; zaznaczone</button>
                    </form>
                    <x-pagination-toolbar
                        :paginator="$shipments"
                        :per-page-options="$perPageOptions"
                        :per-page="$perPage"
                        aria-label="Paginacja przesy&#322;ek"
                    />
                </div>
            @endif
        </section>

        <section class="inpost-panel">
            <div class="inpost-panel-header"><span>Zam&oacute;wienie kuriera</span></div>
            <div class="inpost-empty">Nie utworzono jeszcze &#380;adnego zlecenia odbioru.</div>
        </section>

        <section class="inpost-panel">
            <div class="inpost-panel-header"><span>Konto w systemie kuriera (po&#322;&#261;czenie API)</span></div>
            <div class="inpost-table-wrap">
                <table class="table inpost-table inpost-account-table">
                    <thead><tr><th>Nazwa konta</th><th>Dane</th><th>Status</th><th class="text-end"></th></tr></thead>
                    <tbody><tr>
                        <td class="fw-semibold">{{ $account->name ?: 'InPost Kurier' }}</td>
                        <td>
                            <div class="inpost-account-details">
                                <span>Organization ID: {{ $account->organization_id ?: '...' }}</span>
                                <span>&#346;rodowisko: {{ $account->environment === 'production' ? 'Produkcja' : 'Sandbox' }}</span>
                                <span>Us&#322;uga: {{ $serviceLabels[data_get($settings, 'default_service')] ?? '...' }}</span>
                                <span>Opis zawarto&#347;ci: {!! $contentDescriptionLabels[data_get($settings, 'content_description_source', 'order_id')] ?? $contentDescriptionLabels['order_id'] !!}</span>
                                <span>Nadawca: {{ data_get($settings, 'sender_company_name') ?: '...' }}</span>
                                <span>Ostatni test: {{ $account->last_tested_at?->format('d.m.Y H:i') ?: '...' }}</span>
                            </div>
                            @if ($account->last_error)<div class="text-danger mt-2">Ostatni b&#322;&#261;d: {{ $account->last_error }}</div>@endif
                        </td>
                        <td><span class="account-state"><span class="account-state-dot {{ $account->is_active ? 'is-active' : '' }}"></span>{{ $account->is_active ? 'Konto aktywne' : 'Konto nieaktywne' }}</span></td>
                        <td class="text-end"><button class="account-action bg-white" type="button" data-bs-toggle="modal" data-bs-target="#inpostCourierAccountModal" title="Edytuj konto" aria-label="Edytuj konto InPost Kurier">&#9998;</button></td>
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
                    data-bs-target="#inpostCourierTemplateModal"
                    data-parcel-template-create
                    @disabled(! $account->exists)
                    title="{{ $account->exists ? 'Dodaj nowy szablon' : 'Najpierw zapisz konto InPost Kurier' }}"
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
                                                data-bs-target="#inpostCourierTemplateModal"
                                                data-parcel-template-edit
                                                data-template-id="{{ $template['id'] }}"
                                                data-template-name="{{ $template['name'] }}"
                                                data-template-weight="{{ $template['weight'] }}"
                                                data-template-length="{{ $template['length'] }}"
                                                data-template-width="{{ $template['width'] }}"
                                                data-template-height="{{ $template['height'] }}"
                                                title="Edytuj szablon"
                                                aria-label="Edytuj szablon {{ $template['name'] }}"
                                            >&#9998;</button>
                                            <form method="POST" action="{{ route('integrations.couriers.inpost-courier.templates.destroy', ['templateId' => $template['id']]) }}" onsubmit="return confirm('Czy na pewno usunąć ten szablon?')">
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

    <div class="modal fade" id="courierShipmentDetailsModal" tabindex="-1" aria-labelledby="courierShipmentDetailsModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <div><h2 class="modal-title fs-6" id="courierShipmentDetailsModalLabel">Szczeg&oacute;&#322;y przesy&#322;ki</h2><div class="small text-muted mt-1" data-shipment-detail="title"></div></div>
                    <button class="btn-close" type="button" data-bs-dismiss="modal" aria-label="Zamknij"></button>
                </div>
                <div class="modal-body">
                    <dl class="shipment-details-list">
                        <div class="shipment-details-row"><dt>Waga i wymiary</dt><dd data-shipment-detail="parcel">...</dd></div>
                        <div class="shipment-details-row"><dt>Telefon odbiorcy</dt><dd data-shipment-detail="phone">...</dd></div>
                        <div class="shipment-details-row"><dt>E-mail odbiorcy</dt><dd data-shipment-detail="email">...</dd></div>
                        <div class="shipment-details-row"><dt>Zawarto&#347;&#263;</dt><dd data-shipment-detail="content">...</dd></div>
                        <div class="shipment-details-row"><dt>Spos&oacute;b nadania</dt><dd data-shipment-detail="sendingMethod">...</dd></div>
                    </dl>
                </div>
                <div class="modal-footer"><button class="btn btn-sm btn-outline-secondary" type="button" data-bs-dismiss="modal">Zamknij</button></div>
            </div>
        </div>
    </div>

    @include('integrations.couriers.partials.inpost-courier-account-modal', ['settings' => $settings])
    @include('integrations.couriers.partials.inpost-courier-template-modal')

    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const selectAllShipments = document.querySelector('[data-select-all-courier-shipments]');
            const shipmentCheckboxes = [...document.querySelectorAll('[data-courier-shipment-checkbox]')];
            const bulkShipmentsForm = document.getElementById('bulkCourierShipmentsForm');

            selectAllShipments?.addEventListener('change', () => {
                shipmentCheckboxes.forEach((checkbox) => checkbox.checked = selectAllShipments.checked);
            });

            bulkShipmentsForm?.addEventListener('submit', (event) => {
                const hasSelection = shipmentCheckboxes.some((checkbox) => checkbox.checked);

                if (!hasSelection) {
                    event.preventDefault();
                    window.alert('Zaznacz co najmniej jedna przesylke.');
                    return;
                }

                if (event.submitter?.matches('[data-delete-selected-courier-shipments]')
                    && !window.confirm('Czy na pewno anulowac i usunac zaznaczone przesylki? System anuluje je u kuriera, gdy API na to pozwala.')) {
                    event.preventDefault();
                }
            });

            const shipmentDetailsModal = document.getElementById('courierShipmentDetailsModal');

            shipmentDetailsModal?.addEventListener('show.bs.modal', (event) => {
                const trigger = event.relatedTarget;

                if (!(trigger instanceof HTMLElement)) {
                    return;
                }

                ['title', 'parcel', 'phone', 'email', 'content', 'sendingMethod'].forEach((name) => {
                    const target = shipmentDetailsModal.querySelector(`[data-shipment-detail="${name}"]`);
                    const datasetName = name === 'sendingMethod' ? 'shipmentSendingMethod' : `shipment${name.charAt(0).toUpperCase()}${name.slice(1)}`;

                    if (target) {
                        target.textContent = trigger.dataset[datasetName] || '...';
                    }
                });
            });

            const accountForm = document.getElementById('inpostCourierAccountForm');
            const validationAlert = document.getElementById('inpostCourierValidationAlert');
            const templateModal = document.getElementById('inpostCourierTemplateModal');
            const templateForm = templateModal?.querySelector('[data-courier-template-form]');
            const templateMethod = templateForm?.querySelector('[data-template-method]');
            const templateIdInput = templateForm?.querySelector('[data-template-id-input]');
            const templateModalTitle = templateModal?.querySelector('[data-template-modal-title]');

            const configureTemplateForm = (trigger = null, preserveValues = false) => {
                if (!templateForm) {
                    return;
                }

                const templateId = trigger?.dataset.templateId || (preserveValues ? templateIdInput?.value : '') || '';
                templateForm.action = templateId
                    ? templateForm.dataset.updateUrl.replace('__TEMPLATE_ID__', encodeURIComponent(templateId))
                    : templateForm.dataset.storeUrl;

                if (templateMethod) {
                    templateMethod.disabled = templateId === '';
                }

                if (templateIdInput) {
                    templateIdInput.value = templateId;
                }

                if (templateModalTitle) {
                    templateModalTitle.textContent = templateId ? 'Edytuj szablon przesyłki' : 'Nowy szablon przesyłki';
                }

                if (!preserveValues) {
                    ['name', 'weight', 'length', 'width', 'height'].forEach((field) => {
                        const input = templateForm.querySelector(`[data-template-field="${field}"]`);
                        if (input) {
                            input.value = trigger?.dataset[`template${field.charAt(0).toUpperCase()}${field.slice(1)}`] || '';
                            input.classList.remove('is-invalid');
                        }
                    });
                }
            };

            templateModal?.addEventListener('show.bs.modal', (event) => {
                if (event.relatedTarget instanceof HTMLElement) {
                    configureTemplateForm(event.relatedTarget);
                }
            });
            const showFieldTab = (field) => {
                const pane = field?.closest('.tab-pane');
                const tabButton = pane ? document.querySelector(`[data-bs-target="#${pane.id}"]`) : null;

                if (tabButton) {
                    bootstrap.Tab.getOrCreateInstance(tabButton).show();
                }

                window.setTimeout(() => field?.focus(), 120);
            };
            const serverErrors = @json($errors->getMessages());

            Object.keys(serverErrors).forEach((fieldName) => {
                const field = accountForm?.elements.namedItem(fieldName);

                if (field instanceof HTMLElement) {
                    field.classList.add('is-invalid');
                }
            });

            accountForm?.addEventListener('input', (event) => {
                if (event.target instanceof HTMLElement) {
                    event.target.classList.remove('is-invalid');
                }
            });

            accountForm?.addEventListener('change', (event) => {
                if (event.target instanceof HTMLElement) {
                    event.target.classList.remove('is-invalid');
                }
            });

            accountForm?.addEventListener('submit', (event) => {
                if (accountForm.checkValidity()) {
                    validationAlert?.classList.add('d-none');
                    return;
                }

                event.preventDefault();
                event.stopPropagation();
                accountForm.classList.add('was-validated');
                validationAlert?.classList.remove('d-none');
                showFieldTab(accountForm.querySelector(':invalid'));
            });

            const firstServerInvalidField = accountForm?.querySelector('.is-invalid');
            if (firstServerInvalidField) {
                showFieldTab(firstServerInvalidField);
            }

            if (window.nexOmsOpenCourierTemplateModal) {
                configureTemplateForm(null, true);
                bootstrap.Modal.getOrCreateInstance(templateModal).show();
            }

            @if ($configurationOpen)
                bootstrap.Modal.getOrCreateInstance(document.getElementById('inpostCourierAccountModal')).show();
            @endif
        });
    </script>
@endsection
