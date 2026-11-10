@php
    $visibleActions = $quickActions->filter(fn ($event) => $event->availableFor($order))->keyBy('id');
@endphp
@include('orders.partials.automation-custom-event-styles')
<style>
    .quick-actions-list { display: flex; flex-wrap: wrap; align-items: flex-start; column-gap: 6px; }
    .quick-actions-list > button { margin-block-end: 6px; }
    .quick-action-line-break { flex: 0 0 100%; height: 0; pointer-events: none; }
    .quick-actions-list .custom-event-badge {
        display: grid; grid-template-columns: 26px minmax(0, max-content);
        min-height: 36px; gap: 6px; padding: 3px 10px 3px 4px; font-size: 13px;
    }
    .quick-actions-list .custom-event-symbol { width: 26px; height: 26px; }
    .quick-actions-list .custom-event-label { overflow-wrap: break-word; }
    .quick-action-run, .quick-action-reorder {
        display: flex; flex: 0 0 auto; width: max-content; min-width: 0; max-width: 100%;
        background: transparent; border: 0; padding: 0;
    }
    .quick-action-run:focus-visible, .quick-action-reorder:focus-visible {
        outline: 2px solid #2586d4; outline-offset: 2px; border-radius: 999px;
    }
    .quick-action-run:hover .custom-event-badge { border-color: #8eb6dc; background: #f3f8fe; }
    .quick-action-run:disabled { opacity: .6; cursor: wait; }
    .quick-action-run .quick-action-spinner { display: none; width: 12px; height: 12px; border-width: 2px; }
    .quick-action-run[aria-busy="true"] .quick-action-spinner { display: inline-block; }
    .quick-action-run[aria-busy="true"] .custom-event-symbol > i { display: none; }
    .quick-action-reorder { position: relative; cursor: grab; touch-action: none; user-select: none; }
    .quick-action-reorder .custom-event-badge { border-style: dashed; }
    .quick-action-reorder[aria-pressed="true"] .custom-event-badge { background: #e9f4fe; border-color: #0077da; }
    .quick-action-reorder[data-unavailable], .quick-action-reorder.is-dragging { opacity: .5; }
    .quick-action-reorder.is-drop-before::before, .quick-action-reorder.is-drop-after::after {
        content: ""; position: absolute; inset-block: 3px; width: 2px; border-radius: 2px; background: #0077da;
    }
    .quick-action-reorder.is-drop-before::before { inset-inline-start: -4px; }
    .quick-action-reorder.is-drop-after::after { inset-inline-end: -4px; }
    .quick-actions-body [hidden] { display: none !important; }
</style>
<div class="nex-card h-100" data-order-quick-actions data-layout-url="{{ route('orders.custom-events.layout') }}"
    data-max-columns="{{ $quickActionMaxColumns }}">
    <div class="nex-card-header">
        <h2 class="nex-card-title">Szybkie akcje</h2>
        @if ($quickActions->isNotEmpty())
            <div class="card-header-actions">
                <button class="btn btn-sm btn-light border icon-action-button" type="button" data-quick-action-edit
                    title="Edytuj układ szybkich akcji" aria-label="Edytuj układ szybkich akcji"
                    aria-controls="quick-action-layout" aria-expanded="false"><i class="bi bi-pencil" aria-hidden="true"></i></button>
            </div>
        @endif
    </div>
    <div class="nex-card-body quick-actions-body">
        <div data-quick-action-view>
            @if ($quickActions->isNotEmpty())
                <div class="quick-actions-list" data-quick-action-runs>
                    @foreach ($quickActions as $event)
                        <button class="quick-action-run" type="button" data-quick-action-run="{{ route('orders.quick-actions.store', ['order' => $order, 'customEvent' => $event]) }}"
                            title="{{ $event->buttonLabel() }}" data-action-label="{{ $event->buttonLabel() }}" data-action-statuses="{{ json_encode($event->order_statuses ?? []) }}"
                            @if ($event->confirmation_required) data-requires-confirmation @endif @unless ($visibleActions->has($event->id)) hidden @endunless>
                            @include('orders.partials.automation-custom-event-badge', ['withSpinner' => true])
                        </button>
                    @endforeach
                </div>
            @endif
            <div class="text-secondary small" data-no-quick-actions @if ($visibleActions->isNotEmpty()) hidden @endif>Brak dostępnych szybkich akcji.</div>
        </div>
        @if ($quickActions->isNotEmpty())
            <div id="quick-action-layout" data-quick-action-layout hidden>
                <fieldset class="d-flex flex-wrap align-items-center gap-2 mb-2" data-quick-action-columns aria-labelledby="quick-action-columns-label">
                    <span class="small" id="quick-action-columns-label">Maks. liczba kolumn</span>
                    <div class="btn-group btn-group-sm">
                        @for ($columns = 1; $columns <= \Modules\Automation\Services\AutomationCustomEventSettings::MAX_COLUMNS; $columns++)
                            <input class="btn-check" type="radio" name="quick_action_max_columns" id="quick-action-columns-{{ $columns }}"
                                value="{{ $columns }}" @checked($columns === $quickActionMaxColumns)>
                            <label class="btn btn-outline-secondary" for="quick-action-columns-{{ $columns }}">{{ $columns }}&times;</label>
                        @endfor
                    </div>
                </fieldset>
                <div class="quick-actions-list" data-quick-action-items>
                    @foreach ($quickActions as $event)
                        <button type="button" class="quick-action-reorder" data-quick-action-item="{{ $event->id }}" aria-pressed="false"
                            data-action-position="{{ $event->button_position }}" data-action-statuses="{{ json_encode($event->order_statuses ?? []) }}"
                            title="{{ $event->buttonLabel() }}" @unless ($visibleActions->has($event->id)) data-unavailable @endunless>
                            @include('orders.partials.automation-custom-event-badge')
                        </button>
                    @endforeach
                </div>
                <div class="d-flex flex-wrap gap-2 justify-content-end mt-2">
                    <button type="button" class="btn btn-sm btn-light border" data-quick-action-cancel>Anuluj</button>
                    <button type="button" class="btn btn-sm btn-primary" data-quick-action-save>Zapisz</button>
                </div>
                <div class="small text-danger mt-2" data-quick-action-layout-error role="alert" hidden></div>
            </div>
            <span class="visually-hidden" data-quick-action-status role="status"></span>
        @endif
    </div>
</div>
@if ($quickActions->isNotEmpty())
    <script src="{{ asset('js/order-quick-actions.js') }}?v=4" defer></script>
@endif
