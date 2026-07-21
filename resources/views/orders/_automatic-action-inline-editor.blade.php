@php
    $isDraft = $isDraft ?? false;
    $editorKey = $isDraft ? 'draft' : (string) $rule->id;
    $editorActions = $isDraft
        ? [[
            'type' => \Modules\Automation\Services\AutomationCatalog::ACTION_CHANGE_STATUS,
            'configuration' => ['status' => array_key_first($statuses)],
            'stop_on_error' => true,
        ]]
        : $rule->actions->map(fn ($action) => [
            'type' => $action->action_type,
            'configuration' => $action->configuration ?? [],
            'stop_on_error' => $action->stop_on_error,
        ])->values()->all();
    $editorState = [
        'conditions' => $isDraft ? [] : array_values($rule->conditions ?? []),
        'actions' => $editorActions,
    ];
    $editorOpen = ! $isDraft && (int) request('edit') === $rule->id;
    $selectedTrigger = $isDraft ? array_key_first($catalog->triggers()) : $rule->trigger;
@endphp

<tr class="automation-inline-row {{ $editorOpen ? '' : 'd-none' }}" data-automation-editor-row="{{ $editorKey }}">
    <td colspan="4">
        <form method="POST"
              action="{{ $isDraft ? route('orders.automatic-actions.store') : route('orders.automatic-actions.update', $rule) }}"
              class="automation-inline-form"
              data-automation-editor
              data-rule-id="{{ $editorKey }}"
              data-is-draft="{{ $isDraft ? 1 : 0 }}">
            @csrf
            @unless ($isDraft)
                @method('PUT')
            @endunless
            <script type="application/json" data-editor-state>@json($editorState)</script>

            <div class="automation-inline-grid">
                <section class="automation-inline-section">
                    <label class="automation-block-label" for="automationTrigger{{ $editorKey }}">ZDARZENIE</label>
                    <div class="automation-event-box">
                        <span class="automation-event-dot"></span>
                        <select class="form-select form-select-sm" id="automationTrigger{{ $editorKey }}" name="trigger" required>
                            @foreach ($catalog->triggers() as $trigger => $label)
                                <option value="{{ $trigger }}" @selected($selectedTrigger === $trigger)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div class="automation-section-separator"></div>
                    <div class="d-flex align-items-center justify-content-between mb-2">
                        <div>
                            <span class="automation-block-label mb-0">WARUNKI</span>
                            <small class="text-muted">Wszystkie musz&#261; by&#263; spe&#322;nione</small>
                        </div>
                        <button class="btn btn-sm btn-light border" type="button" data-add-condition>+ Dodaj warunek</button>
                    </div>
                    <div data-conditions-root></div>
                    <div class="text-muted small" data-conditions-empty>Brak dodatkowych warunk&oacute;w.</div>
                    <div class="automation-rule-id">{{ $isDraft ? 'ID: zostanie nadane po zapisie' : 'ID: '.$rule->id }}</div>
                </section>

                <section class="automation-inline-section">
                    <span class="automation-block-label">AKCJE DO WYKONANIA</span>
                    <div data-actions-root></div>
                    <button class="btn btn-sm btn-light automation-add-button" type="button" data-add-action>+ Dodaj kolejn&#261; akcj&#281;</button>
                </section>

                <aside class="automation-inline-settings">
                    <div class="automation-inline-setting">
                        <div class="form-check form-switch mb-0">
                            <input type="hidden" name="is_active" value="0">
                            <input class="form-check-input" id="automationActive{{ $editorKey }}" name="is_active" type="checkbox" value="1" @checked(! $isDraft && $rule->is_active)>
                            <label class="form-check-label small" for="automationActive{{ $editorKey }}">Regu&#322;a aktywna</label>
                        </div>
                    </div>
                    <div class="automation-inline-setting">
                        <label class="form-label small mb-1" for="automationName{{ $editorKey }}">Nazwa opcjonalna</label>
                        <input class="form-control form-control-sm" id="automationName{{ $editorKey }}" name="name" value="{{ $isDraft ? '' : $rule->name }}" maxlength="255" placeholder="Generowana automatycznie">
                    </div>
                    <div class="automation-inline-setting">
                        <label class="form-label small mb-1" for="automationDescription{{ $editorKey }}">Opis opcjonalny</label>
                        <textarea class="form-control form-control-sm" id="automationDescription{{ $editorKey }}" name="description" rows="3" maxlength="1000">{{ $isDraft ? '' : $rule->description }}</textarea>
                    </div>
                    <div class="automation-inline-error d-none" data-editor-error></div>
                    <div class="automation-inline-setting automation-inline-buttons">
                        <button class="btn btn-sm btn-primary" type="submit" data-save-editor>Zapisz</button>
                        <button class="btn btn-sm btn-light border" type="button" data-cancel-editor>Anuluj</button>
                    </div>
                </aside>
            </div>
        </form>
    </td>
</tr>
