@php
    $columns = \Modules\Automation\Models\AutomationCustomEvent::GRID_COLUMNS;
    $maxPositions = \Modules\Automation\Models\AutomationCustomEvent::MAX_POSITIONS;
    $byPosition = $quickActions->keyBy('button_position');
    $visibleActions = $byPosition->filter(fn ($event) => $event->availableFor($order));
    $viewLimit = (int) $visibleActions->keys()->max();
    $editLimit = min($maxPositions, ((int) ceil($byPosition->keys()->max() / $columns) + 1) * $columns);
@endphp
@include('orders.partials.automation-custom-event-styles')
<style>
    .quick-actions-body { container-type: inline-size; }
    .quick-actions-grid { display: grid; grid-template-columns: repeat(5, minmax(0, 1fr)); gap: 6px; }
    .quick-actions-grid > * { min-width: 0; }
    .quick-action-run { background: transparent; border: 0; padding: 0; justify-self: start; }
    .quick-action-run:hover .custom-event-badge { border-color: #8eb6dc; background: #f3f8fe; }
    .quick-action-run:disabled { opacity: .6; cursor: wait; }
    .quick-action-slot {
        background: #f7f8fa; border: 1px dashed #b8c5d2; border-radius: 5px; min-height: 42px;
        padding: 3px; text-align: left; touch-action: none; user-select: none;
    }
    .quick-action-slot[data-occupied] { cursor: grab; }
    .quick-action-slot[aria-pressed="true"], .quick-action-slot.is-drop-target { background: #e9f4fe; border-color: #0077da; }
    .quick-action-slot [data-unavailable] { opacity: .5; }
    .quick-actions-body [hidden] { display: none !important; }
    @container (max-width: 450px) {
        .quick-actions-grid { grid-template-columns: repeat(3, minmax(0, 1fr)); }
    }
    @container (max-width: 335px) {
        .quick-actions-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    }
    @media (max-width: 575px) {
        .quick-actions-grid > .quick-action-gap { display: none; }
    }
</style>
<div class="nex-card h-100" data-order-quick-actions data-layout-url="{{ route('orders.custom-events.layout') }}">
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
                <div class="quick-actions-grid" data-quick-action-view-grid>
                    @for ($position = 1; $position <= $byPosition->keys()->max(); $position++)
                        @php($available = $visibleActions->has($position))
                        <div @class(['quick-action-gap' => ! $available]) data-quick-action-cell @if ($position > $viewLimit) hidden @endif>
                            @if ($event = $byPosition->get($position))
                                <button class="quick-action-run" type="button" data-quick-action-run="{{ route('orders.quick-actions.store', ['order' => $order, 'customEvent' => $event]) }}"
                                    data-action-label="{{ $event->buttonLabel() }}" data-action-statuses="{{ json_encode($event->order_statuses ?? []) }}"
                                    @if ($event->confirmation_required) data-requires-confirmation @endif @unless ($available) hidden @endunless>
                                    @include('orders.partials.automation-custom-event-badge')
                                </button>
                            @endif
                        </div>
                    @endfor
                </div>
            @endif
            <div class="text-secondary small" data-no-quick-actions @if ($viewLimit > 0) hidden @endif>Brak dostępnych szybkich akcji.</div>
        </div>
        @if ($quickActions->isNotEmpty())
            <div id="quick-action-layout" data-quick-action-layout hidden>
                <div class="quick-actions-grid" data-quick-action-slots>
                    @for ($position = 1; $position <= $editLimit; $position++)
                        @php($event = $byPosition->get($position))
                        <button type="button" class="quick-action-slot" data-quick-action-slot="{{ $position }}" aria-pressed="false"
                            aria-label="Pole {{ $position }}{{ $event ? ': '.$event->buttonLabel() : ', puste' }}" @if ($event) data-occupied @endif>
                            @if ($event)
                                <span data-quick-action-item="{{ $event->id }}" data-action-label="{{ $event->buttonLabel() }}" data-action-statuses="{{ json_encode($event->order_statuses ?? []) }}"
                                    @unless ($visibleActions->has($position)) data-unavailable @endunless>
                                    @include('orders.partials.automation-custom-event-badge')
                                </span>
                            @endif
                        </button>
                    @endfor
                </div>
                <div class="d-flex flex-wrap gap-2 justify-content-end mt-3">
                    <button type="button" class="btn btn-sm btn-light border" data-quick-action-cancel>Anuluj</button>
                    <button type="button" class="btn btn-sm btn-primary" data-quick-action-save>Zapisz</button>
                </div>
            </div>
        @endif
        <div class="small mt-2" data-quick-action-message aria-live="polite" hidden></div>
    </div>
</div>
@if ($quickActions->isNotEmpty())
    <script src="{{ asset('js/order-quick-actions.js') }}" defer></script>
@endif
