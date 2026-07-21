@extends('layouts.app')

@section('title', 'InPost Paczkomaty - NEX-OMS')

@section('content')
    @php
        $settings = $account->settings ?? [];
        $filterValue = fn (string $key, mixed $default = '') => old($key, $filters[$key] ?? $default);
        $activeFilters = collect($filters)->filter(fn ($value) => filled($value))->isNotEmpty();
        $configurationFields = [
            'name', 'environment', 'api_token', 'organization_id', 'default_parcel_template',
            'label_format', 'label_type', 'content_description_source', 'sending_method',
            'sender_point_id', 'sender_company_name', 'sender_contact_name', 'sender_street',
            'sender_building_number', 'sender_apartment_number', 'sender_postal_code',
            'sender_city', 'sender_country_code', 'sender_phone', 'sender_email', 'is_active',
        ];
        $configurationOpen = $errors->hasAny($configurationFields)
            || $errors->has('inpost_connection')
            || session()->has('inpost_connection_success')
            || session()->has('inpost_connection_tested');
    @endphp

    <style>
        .inpost-page {
            background: #f4f6f8;
            margin: -1.5rem;
            min-height: 100vh;
            padding: 18px;
        }

        .inpost-page-header {
            align-items: center;
            display: flex;
            justify-content: space-between;
            margin-bottom: 14px;
        }

        .inpost-page-title {
            align-items: center;
            color: #172033;
            display: flex;
            font-size: 18px;
            font-weight: 700;
            gap: 9px;
            margin: 0;
        }

        .inpost-title-dot {
            background: #0783dc;
            border-radius: 50%;
            height: 9px;
            width: 9px;
        }

        .inpost-panel {
            background: #fff;
            border: 1px solid #dce2e8;
            border-radius: 7px;
            box-shadow: 0 1px 3px rgba(15, 23, 42, .07);
            margin-bottom: 16px;
            overflow: hidden;
        }

        .inpost-panel-header {
            align-items: center;
            background: #087fd1;
            color: #fff;
            display: flex;
            font-size: 13px;
            font-weight: 600;
            justify-content: space-between;
            min-height: 42px;
            padding: 10px 13px;
        }

        .inpost-filter-header {
            background: #fff;
            color: #1f2937;
            cursor: pointer;
            min-height: 54px;
        }

        .inpost-filter-title {
            align-items: center;
            display: flex;
            gap: 9px;
        }

        .inpost-filter-icon {
            align-items: center;
            border: 1px solid #d7dee7;
            border-radius: 5px;
            color: #536174;
            display: inline-flex;
            font-size: 16px;
            height: 30px;
            justify-content: center;
            width: 30px;
        }

        .inpost-filter-body {
            border-top: 1px solid #e8edf2;
            padding: 14px 10px 16px;
        }

        .inpost-filter-grid {
            display: grid;
            gap: 10px 8px;
            grid-template-columns: repeat(5, minmax(150px, 1fr));
        }

        .inpost-field label {
            color: #4b5563;
            display: block;
            font-size: 11px;
            margin-bottom: 3px;
        }

        .inpost-field .form-control,
        .inpost-field .form-select {
            border-color: #d5dde6;
            font-size: 12px;
            min-height: 34px;
        }

        .inpost-date-pair {
            display: grid;
            gap: 6px;
            grid-column: span 2;
            grid-template-columns: 1fr 1fr;
        }

        .inpost-filter-actions {
            display: flex;
            gap: 8px;
            justify-content: flex-end;
            margin-top: 14px;
        }

        .inpost-table-wrap {
            overflow-x: auto;
        }

        .inpost-table {
            font-size: 12px;
            margin: 0;
            min-width: 1080px;
            vertical-align: middle;
        }

        .inpost-table thead th {
            background: #fff;
            border-bottom: 1px solid #ccd5df;
            color: #344054;
            font-size: 10px;
            font-weight: 600;
            padding: 10px 8px;
            text-transform: uppercase;
            white-space: nowrap;
        }

        .inpost-table tbody td {
            border-bottom: 1px solid #e2e7ed;
            color: #4b5563;
            padding: 7px 8px;
        }

        .inpost-table tbody tr:hover td {
            background: #f7fbff;
        }

        .inpost-table td.inpost-label-cell {
            padding-right: 20px;
        }

        .inpost-link {
            color: #0074c8;
            font-weight: 500;
            text-decoration: none;
        }

        .inpost-link:hover {
            text-decoration: underline;
        }

        .inpost-link-button {
            background: transparent;
            border: 0;
            padding: 0;
        }

        .shipment-details-list {
            margin: 0;
        }

        .shipment-details-row {
            align-items: start;
            border-bottom: 1px solid #edf0f3;
            display: grid;
            gap: 16px;
            grid-template-columns: minmax(130px, 38%) minmax(0, 1fr);
            padding: 10px 0;
        }

        .shipment-details-row:first-child {
            padding-top: 0;
        }

        .shipment-details-row:last-child {
            border-bottom: 0;
            padding-bottom: 0;
        }

        .shipment-details-row dt {
            color: #667085;
            font-size: 12px;
            font-weight: 400;
            margin: 0;
        }

        .shipment-details-row dd {
            color: #1f2937;
            font-size: 13px;
            font-weight: 500;
            margin: 0;
            overflow-wrap: anywhere;
            white-space: pre-line;
        }

        .shipment-status-line {
            align-items: center;
            display: flex;
            gap: 7px;
            min-width: 270px;
        }

        .shipment-status-track {
            background: #edf0f3;
            border-radius: 999px;
            flex: 0 0 82px;
            height: 7px;
            overflow: hidden;
        }

        .shipment-status-fill {
            background: #0783dc;
            border-radius: inherit;
            display: block;
            height: 100%;
        }

        .shipment-status-fill.is-success {
            background: #16834b;
        }

        .shipment-status-fill.is-error {
            background: #dc3545;
        }

        .inpost-empty {
            color: #6b7280;
            font-size: 13px;
            padding: 18px 12px;
        }

        .inpost-table-footer {
            align-items: center;
            display: flex;
            gap: 10px;
            justify-content: space-between;
            padding: 10px 12px;
        }

        .inpost-bulk-actions {
            display: flex;
            gap: 6px;
        }

        .inpost-account-table td {
            line-height: 1.45;
            vertical-align: top;
        }

        .inpost-account-details {
            display: grid;
            gap: 2px 24px;
            grid-template-columns: repeat(2, minmax(220px, 1fr));
        }

        .account-action {
            align-items: center;
            border: 1px solid #d5dde6;
            border-radius: 50%;
            color: #536174;
            display: inline-flex;
            height: 30px;
            justify-content: center;
            text-decoration: none;
            width: 30px;
        }

        .account-state {
            align-items: center;
            display: inline-flex;
            gap: 6px;
        }

        .account-state-dot {
            background: #98a2b3;
            border-radius: 50%;
            height: 8px;
            width: 8px;
        }

        .account-state-dot.is-active {
            background: #1f9d55;
        }

        .inpost-modal .modal-dialog {
            max-width: 980px;
        }

        .inpost-modal .nav-tabs .nav-link {
            color: #526071;
            font-size: 13px;
        }

        .inpost-modal .nav-tabs .nav-link.active {
            color: #087fd1;
            font-weight: 600;
        }

        .inpost-modal .form-label {
            color: #4b5563;
            font-size: 12px;
            font-weight: 500;
            margin-bottom: 4px;
        }

        .inpost-modal .form-control,
        .inpost-modal .form-select {
            font-size: 13px;
        }

        .inpost-modal .was-validated .form-control:valid:not(.is-invalid),
        .inpost-modal .was-validated .form-select:valid:not(.is-invalid) {
            background-image: none;
            border-color: #dee2e6;
        }

        .inpost-modal-help {
            color: #6b7280;
            font-size: 11px;
            margin-top: 4px;
        }

        @media (max-width: 1200px) {
            .inpost-filter-grid {
                grid-template-columns: repeat(3, minmax(160px, 1fr));
            }
        }

        @media (max-width: 768px) {
            .inpost-page {
                margin: -1rem;
                padding: 12px;
            }

            .inpost-filter-grid,
            .inpost-account-details {
                grid-template-columns: 1fr;
            }

            .inpost-date-pair {
                grid-column: span 1;
            }
        }
    </style>

    <div class="inpost-page">
        <header class="inpost-page-header">
            <h1 class="inpost-page-title"><span class="inpost-title-dot"></span>InPost Paczkomaty</h1>
            <a class="btn btn-sm btn-outline-secondary" href="{{ route('integrations.couriers.index') }}">Powr&oacute;t do kurier&oacute;w</a>
        </header>

        <section class="inpost-panel">
            <button class="inpost-panel-header inpost-filter-header w-100 border-0" type="button" data-bs-toggle="collapse" data-bs-target="#inpostAdvancedFilters" aria-expanded="{{ $activeFilters ? 'true' : 'false' }}" aria-controls="inpostAdvancedFilters">
                <span class="inpost-filter-title"><span class="inpost-filter-icon">&#9906;</span>Wyszukiwanie zaawansowane</span>
                <span aria-hidden="true">&#8964;</span>
            </button>
            <div id="inpostAdvancedFilters" class="collapse {{ $activeFilters ? 'show' : '' }}">
                <form class="inpost-filter-body" method="GET" action="{{ route('integrations.couriers.inpost-lockers.edit') }}">
                    <div class="inpost-filter-grid">
                        <div class="inpost-field"><label for="shipment_tracking_number">Numer przesy&#322;ki</label><input id="shipment_tracking_number" class="form-control form-control-sm" name="tracking_number" value="{{ $filterValue('tracking_number') }}"></div>
                        <div class="inpost-field"><label for="shipment_status">Status paczki</label><select id="shipment_status" class="form-select form-select-sm" name="status"><option value="">Wszystkie</option>@foreach ($shipmentStatuses as $value => $label)<option value="{{ $value }}" @selected($filterValue('status') === $value)>{{ $label }}</option>@endforeach</select></div>
                        <div class="inpost-field"><label for="shipment_service">Rodzaj przesy&#322;ki</label><select id="shipment_service" class="form-select form-select-sm" name="service"><option value="">Wszystkie</option><option value="{{ \Modules\Shipments\Models\Shipment::SERVICE_INPOST_LOCKER_STANDARD }}" @selected($filterValue('service') === \Modules\Shipments\Models\Shipment::SERVICE_INPOST_LOCKER_STANDARD)>Paczkomaty 24/7</option><option value="{{ \Modules\Shipments\Models\Shipment::SERVICE_INPOST_LOCKER_ALLEGRO }}" @selected($filterValue('service') === \Modules\Shipments\Models\Shipment::SERVICE_INPOST_LOCKER_ALLEGRO)>Allegro Paczkomaty 24/7 InPost</option></select></div>
                        <div class="inpost-field"><label for="shipment_order_id">Numer zam&oacute;wienia</label><input id="shipment_order_id" class="form-control form-control-sm" name="order_id" value="{{ $filterValue('order_id') }}"></div>
                        <div class="inpost-field"><label>Nazwa konta</label><input class="form-control form-control-sm" value="{{ $account->name ?: 'InPost Paczkomaty' }}" disabled></div>
                        <div class="inpost-field"><label for="shipment_cod">Pobranie</label><select id="shipment_cod" class="form-select form-select-sm" name="cod"><option value="">Wszystkie</option><option value="yes" @selected($filterValue('cod') === 'yes')>Tak</option><option value="no" @selected($filterValue('cod') === 'no')>Nie</option></select></div>
                        <div class="inpost-field"><label for="shipment_sending_method">Spos&oacute;b nadania</label><select id="shipment_sending_method" class="form-select form-select-sm" name="sending_method"><option value="">Wszystkie</option><option value="parcel_locker" @selected($filterValue('sending_method') === 'parcel_locker')>Nadanie w paczkomacie</option><option value="dispatch_order" @selected($filterValue('sending_method') === 'dispatch_order')>Odbi&oacute;r przez kuriera</option></select></div>
                        <div class="inpost-field"><label for="shipment_errors">Poka&#380; paczki z b&#322;&#281;dami</label><select id="shipment_errors" class="form-select form-select-sm" name="has_errors"><option value="">Wszystkie</option><option value="yes" @selected($filterValue('has_errors') === 'yes')>Tak</option><option value="no" @selected($filterValue('has_errors') === 'no')>Nie</option></select></div>
                        <div class="inpost-date-pair">
                            <div class="inpost-field"><label for="shipment_created_from">Data utworzenia od</label><input id="shipment_created_from" class="form-control form-control-sm" type="date" name="created_from" value="{{ $filterValue('created_from') }}"></div>
                            <div class="inpost-field"><label for="shipment_created_to">do</label><input id="shipment_created_to" class="form-control form-control-sm" type="date" name="created_to" value="{{ $filterValue('created_to') }}"></div>
                        </div>
                        <div class="inpost-date-pair">
                            <div class="inpost-field"><label for="shipment_status_from">Data statusu od</label><input id="shipment_status_from" class="form-control form-control-sm" type="date" name="status_from" value="{{ $filterValue('status_from') }}"></div>
                            <div class="inpost-field"><label for="shipment_status_to">do</label><input id="shipment_status_to" class="form-control form-control-sm" type="date" name="status_to" value="{{ $filterValue('status_to') }}"></div>
                        </div>
                    </div>
                    <div class="inpost-filter-actions">
                        <a class="btn btn-sm btn-light border" href="{{ route('integrations.couriers.inpost-lockers.edit') }}">Wyczy&#347;&#263; filtry</a>
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
                        <thead><tr><th><input class="form-check-input" type="checkbox" data-select-all-shipments aria-label="Zaznacz wszystkie przesy&#322;ki na stronie"></th><th>Data utworzenia</th><th>Nazwa konta</th><th>Zam&oacute;wienie</th><th>Numer nadawczy</th><th>Status</th><th>Szczeg&oacute;&#322;y</th><th class="text-end"></th></tr></thead>
                        <tbody>
                            @foreach ($shipments as $shipment)
                                @php
                                    $progress = $shipment->omsStatusProgress();
                                    $progressClass = $shipment->omsStatusProgressClass();
                                    $creationPayload = $shipment->latestCreateApiLog?->request_payload ?? [];
                                    $parcelData = data_get($creationPayload, 'parcels', []);

                                    if (is_array($parcelData) && array_is_list($parcelData)) {
                                        $parcelData = $parcelData[0] ?? [];
                                    }

                                    $parcelTemplate = data_get($parcelData, 'template', $shipment->parcel_template);
                                    $parcelDimensions = data_get($parcelData, 'dimensions');
                                    $parcelTemplateLabels = [
                                        'small' => 'Gabaryt A (8 x 38 x 64 cm)',
                                        'medium' => 'Gabaryt B (19 x 38 x 64 cm)',
                                        'large' => 'Gabaryt C (41 x 38 x 64 cm)',
                                    ];

                                    if (is_array($parcelDimensions) && collect($parcelDimensions)->filter(fn ($value) => filled($value))->isNotEmpty()) {
                                        $dimensionValues = collect(['length', 'width', 'height'])
                                            ->map(fn ($key) => data_get($parcelDimensions, $key))
                                            ->filter(fn ($value) => filled($value));
                                        $parcelDetails = $dimensionValues->isNotEmpty()
                                            ? $dimensionValues->implode(' x ').' cm'
                                            : collect($parcelDimensions)->filter(fn ($value) => filled($value))->implode(' x ').' cm';
                                    } else {
                                        $parcelDetails = $parcelTemplateLabels[$parcelTemplate] ?? ($parcelTemplate ?: '...');
                                    }

                                    $receiverPhone = \App\Support\PhoneNumberFormatter::normalize(
                                        data_get($creationPayload, 'receiver.phone', $shipment->order?->customer_phone)
                                    ) ?: '...';
                                    $receiverEmail = data_get($creationPayload, 'receiver.email', $shipment->order?->customer_email) ?: '...';
                                    $contentDescription = data_get($creationPayload, 'comments', $shipment->content_description) ?: '...';
                                    $sendingMethod = data_get($creationPayload, 'custom_attributes.sending_method', $shipment->sending_method);
                                    $sendingMethodLabel = match ($sendingMethod) {
                                        'parcel_locker' => 'Nadanie w paczkomacie',
                                        'dispatch_order' => 'Odbior przez kuriera',
                                        default => $sendingMethod ?: '...',
                                    };
                                    $shipmentDetailsTitle = $shipment->tracking_number
                                        ? 'Przesylka '.$shipment->tracking_number
                                        : 'Przesylka #'.$shipment->id;
                                @endphp
                                <tr>
                                    <td><input class="form-check-input" type="checkbox" name="shipment_ids[]" value="{{ $shipment->id }}" form="bulkShipmentsForm" data-shipment-checkbox aria-label="Zaznacz przesy&#322;k&#281; {{ $shipment->tracking_number ?: $shipment->id }}"></td>
                                    <td>{{ $shipment->created_at?->format('d.m.Y H:i') ?: '...' }}</td>
                                    <td>{{ $shipment->courierAccount?->name ?: 'InPost Paczkomaty' }}</td>
                                    <td>@if ($shipment->order)<a class="inpost-link" href="{{ route('orders.show', $shipment->order) }}">{{ $shipment->order->id }}</a>@else ... @endif</td>
                                    <td>@if ($shipment->trackingUrl())<a class="inpost-link" href="{{ $shipment->trackingUrl() }}" target="_blank" rel="noopener noreferrer">{{ $shipment->tracking_number }}</a>@else {{ $shipment->tracking_number ?: '...' }} @endif</td>
                                    <td>
                                        <div class="shipment-status-line"><span class="shipment-status-track"><span class="shipment-status-fill {{ $progressClass }}" style="width: {{ $progress }}%"></span></span><span>{{ $shipment->statusLabel() }}</span></div>
                                        @if ($shipment->error_message)<div class="text-danger mt-1" title="{{ $shipment->error_message }}">{{ \Illuminate\Support\Str::limit($shipment->error_message, 80) }}</div>@endif
                                    </td>
                                    <td>
                                        <button
                                            class="inpost-link inpost-link-button"
                                            type="button"
                                            data-bs-toggle="modal"
                                            data-bs-target="#shipmentDetailsModal"
                                            data-shipment-title="{{ $shipmentDetailsTitle }}"
                                            data-shipment-parcel="{{ $parcelDetails }}"
                                            data-shipment-phone="{{ $receiverPhone }}"
                                            data-shipment-email="{{ $receiverEmail }}"
                                            data-shipment-content="{{ $contentDescription }}"
                                            data-shipment-sending-method="{{ $sendingMethodLabel }}"
                                            aria-label="Poka&#380; szczeg&oacute;&#322;y przesy&#322;ki {{ $shipment->tracking_number ?: $shipment->id }}"
                                        >Szczeg&oacute;&#322;y</button>
                                    </td>
                                    <td class="text-end inpost-label-cell">
                                        @if ($shipment->canDownloadLabel())
                                            <a class="btn btn-sm btn-light border" href="{{ route('shipments.label', $shipment) }}">Etykieta</a>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <div class="inpost-table-footer">
                    <form id="bulkShipmentsForm" class="inpost-bulk-actions" method="POST" action="{{ route('integrations.couriers.inpost-lockers.shipments.refresh') }}">
                        @csrf
                        <button class="btn btn-sm btn-light border" type="submit">Od&#347;wie&#380; zaznaczone</button>
                        <button
                            class="btn btn-sm btn-light border text-danger"
                            type="submit"
                            formaction="{{ route('integrations.couriers.inpost-lockers.shipments.delete') }}"
                            data-delete-selected-shipments
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
                        <td class="fw-semibold">{{ $account->name ?: 'InPost Paczkomaty' }}</td>
                        <td>
                            <div class="inpost-account-details">
                                <span>Organization ID: {{ $account->organization_id ?: '...' }}</span>
                                <span>&#346;rodowisko: {{ $account->environment === 'production' ? 'Produkcja' : 'Sandbox' }}</span>
                                <span>Spos&oacute;b nadania: {{ data_get($settings, 'sending_method', 'dispatch_order') === 'parcel_locker' ? 'Nadanie w paczkomacie' : 'Odbi&oacute;r przez kuriera' }}</span>
                                <span>Opis zawarto&#347;ci: {{ html_entity_decode(['order_id' => 'Numer zam&oacute;wienia', 'customer_login' => 'Login kupuj&#261;cego', 'customer_email' => 'E-mail kupuj&#261;cego', 'customer_phone' => 'Telefon kupuj&#261;cego'][data_get($settings, 'content_description_source', 'order_id')] ?? 'Numer zam&oacute;wienia', ENT_QUOTES, 'UTF-8') }}</span>
                                <span>Nadawca: {{ data_get($settings, 'sender_company_name') ?: '...' }}</span>
                                <span>Ostatni test: {{ $account->last_tested_at?->format('d.m.Y H:i') ?: '...' }}</span>
                            </div>
                            @if ($account->last_error)<div class="text-danger mt-2">Ostatni b&#322;&#261;d: {{ $account->last_error }}</div>@endif
                        </td>
                        <td><span class="account-state"><span class="account-state-dot {{ $account->is_active ? 'is-active' : '' }}"></span>{{ $account->is_active ? 'Konto aktywne' : 'Konto nieaktywne' }}</span></td>
                        <td class="text-end"><button class="account-action bg-white" type="button" data-bs-toggle="modal" data-bs-target="#inpostAccountModal" title="Edytuj konto" aria-label="Edytuj konto InPost">&#9998;</button></td>
                    </tr></tbody>
                </table>
            </div>
        </section>
    </div>

    <div class="modal fade" id="shipmentDetailsModal" tabindex="-1" aria-labelledby="shipmentDetailsModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <div>
                        <h2 class="modal-title fs-6" id="shipmentDetailsModalLabel">Szczeg&oacute;&#322;y przesy&#322;ki</h2>
                        <div class="small text-muted mt-1" data-shipment-detail="title"></div>
                    </div>
                    <button class="btn-close" type="button" data-bs-dismiss="modal" aria-label="Zamknij"></button>
                </div>
                <div class="modal-body">
                    <dl class="shipment-details-list">
                        <div class="shipment-details-row"><dt>Gabaryt / wymiary</dt><dd data-shipment-detail="parcel">...</dd></div>
                        <div class="shipment-details-row"><dt>Telefon odbiorcy</dt><dd data-shipment-detail="phone">...</dd></div>
                        <div class="shipment-details-row"><dt>E-mail odbiorcy</dt><dd data-shipment-detail="email">...</dd></div>
                        <div class="shipment-details-row"><dt>Zawarto&#347;&#263;</dt><dd data-shipment-detail="content">...</dd></div>
                        <div class="shipment-details-row"><dt>Spos&oacute;b nadania</dt><dd data-shipment-detail="sendingMethod">...</dd></div>
                    </dl>
                </div>
                <div class="modal-footer">
                    <button class="btn btn-sm btn-outline-secondary" type="button" data-bs-dismiss="modal">Zamknij</button>
                </div>
            </div>
        </div>
    </div>

    @include('integrations.couriers.partials.inpost-account-modal', ['settings' => $settings])

    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const selectAll = document.querySelector('[data-select-all-shipments]');
            const shipmentCheckboxes = [...document.querySelectorAll('[data-shipment-checkbox]')];
            const bulkShipmentsForm = document.getElementById('bulkShipmentsForm');

            selectAll?.addEventListener('change', () => {
                shipmentCheckboxes.forEach((checkbox) => checkbox.checked = selectAll.checked);
            });

            bulkShipmentsForm?.addEventListener('submit', (event) => {
                const hasSelection = shipmentCheckboxes.some((checkbox) => checkbox.checked);

                if (!hasSelection) {
                    event.preventDefault();
                    window.alert('Zaznacz co najmniej jedna przesylke.');
                    return;
                }

                if (event.submitter?.matches('[data-delete-selected-shipments]')
                    && !window.confirm('Czy na pewno anulowac i usunac zaznaczone przesylki? System anuluje je u kuriera, gdy API na to pozwala.')) {
                    event.preventDefault();
                }
            });

            const shipmentDetailsModal = document.getElementById('shipmentDetailsModal');

            shipmentDetailsModal?.addEventListener('show.bs.modal', (event) => {
                const trigger = event.relatedTarget;

                if (!(trigger instanceof HTMLElement)) {
                    return;
                }

                const details = {
                    title: trigger.dataset.shipmentTitle,
                    parcel: trigger.dataset.shipmentParcel,
                    phone: trigger.dataset.shipmentPhone,
                    email: trigger.dataset.shipmentEmail,
                    content: trigger.dataset.shipmentContent,
                    sendingMethod: trigger.dataset.shipmentSendingMethod,
                };

                Object.entries(details).forEach(([name, value]) => {
                    const target = shipmentDetailsModal.querySelector(`[data-shipment-detail="${name}"]`);

                    if (target) {
                        target.textContent = value || '...';
                    }
                });
            });

            const accountForm = document.getElementById('inpostAccountForm');
            const validationAlert = document.getElementById('inpostAccountValidationAlert');
            const sendingMethod = document.getElementById('account_sending_method');
            const senderPoint = document.querySelector('[data-sender-point-field]');
            const senderPointInput = document.getElementById('account_sender_point_id');
            const updateSenderPoint = () => {
                const isParcelLocker = sendingMethod?.value === 'parcel_locker';

                senderPoint?.classList.toggle('d-none', !isParcelLocker);
                senderPointInput?.toggleAttribute('required', isParcelLocker);
            };
            const showFieldTab = (field) => {
                const pane = field?.closest('.tab-pane');
                const tabButton = pane ? document.querySelector(`[data-bs-target="#${pane.id}"]`) : null;

                if (tabButton) {
                    bootstrap.Tab.getOrCreateInstance(tabButton).show();
                }

                window.setTimeout(() => field?.focus(), 120);
            };

            sendingMethod?.addEventListener('change', updateSenderPoint);
            updateSenderPoint();

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
                updateSenderPoint();

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

            @if ($configurationOpen)
                bootstrap.Modal.getOrCreateInstance(document.getElementById('inpostAccountModal')).show();
            @endif
        });
    </script>
@endsection
