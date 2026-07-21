@extends('layouts.app')

@section('title', 'Automatyczne akcje - NEX-OMS')

@section('content')
    <style>
        .automation-page { background: #f4f6f8; margin: -1.5rem; min-height: 100vh; padding: 20px; color: #263241; }
        .automation-toolbar { align-items: center; display: flex; gap: 8px; justify-content: space-between; margin-bottom: 14px; }
        .automation-title { font-size: 17px; font-weight: 700; margin: 0; }
        .automation-panel { background: #fff; border: 1px solid #dfe4ea; border-radius: 7px; box-shadow: 0 1px 3px rgba(15, 23, 42, .06); margin-bottom: 13px; overflow: hidden; }
        .automation-filter { padding: 13px; }
        .automation-table { font-size: 12.5px; margin: 0; table-layout: fixed; width: 100%; }
        .automation-table th { background: #fff; border-bottom: 1px solid #dce2e8; color: #596579; font-size: 10px; font-weight: 700; padding: 9px 12px; text-transform: uppercase; }
        .automation-table td { border-bottom: 1px solid #edf0f3; padding: 12px; vertical-align: top; }
        .automation-table tbody tr:last-child td { border-bottom: 0; }
        .automation-table tbody tr:hover { background: #fbfdff; }
        .automation-summary-row { cursor: pointer; }
        .automation-summary-row.is-open { background: #f6fbff; }
        .automation-rule-name { color: #172033; font-size: 13px; font-weight: 700; margin-bottom: 3px; }
        .automation-rule-meta { color: #738094; font-size: 11.5px; }
        .automation-event { align-items: flex-start; display: flex; gap: 9px; }
        .automation-event-dot { background: #0b82df; border-radius: 50%; flex: 0 0 8px; height: 8px; margin-top: 5px; }
        .automation-condition { background: #f2f5f8; border: 1px solid #e2e7ec; border-radius: 4px; color: #526174; display: inline-block; font-size: 11px; margin: 5px 4px 0 0; padding: 3px 6px; }
        .automation-action-line { align-items: flex-start; display: flex; gap: 8px; margin-bottom: 6px; }
        .automation-action-line:last-child { margin-bottom: 0; }
        .automation-action-number { align-items: center; background: #e8f3fc; border-radius: 50%; color: #0877cb; display: inline-flex; flex: 0 0 20px; font-size: 10px; font-weight: 700; height: 20px; justify-content: center; }
        .automation-actions-cell { width: 38%; }
        .automation-state { align-items: center; display: flex; gap: 9px; justify-content: flex-end; white-space: nowrap; }
        .automation-empty { color: #6b7280; padding: 36px 20px; text-align: center; }
        .automation-empty strong { color: #263241; display: block; font-size: 14px; margin-bottom: 5px; }
        .automation-examples { background: #eef8ff; border: 1px solid #cbe9fb; border-radius: 6px; color: #385a72; font-size: 12px; margin-bottom: 13px; padding: 11px 13px; }
        .automation-switch { height: 18px; margin: 1px 0 0; width: 34px; }
        .automation-inline-row > td { background: #fff; border-bottom: 1px solid #ccd5df !important; padding: 0 !important; }
        .automation-inline-row:hover { background: #fff !important; }
        .automation-inline-form { border-top: 2px solid #2586d4; }
        .automation-inline-grid { display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 1fr) 250px; }
        .automation-inline-section { border-right: 1px solid #dfe4ea; min-width: 0; padding: 12px; }
        .automation-inline-settings { background: #fbfcfd; min-width: 0; }
        .automation-inline-setting { border-bottom: 1px solid #e6eaee; padding: 10px; }
        .automation-inline-setting:last-child { border-bottom: 0; }
        .automation-inline-buttons { display: flex; gap: 7px; justify-content: flex-end; }
        .automation-block-label { color: #667085; display: block; font-size: 10px; font-weight: 700; margin-bottom: 5px; }
        .automation-event-box { align-items: center; border: 1px solid #cfd7e1; border-radius: 5px; display: flex; gap: 8px; padding: 4px 8px; }
        .automation-event-box .form-select { border: 0; box-shadow: none; padding-left: 0; }
        .automation-section-separator { border-top: 1px solid #e8ecf0; margin: 13px 0; }
        .automation-condition-row { align-items: center; background: #fbfcfd; border: 1px solid #e0e5ea; border-radius: 5px; display: grid; gap: 5px; grid-template-columns: 1.1fr .8fr 1fr 28px; margin-bottom: 6px; padding: 6px; }
        .automation-action-card { border: 1px solid #dce2e8; border-radius: 6px; margin-bottom: 8px; overflow: hidden; }
        .automation-action-head { align-items: center; background: #f8fafc; border-bottom: 1px solid #e6eaee; display: flex; gap: 7px; padding: 7px; }
        .automation-action-choice { align-items: center; background: #fff; border: 1px solid #cfd7e1; border-radius: 5px; display: flex; flex: 1 1 auto; gap: 7px; min-width: 0; padding: 2px 7px; }
        .automation-action-choice .form-select { border: 0; box-shadow: none; min-width: 0; padding-left: 0; }
        .automation-action-choice:focus-within { border-color: #86b7fe; box-shadow: 0 0 0 .15rem rgba(13, 110, 253, .15); }
        .automation-action-choice-dot { background: #0b82df; border-radius: 50%; flex: 0 0 8px; height: 8px; }
        .automation-action-config { padding: 9px; }
        .automation-action-controls { display: flex; gap: 2px; margin-left: auto; }
        .automation-icon-btn { align-items: center; background: transparent; border: 1px solid transparent; border-radius: 4px; color: #64748b; display: inline-flex; font-size: 12px; height: 27px; justify-content: center; padding: 0; width: 27px; }
        .automation-icon-btn:hover { background: #fff; border-color: #d7dde4; color: #0b72c6; }
        .automation-inline-action-number { align-items: center; background: #e0f0fc; border-radius: 4px; color: #0877cb; display: inline-flex; flex: 0 0 22px; font-size: 11px; font-weight: 700; height: 22px; justify-content: center; }
        .automation-add-button { border-style: dashed; width: 100%; }
        .automation-inline-error { background: #fff1f1; border-bottom: 1px solid #f0c4c4; color: #9d2929; font-size: 11.5px; padding: 9px 10px; }
        .automation-rule-id { color: #98a2b3; font-size: 10.5px; margin-top: 22px; }
        .automation-shipment-config { display: grid; gap: 9px; }
        .automation-shipment-carrier { align-items: center; display: grid; gap: 9px; grid-template-columns: 92px minmax(0, 1fr); }
        .automation-shipment-carrier .automation-block-label { margin: 0; }
        .automation-shipment-options { background: #f7f9fb; border: 1px solid #e1e6ec; border-radius: 5px; padding: 9px; }
        .automation-shipment-checks { display: flex; flex-wrap: wrap; gap: 6px 16px; }
        .automation-shipment-checks .form-check { margin: 0; }
        .automation-shipment-parcel { align-items: end; border-top: 1px solid #e1e6ec; display: grid; gap: 6px; grid-template-columns: repeat(4, minmax(58px, 1fr)) 82px minmax(96px, 1.25fr) 28px; padding: 8px 0; }
        .automation-shipment-parcel:first-child { border-top: 0; padding-top: 0; }
        .automation-shipment-parcel .automation-block-label { font-size: 9px; margin-bottom: 3px; }
        .automation-shipment-parcel .form-control,
        .automation-shipment-parcel .form-select { font-size: 11px; min-height: 29px; padding-bottom: 3px; padding-top: 3px; }
        .automation-shipment-nonstandard { text-align: center; }
        .automation-shipment-nonstandard .form-check-input { margin: 7px 0 0; }
        .automation-shipment-remove { height: 28px; padding: 0; width: 28px; }
        @media (max-width: 900px) {
            .automation-page { margin: -1rem; padding: 12px; }
            .automation-table { min-width: 760px; }
            .automation-table-wrap { overflow-x: auto; }
            .automation-toolbar { align-items: flex-start; flex-direction: column; }
            .automation-inline-grid { grid-template-columns: 1fr; }
            .automation-inline-section { border-bottom: 1px solid #dfe4ea; border-right: 0; }
            .automation-shipment-parcel { grid-template-columns: repeat(2, minmax(90px, 1fr)); }
        }
    </style>

    <div class="automation-page">
        <div class="automation-toolbar">
            <h1 class="automation-title">Automatyczne akcje</h1>
            <div class="d-flex gap-2">
                <button class="btn btn-sm btn-outline-secondary" type="button" data-bs-toggle="collapse" data-bs-target="#automationExamples">
                    Przyk&#322;ady
                </button>
                <button class="btn btn-sm btn-success" type="button" data-create-automation>+ Dodaj automatyczn&#261; akcj&#281;</button>
            </div>
        </div>

        <div class="collapse" id="automationExamples">
            <div class="automation-examples">
                Przyk&#322;ad: po utworzeniu przesy&#322;ki zmie&#324; status zam&oacute;wienia na Wys&#322;ane. Regu&#322;a mo&#380;e te&#380; poczeka&#263; wskazan&#261; liczb&#281; minut przed kolejnym dzia&#322;aniem.
            </div>
        </div>

        <section class="automation-panel">
            <form class="automation-filter" method="GET" action="{{ route('orders.automatic-actions.index') }}">
                <div class="row g-2 align-items-end">
                    <div class="col-lg-4">
                        <label class="form-label small text-muted mb-1" for="automationSearch">Szukaj</label>
                        <input class="form-control form-control-sm" id="automationSearch" name="search" value="{{ request('search') }}" placeholder="Nazwa lub opis">
                    </div>
                    <div class="col-lg-3">
                        <label class="form-label small text-muted mb-1" for="automationTrigger">Zdarzenie</label>
                        <select class="form-select form-select-sm" id="automationTrigger" name="trigger">
                            <option value="">Wszystkie zdarzenia</option>
                            @foreach ($catalog->triggers() as $trigger => $label)
                                <option value="{{ $trigger }}" @selected(request('trigger') === $trigger)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-lg-2">
                        <label class="form-label small text-muted mb-1" for="automationActive">Stan</label>
                        <select class="form-select form-select-sm" id="automationActive" name="active">
                            <option value="">Wszystkie</option>
                            <option value="1" @selected(request('active') === '1')>Aktywne</option>
                            <option value="0" @selected(request('active') === '0')>Wy&#322;&#261;czone</option>
                        </select>
                    </div>
                    <div class="col-lg-3 d-flex gap-2">
                        <button class="btn btn-primary btn-sm" type="submit">Ustaw filtry</button>
                        <a class="btn btn-light border btn-sm" href="{{ route('orders.automatic-actions.index') }}">Wyczy&#347;&#263;</a>
                    </div>
                </div>
            </form>
        </section>

        <section class="automation-panel">
            <div class="automation-table-wrap">
                <table class="table automation-table">
                        <thead>
                            <tr>
                                <th style="width: 23%">Nazwa</th>
                                <th style="width: 29%">Zdarzenie i warunki</th>
                                <th class="automation-actions-cell">Wykonywane akcje</th>
                                <th class="text-end" style="width: 10%">Stan</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr id="automation-rule-draft" class="automation-summary-row automation-draft-row d-none is-open" data-open-automation-editor="draft">
                                <td>
                                    <div class="automation-rule-name">Nowa automatyczna akcja</div>
                                    <div class="automation-rule-meta">Niezapisany szkic</div>
                                </td>
                                <td>
                                    <div class="automation-event">
                                        <span class="automation-event-dot"></span>
                                        <div>
                                            <strong>{{ $catalog->triggerLabel(array_key_first($catalog->triggers())) }}</strong>
                                            <div><span class="automation-rule-meta">Bez dodatkowych warunk&oacute;w</span></div>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <div class="automation-action-line">
                                        <span class="automation-action-number">1</span>
                                        <span>{{ $catalog->actionLabel(\Modules\Automation\Services\AutomationCatalog::ACTION_CHANGE_STATUS) }}</span>
                                    </div>
                                </td>
                                <td class="text-end"><span class="badge text-bg-light border">Szkic</span></td>
                            </tr>
                            @include('orders._automatic-action-inline-editor', ['rule' => null, 'isDraft' => true])

                            @foreach ($rules as $rule)
                                <tr id="automation-rule-{{ $rule->id }}" class="automation-summary-row {{ (int) request('edit') === $rule->id ? 'is-open' : '' }}" data-open-automation-editor="{{ $rule->id }}">
                                    <td>
                                        <div class="automation-rule-name">{{ $rule->name }}</div>
                                        <div class="automation-rule-meta">{{ $rule->description ?: 'Bez opisu' }}</div>
                                    </td>
                                    <td>
                                        <div class="automation-event">
                                            <span class="automation-event-dot"></span>
                                            <div>
                                                <strong>{{ $catalog->triggerLabel($rule->trigger) }}</strong>
                                                <div>
                                                    @forelse ($rule->conditions ?? [] as $condition)
                                                        <span class="automation-condition">{{ $catalog->conditionSummary($condition) }}</span>
                                                    @empty
                                                        <span class="automation-rule-meta">Bez dodatkowych warunk&oacute;w</span>
                                                    @endforelse
                                                </div>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        @foreach ($rule->actions as $action)
                                            <div class="automation-action-line">
                                                <span class="automation-action-number">{{ $loop->iteration }}</span>
                                                <span>{{ $catalog->actionSummary($action->action_type, $action->configuration ?? []) }}</span>
                                            </div>
                                        @endforeach
                                    </td>
                                    <td>
                                        <div class="automation-state">
                                            <form method="POST" action="{{ route('orders.automatic-actions.toggle', $rule) }}">
                                                @csrf
                                                @method('PATCH')
                                                <input type="hidden" name="is_active" value="{{ $rule->is_active ? 0 : 1 }}">
                                                <input class="form-check-input automation-switch" type="checkbox" role="switch" @checked($rule->is_active)
                                                       aria-label="{{ $rule->is_active ? 'Wy&#322;&#261;cz regu&#322;&#281;' : 'W&#322;&#261;cz regu&#322;&#281;' }}"
                                                       onchange="this.form.submit()">
                                            </form>
                                            <div class="dropdown">
                                                <button class="btn btn-sm btn-light border" type="button" data-bs-toggle="dropdown" aria-expanded="false" aria-label="Akcje regu&#322;y">&#8942;</button>
                                                <ul class="dropdown-menu dropdown-menu-end">
                                                    <li><button class="dropdown-item" type="button" data-open-automation-editor="{{ $rule->id }}">Edytuj</button></li>
                                                    <li><hr class="dropdown-divider"></li>
                                                    <li>
                                                        <form method="POST" action="{{ route('orders.automatic-actions.destroy', $rule) }}" onsubmit="return confirm('Usunac automatyczna akcje?')">
                                                            @csrf
                                                            @method('DELETE')
                                                            <button class="dropdown-item text-danger" type="submit">Usu&#324;</button>
                                                        </form>
                                                    </li>
                                                </ul>
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                                @include('orders._automatic-action-inline-editor', ['rule' => $rule])
                            @endforeach

                            @if ($rules->isEmpty())
                                <tr data-automation-empty-row>
                                    <td colspan="4">
                                        <div class="automation-empty">
                                            <strong>Nie utworzono jeszcze automatycznych akcji.</strong>
                                            Dodaj pierwsz&#261; regu&#322;&#281;, wybierz zdarzenie i okre&#347;l kolejno wykonywane dzia&#322;ania.
                                        </div>
                                    </td>
                                </tr>
                            @endif
                        </tbody>
                    </table>
                </div>
        </section>
    </div>

    <script>
        (() => {
            const definitions = {{ Illuminate\Support\Js::from($catalog->conditionDefinitions()) }};
            const operatorLabels = {{ Illuminate\Support\Js::from($catalog->operators()) }};
            const actionLabels = {{ Illuminate\Support\Js::from($catalog->actions()) }};
            const statuses = {{ Illuminate\Support\Js::from($statuses) }};
            const shipmentDefinitions = {{ Illuminate\Support\Js::from($shipmentActionDefinitions) }};
            const editors = new Map();

            const escapeHtml = value => String(value ?? '')
                .replaceAll('&', '&amp;').replaceAll('<', '&lt;').replaceAll('>', '&gt;')
                .replaceAll('"', '&quot;').replaceAll("'", '&#039;');
            const clone = value => JSON.parse(JSON.stringify(value));
            const optionsHtml = (options, selected) => Object.entries(options)
                .map(([value, label]) => `<option value="${escapeHtml(value)}" ${String(value) === String(selected ?? '') ? 'selected' : ''}>${escapeHtml(label)}</option>`)
                .join('');

            const defaultShipmentConfiguration = requestedProvider => {
                const provider = shipmentDefinitions[requestedProvider]
                    ? requestedProvider
                    : Object.keys(shipmentDefinitions)[0] || '';
                const definition = shipmentDefinitions[provider];

                if (!definition) {
                    return {provider: '', service: '', cod_auto: true, insurance_amount: '', content_description: '', additional_services: []};
                }

                const configuration = {
                    provider,
                    service: definition.default_service ?? '',
                    cod_auto: true,
                    insurance_amount: definition.default_insurance_amount ?? '',
                    content_description: '',
                    additional_services: clone(definition.default_additional_services || []),
                };

                if (definition.kind === 'locker') {
                    configuration.parcel_template = definition.default_size || 'medium';
                    configuration.target_point_id = '';
                } else {
                    configuration.parcels = [clone(definition.default_parcel)];
                }

                return configuration;
            };

            const normalizeShipmentConfiguration = configuration => {
                const legacyProvider = configuration.provider || 'inpost_lockers';
                const defaults = defaultShipmentConfiguration(legacyProvider);
                const normalized = {...defaults, ...configuration, provider: defaults.provider};
                normalized.additional_services = Array.isArray(configuration.additional_services)
                    ? configuration.additional_services
                    : defaults.additional_services;

                if (shipmentDefinitions[normalized.provider]?.kind === 'parcels') {
                    normalized.parcels = Array.isArray(configuration.parcels) && configuration.parcels.length
                        ? configuration.parcels
                        : defaults.parcels;
                }

                return normalized;
            };

            const shipmentAdditionalServicesHtml = (definition, configuration, index) => {
                const options = Object.entries(definition.additional_services || {});
                if (!options.length) return '';

                return `<div><span class="automation-block-label">US&#321;UGI DODATKOWE</span><div class="automation-shipment-checks">${options.map(([value, label]) => `<label class="form-check small"><input class="form-check-input action-additional-service" type="checkbox" name="actions[${index}][configuration][additional_services][]" value="${escapeHtml(value)}" ${(configuration.additional_services || []).includes(value) ? 'checked' : ''}><span class="form-check-label">${escapeHtml(label)}</span></label>`).join('')}</div></div>`;
            };

            const shipmentParcelsHtml = (definition, configuration, index) => {
                const templates = definition.templates || [];
                const parcels = configuration.parcels || [clone(definition.default_parcel)];

                return `<div class="automation-shipment-options"><span class="automation-block-label">PACZKI</span><div>${parcels.map((parcel, parcelIndex) => `<div class="automation-shipment-parcel" data-shipment-parcel-index="${parcelIndex}">
                    ${[['weight', 'WAGA', definition.max_weight], ['length', 'D&#321;UGO&#346;&#262;', definition.max_dimension], ['width', 'SZEROKO&#346;&#262;', definition.max_dimension], ['height', 'WYSOKO&#346;&#262;', definition.max_dimension]].map(([key, label, max]) => `<div><label class="automation-block-label">${label}</label><input class="form-control form-control-sm action-parcel-config" data-key="${key}" type="number" min="0.01" max="${max}" step="0.01" required name="actions[${index}][configuration][parcels][${parcelIndex}][${key}]" value="${escapeHtml(parcel[key])}"></div>`).join('')}
                    ${definition.supports_non_standard ? `<div class="automation-shipment-nonstandard"><label class="automation-block-label">NIESTANDARD</label><input type="hidden" name="actions[${index}][configuration][parcels][${parcelIndex}][is_non_standard]" value="0"><input class="form-check-input action-parcel-config" data-key="is_non_standard" type="checkbox" name="actions[${index}][configuration][parcels][${parcelIndex}][is_non_standard]" value="1" ${parcel.is_non_standard ? 'checked' : ''}></div>` : '<div></div>'}
                    <div><label class="automation-block-label">SZABLON</label><select class="form-select form-select-sm action-parcel-template"><option value="">-- wybierz</option>${templates.map(template => `<option value="${escapeHtml(template.id)}">${escapeHtml(template.name)}</option>`).join('')}</select></div>
                    <button class="btn btn-sm btn-light border automation-shipment-remove action-remove-parcel" type="button" aria-label="Usu&#324; paczk&#281;" ${parcels.length === 1 ? 'disabled' : ''}>&times;</button>
                </div>`).join('')}</div><button class="btn btn-sm btn-light border action-add-parcel" type="button">+ Kolejna paczka</button></div>`;
            };

            const shipmentActionConfiguration = (action, index) => {
                const configuration = normalizeShipmentConfiguration(action.configuration || {});
                action.configuration = configuration;
                const providerOptions = Object.fromEntries(Object.entries(shipmentDefinitions).map(([provider, definition]) => [provider, definition.label]));
                const definition = shipmentDefinitions[configuration.provider];

                if (!definition) {
                    return `<div class="alert alert-warning py-2 px-3 small mb-0">Brak aktywnej integracji kurierskiej. Aktywuj konto kuriera w Integracje &rarr; Kurierzy.</div><input type="hidden" name="actions[${index}][configuration][provider]" value="">`;
                }

                const common = `<div class="automation-shipment-carrier"><label class="automation-block-label">PRZEWO&#377;NIK</label><select class="form-select form-select-sm shipment-provider" name="actions[${index}][configuration][provider]">${optionsHtml(providerOptions, configuration.provider)}</select></div>
                    <div class="row g-2"><div class="col-md-6"><label class="automation-block-label">US&#321;UGA</label><select class="form-select form-select-sm action-config" data-key="service" name="actions[${index}][configuration][service]">${optionsHtml(definition.services, configuration.service || '')}</select></div><div class="col-md-3"><label class="automation-block-label">UBEZPIECZENIE</label><input class="form-control form-control-sm action-config" data-key="insurance_amount" type="number" min="0" step="0.01" name="actions[${index}][configuration][insurance_amount]" value="${escapeHtml(configuration.insurance_amount)}"></div><div class="col-md-3 d-flex align-items-end"><label class="form-check small mb-1"><input type="hidden" name="actions[${index}][configuration][cod_auto]" value="0"><input class="form-check-input action-config" data-key="cod_auto" type="checkbox" name="actions[${index}][configuration][cod_auto]" value="1" ${configuration.cod_auto ? 'checked' : ''}><span class="form-check-label">Pobranie automatyczne</span></label></div></div>
                    <div><label class="automation-block-label">OPIS ZAWARTO&#346;CI (OPCJONALNIE)</label><input class="form-control form-control-sm action-config" data-key="content_description" maxlength="100" name="actions[${index}][configuration][content_description]" value="${escapeHtml(configuration.content_description)}" placeholder="Domy&#347;lnie wed&#322;ug ustawie&#324; integracji"></div>`;

                const providerFields = definition.kind === 'locker'
                    ? `<div class="row g-2"><div class="col-md-4"><label class="automation-block-label">GABARYT</label><select class="form-select form-select-sm action-config" data-key="parcel_template" name="actions[${index}][configuration][parcel_template]">${optionsHtml(definition.sizes, configuration.parcel_template || definition.default_size)}</select></div><div class="col-md-8"><label class="automation-block-label">PACZKOMAT ODBIORCZY (OPCJONALNIE)</label><input class="form-control form-control-sm action-config" data-key="target_point_id" name="actions[${index}][configuration][target_point_id]" value="${escapeHtml(configuration.target_point_id)}" placeholder="Domy&#347;lnie z zam&oacute;wienia"></div></div>`
                    : shipmentParcelsHtml(definition, configuration, index);

                return `<div class="automation-shipment-config">${common}${shipmentAdditionalServicesHtml(definition, configuration, index)}${providerFields}</div>`;
            };

            const closeEditor = ruleId => {
                document.querySelector(`[data-automation-editor-row="${ruleId}"]`)?.classList.add('d-none');
                document.querySelector(`#automation-rule-${ruleId}`)?.classList.remove('is-open');
            };

            const openEditor = (ruleId, toggle = false) => {
                const editorRow = document.querySelector(`[data-automation-editor-row="${ruleId}"]`);
                if (!editorRow) return;

                if (toggle && !editorRow.classList.contains('d-none')) {
                    closeEditor(ruleId);
                    return;
                }

                document.querySelectorAll('[data-automation-editor-row]').forEach(row => {
                    if (row.dataset.automationEditorRow !== String(ruleId)) closeEditor(row.dataset.automationEditorRow);
                });
                editorRow.classList.remove('d-none');
                document.querySelector(`#automation-rule-${ruleId}`)?.classList.add('is-open');
            };

            const initializeEditor = form => {
                const ruleId = form.dataset.ruleId;
                const isDraft = form.dataset.isDraft === '1';
                const initial = JSON.parse(form.querySelector('[data-editor-state]').textContent);
                let conditions = clone(initial.conditions || []);
                let actions = clone(initial.actions || []);
                const conditionsRoot = form.querySelector('[data-conditions-root]');
                const conditionsEmpty = form.querySelector('[data-conditions-empty]');
                const actionsRoot = form.querySelector('[data-actions-root]');
                const errorBox = form.querySelector('[data-editor-error]');

                const renderConditions = () => {
                    conditionsEmpty.classList.toggle('d-none', conditions.length > 0);
                    conditionsRoot.innerHTML = conditions.map((condition, index) => {
                        const field = condition.field && definitions[condition.field] ? condition.field : Object.keys(definitions)[0];
                        const definition = definitions[field];
                        const operator = definition.operators.includes(condition.operator) ? condition.operator : definition.operators[0];
                        conditions[index] = {...condition, field, operator};
                        const valueControl = definition.type === 'select'
                            ? `<select class="form-select form-select-sm condition-value" name="conditions[${index}][value]">${optionsHtml(definition.options, condition.value)}</select>`
                            : `<input class="form-control form-control-sm condition-value" type="${definition.type === 'number' ? 'number' : 'text'}" ${definition.type === 'number' ? 'step="0.01"' : ''} name="conditions[${index}][value]" value="${escapeHtml(condition.value)}">`;

                        return `<div class="automation-condition-row" data-condition-index="${index}">
                            <select class="form-select form-select-sm condition-field" name="conditions[${index}][field]">${optionsHtml(Object.fromEntries(Object.entries(definitions).map(([key, item]) => [key, item.label])), field)}</select>
                            <select class="form-select form-select-sm condition-operator" name="conditions[${index}][operator]">${optionsHtml(Object.fromEntries(definition.operators.map(key => [key, operatorLabels[key] || key])), operator)}</select>
                            ${valueControl}
                            <button class="automation-icon-btn condition-remove" type="button" aria-label="Usu&#324; warunek">&#10005;</button>
                        </div>`;
                    }).join('');

                    conditionsRoot.querySelectorAll('[data-condition-index]').forEach(row => {
                        const index = Number(row.dataset.conditionIndex);
                        row.querySelector('.condition-field').addEventListener('change', event => {
                            const field = event.target.value;
                            conditions[index] = {field, operator: definitions[field].operators[0], value: ''};
                            renderConditions();
                        });
                        row.querySelector('.condition-operator').addEventListener('change', event => conditions[index].operator = event.target.value);
                        row.querySelector('.condition-value').addEventListener('input', event => conditions[index].value = event.target.value);
                        row.querySelector('.condition-value').addEventListener('change', event => conditions[index].value = event.target.value);
                        row.querySelector('.condition-remove').addEventListener('click', () => {
                            conditions.splice(index, 1);
                            renderConditions();
                        });
                    });
                };

                const actionConfiguration = (action, index) => {
                    const configuration = action.configuration || {};
                    if (action.type === 'change_order_status') {
                        return `<label class="automation-block-label">STATUS DOCELOWY</label><select class="form-select form-select-sm action-config" data-key="status" name="actions[${index}][configuration][status]">${optionsHtml(statuses, configuration.status)}</select>`;
                    }
                    if (action.type === 'create_inpost_shipment') {
                        return shipmentActionConfiguration(action, index);
                    }
                    if (action.type === 'call_url') {
                        return `<label class="automation-block-label">ADRES URL (GET)</label><input class="form-control form-control-sm action-config" data-key="url" type="url" maxlength="2048" required name="actions[${index}][configuration][url]" value="${escapeHtml(configuration.url)}" placeholder="https://example.com/webhook?order=[id_zamowienia]"><div class="form-text">Mo&#380;esz u&#380;ywa&#263; zmiennych z <a href="{{ route('settings.variables.index') }}" target="_blank" rel="noopener">Ustawienia &rarr; Zmienne</a>.</div>`;
                    }
                    return `<label class="automation-block-label">CZAS W MINUTACH</label><input class="form-control form-control-sm action-config" data-key="minutes" type="number" min="1" max="86400" name="actions[${index}][configuration][minutes]" value="${escapeHtml(configuration.minutes || 1)}">`;
                };

                const defaultActionConfiguration = type => {
                    if (type === 'change_order_status') return {status: Object.keys(statuses)[0]};
                    if (type === 'create_inpost_shipment') return defaultShipmentConfiguration();
                    if (type === 'call_url') return {url: ''};
                    return {minutes: 1};
                };

                const moveAction = (index, direction) => {
                    const target = index + direction;
                    if (target < 0 || target >= actions.length) return;
                    [actions[index], actions[target]] = [actions[target], actions[index]];
                    renderActions();
                };

                const renderActions = () => {
                    actionsRoot.innerHTML = actions.map((action, index) => {
                        const type = action.type && actionLabels[action.type] ? action.type : Object.keys(actionLabels)[0];
                        actions[index] = {...action, type, configuration: action.configuration || {}, stop_on_error: action.stop_on_error !== false && String(action.stop_on_error) !== '0'};
                        return `<div class="automation-action-card" data-action-index="${index}">
                            <div class="automation-action-head">
                                <span class="automation-inline-action-number">${index + 1}.</span>
                                <div class="automation-action-choice"><span class="automation-action-choice-dot"></span><select class="form-select form-select-sm action-type" name="actions[${index}][type]" aria-label="Wybierz rodzaj akcji">${optionsHtml(actionLabels, type)}</select></div>
                                <div class="automation-action-controls"><button class="automation-icon-btn action-up" type="button" aria-label="Przesu&#324; wy&#380;ej" ${index === 0 ? 'disabled' : ''}>&#8593;</button><button class="automation-icon-btn action-down" type="button" aria-label="Przesu&#324; ni&#380;ej" ${index === actions.length - 1 ? 'disabled' : ''}>&#8595;</button><button class="automation-icon-btn action-remove" type="button" aria-label="Usu&#324; akcj&#281;" ${actions.length === 1 ? 'disabled' : ''}>&#10005;</button></div>
                            </div>
                            <div class="automation-action-config">${actionConfiguration(actions[index], index)}<div class="form-check mt-2"><input type="hidden" name="actions[${index}][stop_on_error]" value="0"><input class="form-check-input action-stop" id="actionStop${ruleId}-${index}" type="checkbox" name="actions[${index}][stop_on_error]" value="1" ${actions[index].stop_on_error ? 'checked' : ''}><label class="form-check-label small text-muted" for="actionStop${ruleId}-${index}">Zatrzymaj regu&#322;&#281; po b&#322;&#281;dzie</label></div></div>
                        </div>`;
                    }).join('');

                    actionsRoot.querySelectorAll('[data-action-index]').forEach(card => {
                        const index = Number(card.dataset.actionIndex);
                        card.querySelector('.action-type').addEventListener('change', event => {
                            const type = event.target.value;
                            const configuration = defaultActionConfiguration(type);
                            actions[index] = {type, configuration, stop_on_error: actions[index].stop_on_error};
                            renderActions();
                        });
                        card.querySelectorAll('.action-config').forEach(control => {
                            const update = event => actions[index].configuration[event.target.dataset.key] = event.target.type === 'checkbox' ? event.target.checked : event.target.value;
                            control.addEventListener('input', update);
                            control.addEventListener('change', update);
                        });
                        card.querySelector('.shipment-provider')?.addEventListener('change', event => {
                            actions[index].configuration = defaultShipmentConfiguration(event.target.value);
                            renderActions();
                        });
                        card.querySelectorAll('.action-additional-service').forEach(control => control.addEventListener('change', () => {
                            actions[index].configuration.additional_services = [...card.querySelectorAll('.action-additional-service:checked')].map(input => input.value);
                        }));
                        card.querySelectorAll('[data-shipment-parcel-index]').forEach(row => {
                            const parcelIndex = Number(row.dataset.shipmentParcelIndex);
                            row.querySelectorAll('.action-parcel-config').forEach(control => {
                                const update = event => actions[index].configuration.parcels[parcelIndex][event.target.dataset.key] = event.target.type === 'checkbox' ? event.target.checked : event.target.value;
                                control.addEventListener('input', update);
                                control.addEventListener('change', update);
                            });
                            row.querySelector('.action-parcel-template')?.addEventListener('change', event => {
                                const definition = shipmentDefinitions[actions[index].configuration.provider];
                                const template = (definition.templates || []).find(item => String(item.id) === String(event.target.value));
                                if (!template) return;
                                ['weight', 'length', 'width', 'height'].forEach(key => actions[index].configuration.parcels[parcelIndex][key] = template[key]);
                                renderActions();
                            });
                            row.querySelector('.action-remove-parcel')?.addEventListener('click', () => {
                                if (actions[index].configuration.parcels.length > 1) {
                                    actions[index].configuration.parcels.splice(parcelIndex, 1);
                                    renderActions();
                                }
                            });
                        });
                        card.querySelector('.action-add-parcel')?.addEventListener('click', () => {
                            const definition = shipmentDefinitions[actions[index].configuration.provider];
                            actions[index].configuration.parcels.push(clone(definition.default_parcel));
                            renderActions();
                        });
                        card.querySelector('.action-stop').addEventListener('change', event => actions[index].stop_on_error = event.target.checked);
                        card.querySelector('.action-up').addEventListener('click', () => moveAction(index, -1));
                        card.querySelector('.action-down').addEventListener('click', () => moveAction(index, 1));
                        card.querySelector('.action-remove').addEventListener('click', () => {
                            if (actions.length > 1) {
                                actions.splice(index, 1);
                                renderActions();
                            }
                        });
                    });
                };

                form.querySelector('[data-add-condition]').addEventListener('click', () => {
                    const field = Object.keys(definitions)[0];
                    conditions.push({field, operator: definitions[field].operators[0], value: ''});
                    renderConditions();
                });
                form.querySelector('[data-add-action]').addEventListener('click', () => {
                    actions.push({type: 'change_order_status', configuration: {status: Object.keys(statuses)[0]}, stop_on_error: true});
                    renderActions();
                });
                form.querySelector('[data-cancel-editor]').addEventListener('click', () => {
                    form.reset();
                    conditions = clone(initial.conditions || []);
                    actions = clone(initial.actions || []);
                    errorBox.classList.add('d-none');
                    renderConditions();
                    renderActions();
                    closeEditor(ruleId);
                    if (isDraft) {
                        document.querySelector('#automation-rule-draft')?.classList.add('d-none');
                        if (document.querySelectorAll('.automation-summary-row:not(.automation-draft-row)').length === 0) {
                            document.querySelector('[data-automation-empty-row]')?.classList.remove('d-none');
                        }
                    }
                });
                form.addEventListener('submit', async event => {
                    event.preventDefault();
                    const saveButton = form.querySelector('[data-save-editor]');
                    saveButton.disabled = true;
                    errorBox.classList.add('d-none');
                    try {
                        const response = await fetch(form.action, {method: 'POST', headers: {'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest'}, body: new FormData(form)});
                        const payload = await response.json();
                        if (!response.ok) {
                            const messages = Object.values(payload.errors || {}).flat();
                            throw new Error(messages.join(' ') || payload.message || 'Nie uda&#322;o si&#281; zapisa&#263; automatycznej akcji.');
                        }
                        window.location.href = payload.redirect_url || '{{ route('orders.automatic-actions.index') }}';
                    } catch (error) {
                        errorBox.textContent = error.message;
                        errorBox.classList.remove('d-none');
                        saveButton.disabled = false;
                    }
                });

                renderConditions();
                renderActions();
                editors.set(String(ruleId), {reset: () => form.querySelector('[data-cancel-editor]').click()});
            };

            document.querySelectorAll('[data-automation-editor]').forEach(initializeEditor);
            document.querySelectorAll('.automation-summary-row').forEach(row => row.addEventListener('click', event => {
                if (event.target.closest('a, button, input, select, textarea, label, form')) return;
                openEditor(row.dataset.openAutomationEditor, true);
            }));
            document.querySelectorAll('button[data-open-automation-editor]').forEach(button => button.addEventListener('click', () => openEditor(button.dataset.openAutomationEditor)));

            const createButton = document.querySelector('[data-create-automation]');
            createButton?.addEventListener('click', () => {
                document.querySelector('[data-automation-empty-row]')?.classList.add('d-none');
                document.querySelector('#automation-rule-draft')?.classList.remove('d-none');
                openEditor('draft');
                document.querySelector('#automation-rule-draft')?.scrollIntoView({behavior: 'smooth', block: 'center'});
            });
        })();
    </script>
@endsection
