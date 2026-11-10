@extends('layouts.app')

@section('title', 'Zdarzenia własne - NEX-OMS')

@section('content')
    @include('orders.partials.automation-custom-event-styles')
    <style>
        .custom-events-page { background: #f4f6f8; color: #263241; margin: -1.5rem; min-height: 100vh; padding: 20px; }
        .custom-events-table { background: #fff; font-size: 12.5px; table-layout: fixed; }
        .custom-events-table th { color: #465363; font-size: 10px; text-transform: uppercase; }
        .custom-events-table td { height: 42px; overflow-wrap: anywhere; vertical-align: middle; }
        .custom-events-table .automation-rule-grip { cursor: grab; touch-action: none; }
        .custom-events-table .automation-sort-dragging > td { opacity: .5; }
        .custom-events-table .automation-sort-before > td { box-shadow: inset 0 2px #2586d4; }
        .custom-events-table .automation-sort-after > td { box-shadow: inset 0 -2px #2586d4; }
        .custom-event-picker { position: relative; }
        .custom-event-picker summary { list-style: none; }
        .custom-event-picker summary::-webkit-details-marker { display: none; }
        .custom-event-options {
            background: #fff; border: 1px solid #cbd4df; border-radius: 5px; box-shadow: 0 4px 14px #0002;
            display: grid; gap: 4px; inset: 100% auto auto 0; padding: 8px; position: absolute; z-index: 2;
        }
        .custom-event-icon-options { grid-template-columns: repeat(6, 36px); }
        .custom-event-color-options { grid-template-columns: repeat(7, 28px); }
        .custom-event-choice, .custom-event-swatch { border: 1px solid #cbd4df; border-radius: 4px; }
        .custom-event-choice { background: #f7f8fa; color: #465363; height: 36px; }
        .custom-event-swatch { background: var(--event-color); display: inline-block; height: 28px; width: 28px; }
        .custom-event-swatch-small { height: 16px; vertical-align: middle; width: 16px; }
        .custom-event-options button:hover, .custom-event-options button[aria-pressed="true"] { box-shadow: inset 0 0 0 2px #1681db; }
        .custom-event-picker-row { display: grid; gap: 8px; grid-template-columns: auto auto minmax(0, 1fr); }
        .custom-event-states { display: grid; gap: 5px 12px; grid-template-columns: repeat(auto-fit, minmax(min(100%, 140px), 1fr)); }
        @media (max-width: 575px) {
            .custom-events-page { margin: -1rem; padding: 12px; }
            .custom-events-table { table-layout: auto; }
            .custom-event-icon-options { grid-template-columns: repeat(6, 31px); }
            .custom-event-color-options { grid-template-columns: repeat(7, 23px); }
            .custom-event-color-options button { width: 23px; }
            .custom-event-picker-row input { grid-column: 1 / -1; }
        }
    </style>

    <div class="custom-events-page" data-custom-events>
        @include('orders.partials.automation-tabs')
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
            <h1 class="fs-6 fw-bold m-0">Zdarzenia własne</h1>
            <button type="button" class="btn btn-sm btn-success rounded-pill" data-bs-toggle="modal" data-bs-target="#customEventModal">
                <i class="bi bi-plus-circle me-1" aria-hidden="true"></i> Dodaj zdarzenie własne
            </button>
        </div>
        <div class="alert alert-danger py-2" role="alert" data-custom-event-list-error hidden></div>
        <span class="visually-hidden" data-automation-order-feedback aria-live="polite"></span>
        <table class="table custom-events-table mb-0">
            <thead>
                <tr>
                    <th scope="col" style="width: 38px"><span class="visually-hidden">Kolejność</span></th>
                    <th scope="col">Nazwa zdarzenia</th>
                    <th scope="col">Przycisk</th>
                    <th scope="col" class="text-end" style="width: 64px">Akcje</th>
                </tr>
            </thead>
            <tbody @if ($events->count() > 1) data-automation-sort-list="{{ route('orders.custom-events.order') }}" data-automation-sort-entity="event" @endif>
                @forelse ($events as $event)
                    <tr data-automation-sort-row="{{ $event->id }}"
                        data-name="{{ $event->name }}" data-description="{{ $event->description }}"
                        data-button-enabled="{{ (int) $event->button_enabled }}" data-button-label="{{ $event->button_label }}"
                        data-icon="{{ $event->icon }}" data-color="{{ $event->color }}"
                        data-confirmation="{{ (int) $event->confirmation_required }}" data-statuses="{{ json_encode($event->order_statuses ?? []) }}">
                        <td>
                            @if ($events->count() > 1)
                                <button type="button" class="btn btn-sm p-0 automation-rule-grip text-secondary" data-automation-sort-handle
                                    title="Przeciągnij lub użyj strzałek góra/dół" aria-label="Zmień kolejność: {{ $event->name }}">
                                    <i class="bi bi-grip-vertical" aria-hidden="true"></i>
                                </button>
                            @endif
                        </td>
                        <td>
                            <div>{{ $event->name }}</div>
                            @if ($event->description)<div class="small text-secondary">{{ $event->description }}</div>@endif
                        </td>
                        <td>
                            @if ($event->button_enabled)
                                @include('orders.partials.automation-custom-event-badge')
                            @else
                                <span class="text-secondary">Wyłączony</span>
                            @endif
                        </td>
                        <td class="text-end">
                            <div class="dropdown">
                                <button class="btn btn-sm btn-light border rounded-circle" type="button" data-bs-toggle="dropdown" aria-expanded="false"
                                    title="Akcje zdarzenia" aria-label="Akcje zdarzenia: {{ $event->name }}"><i class="bi bi-three-dots-vertical" aria-hidden="true"></i></button>
                                <ul class="dropdown-menu dropdown-menu-end">
                                    <li><button class="dropdown-item" type="button" data-bs-toggle="modal" data-bs-target="#customEventModal"
                                        data-custom-event-update="{{ route('orders.custom-events.update', $event) }}">Edytuj</button></li>
                                    <li><button class="dropdown-item" type="button" data-bs-toggle="modal" data-bs-target="#customEventModal"
                                        data-custom-event-duplicate>Duplikuj</button></li>
                                    <li><hr class="dropdown-divider"></li>
                                    <li><button class="dropdown-item text-danger" type="button" data-custom-event-delete="{{ route('orders.custom-events.destroy', $event) }}">Usuń</button></li>
                                </ul>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="text-center text-secondary p-4">Brak zdarzeń własnych.</td></tr>
                @endforelse
            </tbody>
        </table>

        <div class="modal fade" id="customEventModal" tabindex="-1" aria-labelledby="customEventModalTitle" aria-hidden="true">
            <div class="modal-dialog modal-lg modal-dialog-scrollable">
                <form class="modal-content" method="POST" action="{{ route('orders.custom-events.store') }}" data-custom-event-form
                    data-default-icon="{{ \Modules\Automation\Services\AutomationCustomEventAppearance::DEFAULT_ICON }}"
                    data-default-color="{{ \Modules\Automation\Services\AutomationCustomEventAppearance::DEFAULT_COLOR }}">
                    <div class="modal-header">
                        <h2 class="modal-title fs-6" id="customEventModalTitle">Ustawienia zdarzenia własnego</h2>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Zamknij"></button>
                    </div>
                    <div class="modal-body small">
                        <div class="alert alert-danger py-2" role="alert" data-custom-event-error hidden></div>
                        <div class="row align-items-center g-2 mb-3">
                            <label for="customEventName" class="col-sm-3 col-form-label text-sm-end">Nazwa zdarzenia</label>
                            <div class="col-sm-9"><input class="form-control form-control-sm" id="customEventName" name="name" maxlength="120" required></div>
                            <label for="customEventDescription" class="col-sm-3 col-form-label text-sm-end">Opis zdarzenia</label>
                            <div class="col-sm-9"><textarea class="form-control form-control-sm" id="customEventDescription" name="description" rows="2" maxlength="1000"></textarea></div>
                        </div>
                        <div class="row align-items-start g-2 border-top pt-3">
                            <div class="col-sm-3 text-sm-end">Włącz przycisk</div>
                            <div class="col-sm-9">
                                <label class="form-check">
                                    <input type="checkbox" class="form-check-input" name="button_enabled">
                                    <span class="form-check-label">Uruchamiaj akcję poprzez przycisk na karcie zamówienia</span>
                                </label>
                            </div>
                        </div>
                        <div data-custom-event-button-fields hidden>
                            <div class="row align-items-center g-2 mb-3">
                                <label class="col-sm-3 text-sm-end" for="customEventButtonLabel">Przycisk</label>
                                <div class="col-sm-9 custom-event-picker-row">
                                    <details class="custom-event-picker">
                                        <summary class="btn btn-sm btn-light border" title="Ikona przycisku" aria-label="Ikona przycisku">
                                            <i class="bi" data-selected-event-icon aria-hidden="true"></i> <i class="bi bi-chevron-down" aria-hidden="true"></i>
                                        </summary>
                                        <div class="custom-event-options custom-event-icon-options">
                                            @foreach ($icons as $icon => $label)
                                                <button class="custom-event-choice" type="button" data-event-icon="{{ $icon }}" title="{{ $label }}" aria-label="{{ $label }}" aria-pressed="false">
                                                    <i class="bi bi-{{ $icon }}" aria-hidden="true"></i>
                                                </button>
                                            @endforeach
                                        </div>
                                    </details>
                                    <details class="custom-event-picker">
                                        <summary class="btn btn-sm btn-light border" title="Kolor przycisku" aria-label="Kolor przycisku">
                                            <span class="custom-event-swatch custom-event-swatch-small" data-selected-event-color></span>
                                            <i class="bi bi-chevron-down" aria-hidden="true"></i>
                                        </summary>
                                        <div class="custom-event-options custom-event-color-options">
                                            @foreach ($colors as $color)
                                                <button class="custom-event-swatch" type="button" data-event-color="{{ $color }}" title="{{ $color }}" aria-label="Kolor {{ $color }}"
                                                    aria-pressed="false" style="--event-color: {{ $color }}"></button>
                                            @endforeach
                                        </div>
                                    </details>
                                    <input class="form-control form-control-sm" id="customEventButtonLabel" name="button_label" maxlength="80" placeholder="Nazwa wyświetlana przycisku">
                                </div>
                                <div class="col-sm-3 text-sm-end">Wizualizacja przycisku</div>
                                <div class="col-sm-9">
                                    <span class="custom-event-badge">
                                        <span class="custom-event-symbol" data-event-preview-symbol><i class="bi" data-event-preview-icon aria-hidden="true"></i></span>
                                        <span class="custom-event-label" data-event-preview-label></span>
                                    </span>
                                </div>
                            </div>
                            <div class="row align-items-start g-2 mb-2">
                                <div class="col-sm-3 text-sm-end" id="customEventStatuses">Statusy zamówienia</div>
                                <div class="col-sm-9">
                                    <div class="custom-event-states" role="group" aria-labelledby="customEventStatuses">
                                        @foreach ($statuses as $code => $label)
                                            <label class="form-check">
                                                <input type="checkbox" class="form-check-input" name="order_statuses" value="{{ $code }}">
                                                <span class="form-check-label">{{ $label }}</span>
                                            </label>
                                        @endforeach
                                    </div>
                                    <div class="form-text">Bez wyboru: wszystkie statusy.</div>
                                </div>
                            </div>
                            <div class="row g-2">
                                <div class="col-sm-9 offset-sm-3">
                                    <label class="form-check">
                                        <input type="checkbox" class="form-check-input" name="confirmation_required">
                                        <span class="form-check-label">Wymagaj potwierdzenia przed uruchomieniem</span>
                                    </label>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-sm btn-light border rounded-pill" data-bs-dismiss="modal">Anuluj</button>
                        <button type="submit" class="btn btn-sm btn-primary rounded-pill">Zapisz</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    @if ($events->count() > 1)
        @include('orders.partials.automation-rule-order-script')
    @endif
    <script src="{{ asset('js/automation-custom-events.js') }}" defer></script>
@endsection
