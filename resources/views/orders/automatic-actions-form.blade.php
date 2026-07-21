@extends('layouts.app')

@section('title', ($automationRule->exists ? 'Edycja' : 'Nowa').' automatyczna akcja - NEX-OMS')

@section('content')
    @php
        $storedActions = $automationRule->exists
            ? $automationRule->actions->map(fn ($action) => [
                'type' => $action->action_type,
                'configuration' => $action->configuration ?? [],
                'stop_on_error' => $action->stop_on_error,
            ])->values()->all()
            : [[
                'type' => \Modules\Automation\Services\AutomationCatalog::ACTION_CHANGE_STATUS,
                'configuration' => ['status' => array_key_first($statuses)],
                'stop_on_error' => true,
            ]];
        $initialConditions = old('conditions', $automationRule->conditions ?? []);
        $initialActions = old('actions', $storedActions);
    @endphp

    <style>
        .automation-editor-page { background: #f4f6f8; margin: -1.5rem; min-height: 100vh; padding: 20px; color: #263241; }
        .automation-editor-top { align-items: center; display: flex; gap: 12px; justify-content: space-between; margin-bottom: 14px; }
        .automation-editor-title { font-size: 17px; font-weight: 700; margin: 0; }
        .automation-editor-grid { align-items: start; display: grid; gap: 14px; grid-template-columns: minmax(0, 1fr) minmax(0, 1fr) 270px; }
        .automation-editor-panel { background: #fff; border: 1px solid #dfe4ea; border-radius: 7px; box-shadow: 0 1px 3px rgba(15, 23, 42, .06); overflow: hidden; }
        .automation-editor-panel-header { align-items: center; background: #f8fafc; border-bottom: 1px solid #e5e7eb; display: flex; justify-content: space-between; padding: 11px 13px; }
        .automation-editor-panel-title { font-size: 13px; font-weight: 700; margin: 0; }
        .automation-editor-body { padding: 13px; }
        .automation-block-label { color: #667085; display: block; font-size: 11px; font-weight: 600; margin-bottom: 5px; }
        .automation-event-box { align-items: center; border: 1px solid #cfd7e1; border-radius: 5px; display: flex; gap: 8px; padding: 7px 9px; }
        .automation-event-dot { background: #0b82df; border-radius: 50%; flex: 0 0 8px; height: 8px; }
        .automation-event-box .form-select { border: 0; box-shadow: none; padding-left: 0; }
        .automation-section-separator { border-top: 1px solid #e8ecf0; margin: 15px 0; }
        .automation-condition-row { align-items: center; background: #fbfcfd; border: 1px solid #e0e5ea; border-radius: 6px; display: grid; gap: 6px; grid-template-columns: 1.15fr .85fr 1fr 30px; margin-bottom: 7px; padding: 7px; }
        .automation-action-card { background: #fff; border: 1px solid #dce2e8; border-radius: 7px; margin-bottom: 9px; overflow: hidden; }
        .automation-action-head { align-items: center; background: #f8fafc; border-bottom: 1px solid #e6eaee; display: flex; gap: 8px; padding: 8px 9px; }
        .automation-action-number { align-items: center; background: #0b82df; border-radius: 50%; color: #fff; display: inline-flex; flex: 0 0 21px; font-size: 10px; font-weight: 700; height: 21px; justify-content: center; }
        .automation-action-choice { align-items: center; background: #fff; border: 1px solid #cfd7e1; border-radius: 5px; display: flex; flex: 1 1 auto; gap: 8px; min-width: 0; padding: 2px 8px; }
        .automation-action-choice-dot { background: #0b82df; border-radius: 50%; flex: 0 0 8px; height: 8px; }
        .automation-action-choice .form-select { background-color: #fff; border: 0; box-shadow: none; font-size: 12.5px; font-weight: 600; min-width: 0; padding-bottom: 5px; padding-left: 0; padding-top: 5px; }
        .automation-action-choice:focus-within { border-color: #86b7fe; box-shadow: 0 0 0 .15rem rgba(13, 110, 253, .15); }
        .automation-action-config { padding: 10px; }
        .automation-action-controls { display: flex; gap: 3px; margin-left: auto; }
        .automation-icon-btn { align-items: center; background: transparent; border: 1px solid transparent; border-radius: 4px; color: #64748b; display: inline-flex; font-size: 12px; height: 27px; justify-content: center; padding: 0; width: 27px; }
        .automation-icon-btn:hover { background: #fff; border-color: #d7dde4; color: #0b72c6; }
        .automation-sidebar-row { border-bottom: 1px solid #edf0f3; padding: 11px 13px; }
        .automation-sidebar-row:last-child { border-bottom: 0; }
        .automation-summary { color: #627084; font-size: 11.5px; line-height: 1.5; }
        .automation-add-button { border-style: dashed; width: 100%; }
        .automation-form-actions { display: flex; gap: 7px; justify-content: flex-end; }
        .automation-validation { background: #fff4f4; border: 1px solid #f3c5c5; border-radius: 6px; color: #a12f2f; font-size: 12px; margin-bottom: 13px; padding: 10px 13px; }
        @media (max-width: 1180px) { .automation-editor-grid { grid-template-columns: 1fr 1fr; } .automation-editor-sidebar { grid-column: 1 / -1; } }
        @media (max-width: 760px) { .automation-editor-page { margin: -1rem; padding: 12px; } .automation-editor-grid { grid-template-columns: 1fr; } .automation-editor-sidebar { grid-column: auto; } .automation-condition-row { grid-template-columns: 1fr; } }
    </style>

    <div class="automation-editor-page">
        <div class="automation-editor-top">
            <h1 class="automation-editor-title">{{ $automationRule->exists ? 'Edycja automatycznej akcji' : 'Nowa automatyczna akcja' }}</h1>
            <a class="btn btn-sm btn-outline-secondary" href="{{ route('orders.automatic-actions.index') }}">Powr&oacute;t do listy</a>
        </div>

        @if ($errors->any())
            <div class="automation-validation">
                <strong>Nie uda&#322;o si&#281; zapisa&#263; regu&#322;y.</strong> Sprawd&#378; zaznaczone pola i konfiguracj&#281; dzia&#322;a&#324;.
            </div>
        @endif

        <form method="POST" action="{{ $automationRule->exists ? route('orders.automatic-actions.update', $automationRule) : route('orders.automatic-actions.store') }}" id="automationRuleForm">
            @csrf
            @if ($automationRule->exists)
                @method('PUT')
            @endif
            <div class="automation-editor-grid">
                <section class="automation-editor-panel">
                    <header class="automation-editor-panel-header">
                        <h2 class="automation-editor-panel-title">Kiedy akcja ma si&#281; uruchomi&#263;?</h2>
                    </header>
                    <div class="automation-editor-body">
                        <label class="automation-block-label" for="automationTrigger">ZDARZENIE</label>
                        <div class="automation-event-box">
                            <span class="automation-event-dot"></span>
                            <select class="form-select form-select-sm" id="automationTrigger" name="trigger" required>
                                @foreach ($catalog->triggers() as $trigger => $label)
                                    <option value="{{ $trigger }}" @selected(old('trigger', $automationRule->trigger) === $trigger)>{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>

                        <div class="automation-section-separator"></div>
                        <div class="d-flex align-items-center justify-content-between mb-2">
                            <div>
                                <span class="automation-block-label mb-0">WARUNKI</span>
                                <small class="text-muted">Wszystkie musz&#261; by&#263; spe&#322;nione</small>
                            </div>
                            <button class="btn btn-sm btn-light border" type="button" id="addAutomationCondition">+ Dodaj warunek</button>
                        </div>
                        <div id="automationConditions"></div>
                        <div class="text-muted small" id="automationConditionsEmpty">Brak dodatkowych warunk&oacute;w. Regu&#322;a zadzia&#322;a dla ka&#380;dego takiego zdarzenia.</div>
                    </div>
                </section>

                <section class="automation-editor-panel">
                    <header class="automation-editor-panel-header">
                        <h2 class="automation-editor-panel-title">Co ma zrobi&#263; NEX-OMS?</h2>
                    </header>
                    <div class="automation-editor-body">
                        <div id="automationActions"></div>
                        <button class="btn btn-sm btn-light automation-add-button" type="button" id="addAutomationAction">+ Dodaj kolejn&#261; akcj&#281;</button>
                    </div>
                </section>

                <aside class="automation-editor-panel automation-editor-sidebar">
                    <header class="automation-editor-panel-header">
                        <h2 class="automation-editor-panel-title">Ustawienia regu&#322;y</h2>
                    </header>
                    <div class="automation-sidebar-row">
                        <label class="form-label small mb-1" for="automationName">Nazwa</label>
                        <input class="form-control form-control-sm @error('name') is-invalid @enderror" id="automationName" name="name" value="{{ old('name', $automationRule->name) }}" maxlength="255" placeholder="Opcjonalna - zostaw puste, aby wygenerowa&#263;">
                        @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="automation-sidebar-row">
                        <label class="form-label small mb-1" for="automationDescription">Opis</label>
                        <textarea class="form-control form-control-sm" id="automationDescription" name="description" rows="3" maxlength="1000">{{ old('description', $automationRule->description) }}</textarea>
                    </div>
                    <div class="automation-sidebar-row">
                        <div class="form-check form-switch">
                            <input type="hidden" name="is_active" value="0">
                            <input class="form-check-input" id="automationActive" name="is_active" type="checkbox" value="1" @checked((bool) old('is_active', $automationRule->is_active))>
                            <label class="form-check-label small" for="automationActive">Regu&#322;a aktywna</label>
                        </div>
                    </div>
                    <div class="automation-sidebar-row automation-summary">
                        Dzia&#322;ania s&#261; wykonywane kolejno w tle. Ka&#380;de wykonanie zostanie zapisane w historii zam&oacute;wienia. Ta sama regu&#322;a nie uruchomi si&#281; ponownie w tym samym &#322;a&#324;cuchu zdarze&#324;.
                    </div>
                    <div class="automation-sidebar-row">
                        <div class="automation-form-actions">
                            <a class="btn btn-sm btn-light border" href="{{ route('orders.automatic-actions.index') }}">Anuluj</a>
                            <button class="btn btn-sm btn-primary" type="submit">Zapisz</button>
                        </div>
                    </div>
                </aside>
            </div>
        </form>
    </div>

    <script>
        (() => {
            const definitions = {{ Illuminate\Support\Js::from($catalog->conditionDefinitions()) }};
            const operatorLabels = {{ Illuminate\Support\Js::from($catalog->operators()) }};
            const actionLabels = {{ Illuminate\Support\Js::from($catalog->actions()) }};
            const statuses = {{ Illuminate\Support\Js::from($statuses) }};
            const parcelTemplates = {{ Illuminate\Support\Js::from($catalog->parcelTemplates()) }};
            const inPostServices = {{ Illuminate\Support\Js::from($catalog->inPostServices()) }};
            let conditions = {{ Illuminate\Support\Js::from(array_values($initialConditions)) }};
            let actions = {{ Illuminate\Support\Js::from(array_values($initialActions)) }};

            const conditionsRoot = document.getElementById('automationConditions');
            const actionsRoot = document.getElementById('automationActions');
            const conditionsEmpty = document.getElementById('automationConditionsEmpty');

            const escapeHtml = value => String(value ?? '')
                .replaceAll('&', '&amp;').replaceAll('<', '&lt;').replaceAll('>', '&gt;')
                .replaceAll('"', '&quot;').replaceAll("'", '&#039;');

            const optionsHtml = (options, selected) => Object.entries(options)
                .map(([value, label]) => `<option value="${escapeHtml(value)}" ${String(value) === String(selected ?? '') ? 'selected' : ''}>${escapeHtml(label)}</option>`)
                .join('');

            const renderConditions = () => {
                conditionsEmpty.classList.toggle('d-none', conditions.length > 0);
                conditionsRoot.innerHTML = conditions.map((condition, index) => {
                    const field = condition.field && definitions[condition.field] ? condition.field : Object.keys(definitions)[0];
                    const definition = definitions[field];
                    const operator = definition.operators.includes(condition.operator) ? condition.operator : definition.operators[0];
                    const valueControl = definition.type === 'select'
                        ? `<select class="form-select form-select-sm condition-value" name="conditions[${index}][value]">${optionsHtml(definition.options, condition.value)}</select>`
                        : `<input class="form-control form-control-sm condition-value" type="${definition.type === 'number' ? 'number' : 'text'}" step="${definition.type === 'number' ? '0.01' : ''}" name="conditions[${index}][value]" value="${escapeHtml(condition.value)}">`;

                    conditions[index] = {...condition, field, operator};

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
                    row.querySelector('.condition-value').addEventListener('change', event => conditions[index].value = event.target.value);
                    row.querySelector('.condition-value').addEventListener('input', event => conditions[index].value = event.target.value);
                    row.querySelector('.condition-remove').addEventListener('click', () => {
                        conditions.splice(index, 1);
                        renderConditions();
                    });
                });
            };

            const actionConfiguration = (action, index) => {
                const configuration = action.configuration || {};

                if (action.type === 'change_order_status') {
                    return `<label class="automation-block-label">STATUS DOCELOWY</label>
                        <select class="form-select form-select-sm action-config" data-key="status" name="actions[${index}][configuration][status]">${optionsHtml(statuses, configuration.status)}</select>`;
                }

                if (action.type === 'create_inpost_shipment') {
                    return `<div class="row g-2">
                        <div class="col-md-4"><label class="automation-block-label">US&#321;UGA</label><select class="form-select form-select-sm action-config" data-key="service" name="actions[${index}][configuration][service]">${optionsHtml(inPostServices, configuration.service || '')}</select></div>
                        <div class="col-md-3"><label class="automation-block-label">GABARYT</label><select class="form-select form-select-sm action-config" data-key="parcel_template" name="actions[${index}][configuration][parcel_template]">${optionsHtml(parcelTemplates, configuration.parcel_template || 'medium')}</select></div>
                        <div class="col-md-5"><label class="automation-block-label">PACZKOMAT ODBIORCZY (OPCJONALNIE)</label><input class="form-control form-control-sm action-config" data-key="target_point_id" name="actions[${index}][configuration][target_point_id]" value="${escapeHtml(configuration.target_point_id)}" placeholder="Domy&#347;lnie z zam&oacute;wienia"></div>
                    </div>`;
                }

                return `<label class="automation-block-label">CZAS W MINUTACH</label>
                    <input class="form-control form-control-sm action-config" data-key="minutes" type="number" min="1" max="86400" name="actions[${index}][configuration][minutes]" value="${escapeHtml(configuration.minutes || 1)}">`;
            };

            const renderActions = () => {
                actionsRoot.innerHTML = actions.map((action, index) => {
                    const type = action.type && actionLabels[action.type] ? action.type : Object.keys(actionLabels)[0];
                    actions[index] = {...action, type, configuration: action.configuration || {}, stop_on_error: action.stop_on_error !== false && String(action.stop_on_error) !== '0'};

                    return `<div class="automation-action-card" data-action-index="${index}">
                        <div class="automation-action-head">
                            <span class="automation-action-number">${index + 1}</span>
                            <div class="automation-action-choice">
                                <span class="automation-action-choice-dot"></span>
                                <select class="form-select form-select-sm action-type" name="actions[${index}][type]" aria-label="Wybierz rodzaj akcji">${optionsHtml(actionLabels, type)}</select>
                            </div>
                            <div class="automation-action-controls">
                                <button class="automation-icon-btn action-up" type="button" aria-label="Przesu&#324; wy&#380;ej" ${index === 0 ? 'disabled' : ''}>&#8593;</button>
                                <button class="automation-icon-btn action-down" type="button" aria-label="Przesu&#324; ni&#380;ej" ${index === actions.length - 1 ? 'disabled' : ''}>&#8595;</button>
                                <button class="automation-icon-btn action-remove" type="button" aria-label="Usu&#324; akcj&#281;" ${actions.length === 1 ? 'disabled' : ''}>&#10005;</button>
                            </div>
                        </div>
                        <div class="automation-action-config">
                            ${actionConfiguration(actions[index], index)}
                            <div class="form-check mt-2">
                                <input type="hidden" name="actions[${index}][stop_on_error]" value="0">
                                <input class="form-check-input action-stop" id="actionStop${index}" type="checkbox" name="actions[${index}][stop_on_error]" value="1" ${actions[index].stop_on_error ? 'checked' : ''}>
                                <label class="form-check-label small text-muted" for="actionStop${index}">Zatrzymaj regu&#322;&#281;, je&#347;li ta akcja si&#281; nie powiedzie</label>
                            </div>
                        </div>
                    </div>`;
                }).join('');

                actionsRoot.querySelectorAll('[data-action-index]').forEach(card => {
                    const index = Number(card.dataset.actionIndex);
                    card.querySelector('.action-type').addEventListener('change', event => {
                        const type = event.target.value;
                        const defaults = type === 'change_order_status'
                            ? {status: Object.keys(statuses)[0]}
                            : type === 'create_inpost_shipment' ? {service: '', parcel_template: 'medium', target_point_id: ''} : {minutes: 1};
                        actions[index] = {type, configuration: defaults, stop_on_error: actions[index].stop_on_error};
                        renderActions();
                    });
                    card.querySelectorAll('.action-config').forEach(control => {
                        const update = event => actions[index].configuration[event.target.dataset.key] = event.target.value;
                        control.addEventListener('change', update);
                        control.addEventListener('input', update);
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

            const moveAction = (index, direction) => {
                const target = index + direction;
                if (target < 0 || target >= actions.length) return;
                [actions[index], actions[target]] = [actions[target], actions[index]];
                renderActions();
            };

            document.getElementById('addAutomationCondition').addEventListener('click', () => {
                const field = Object.keys(definitions)[0];
                conditions.push({field, operator: definitions[field].operators[0], value: ''});
                renderConditions();
            });
            document.getElementById('addAutomationAction').addEventListener('click', () => {
                actions.push({type: 'change_order_status', configuration: {status: Object.keys(statuses)[0]}, stop_on_error: true});
                renderActions();
            });

            renderConditions();
            renderActions();
        })();
    </script>
@endsection
