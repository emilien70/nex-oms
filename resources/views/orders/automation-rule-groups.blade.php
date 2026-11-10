@extends('layouts.app')

@section('title', 'Grupy akcji - NEX-OMS')

@section('content')
    <style>
        .automation-groups-page { background: #f4f6f8; color: #263241; margin: -1.5rem; min-height: 100vh; padding: 20px; }
        .automation-groups-table { background: #fff; font-size: 12.5px; table-layout: fixed; }
        .automation-groups-table th { color: #465363; font-size: 10px; text-transform: uppercase; }
        .automation-groups-table td { height: 42px; overflow-wrap: anywhere; vertical-align: middle; }
        .automation-groups-table .automation-rule-grip { cursor: grab; touch-action: none; }
        .automation-groups-table .automation-sort-dragging > td { opacity: .5; }
        .automation-groups-table .automation-sort-before > td { box-shadow: inset 0 2px #2586d4; }
        .automation-groups-table .automation-sort-after > td { box-shadow: inset 0 -2px #2586d4; }
        @media (max-width: 575px) {
            .automation-groups-page { margin: -1rem; padding: 12px; }
        }
    </style>

    <div class="automation-groups-page" data-automation-groups>
        @include('orders.partials.automation-tabs')
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
            <h1 class="fs-6 fw-bold m-0">Grupy akcji</h1>
            <button type="button" class="btn btn-sm btn-success rounded-pill" data-bs-toggle="modal" data-bs-target="#automationGroupModal">
                <i class="bi bi-plus-circle me-1" aria-hidden="true"></i> Nowa grupa akcji
            </button>
        </div>
        <div class="alert alert-danger py-2" role="alert" data-group-list-error hidden></div>
        <div class="visually-hidden" role="status" data-automation-order-feedback></div>
        <table class="table automation-groups-table mb-0">
            <thead>
                <tr>
                    <th scope="col" style="width: 38px"><span class="visually-hidden">Kolejność</span></th>
                    <th scope="col">Nazwa</th>
                    <th scope="col" class="text-end" style="width: 102px">Akcje</th>
                </tr>
            </thead>
            <tbody @if ($groups->count() > 1) data-automation-sort-list="{{ route('orders.automatic-action-groups.order') }}"
                data-automation-sort-entity="group" data-automation-sort-title="Grupy akcji" @endif>
                @forelse ($groups as $group)
                    <tr data-automation-sort-row="{{ $group->id }}">
                        <td>
                            @if ($groups->count() > 1)
                                <button type="button" class="btn btn-sm p-0 automation-rule-grip text-secondary" data-automation-sort-handle
                                    title="Przeciągnij lub użyj strzałek góra/dół" aria-label="Zmień kolejność: {{ $group->name }}">
                                    <i class="bi bi-grip-vertical" aria-hidden="true"></i>
                                </button>
                            @endif
                        </td>
                        <td>{{ $group->name }}</td>
                        <td class="text-end">
                            <button type="button" class="btn btn-sm btn-light border rounded-circle" title="Edytuj grupę" aria-label="Edytuj: {{ $group->name }}"
                                data-bs-toggle="modal" data-bs-target="#automationGroupModal"
                                data-group-update="{{ route('orders.automatic-action-groups.update', $group) }}" data-group-name="{{ $group->name }}">
                                <i class="bi bi-pencil" aria-hidden="true"></i>
                            </button>
                            <button type="button" class="btn btn-sm btn-light border rounded-circle" title="Usuń grupę" aria-label="Usuń: {{ $group->name }}"
                                data-group-delete="{{ route('orders.automatic-action-groups.destroy', $group) }}"
                                data-group-name="{{ $group->name }}" data-group-rule-count="{{ $group->rules_count }}">
                                <i class="bi bi-trash" aria-hidden="true"></i>
                            </button>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="3" class="text-center text-secondary p-4">Brak grup akcji.</td></tr>
                @endforelse
            </tbody>
        </table>

        <div class="modal fade" id="automationGroupModal" tabindex="-1" aria-labelledby="automationGroupTitle" aria-hidden="true">
            <div class="modal-dialog modal-lg">
                <form class="modal-content" method="POST" action="{{ route('orders.automatic-action-groups.store') }}" data-group-form>
                    @csrf
                    <div class="modal-header">
                        <h2 class="modal-title fs-6" id="automationGroupTitle">Grupa akcji automatycznych</h2>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Zamknij"></button>
                    </div>
                    <div class="modal-body">
                        <div class="alert alert-danger py-2" role="alert" data-group-form-error hidden></div>
                        <div class="row align-items-center g-2">
                            <label class="col-sm-3 col-form-label small text-sm-end" for="automationGroupName">Nazwa grupy</label>
                            <div class="col-sm-9">
                                <input type="text" class="form-control form-control-sm" name="name" id="automationGroupName" maxlength="50" required>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="submit" class="btn btn-sm btn-primary rounded-pill">Zapisz</button>
                        <button type="button" class="btn btn-sm btn-light border rounded-pill" data-bs-dismiss="modal">Zamknij</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    @if ($groups->count() > 1)
        @include('orders.partials.automation-rule-order-script')
    @endif
    <script src="{{ asset('js/automation-rule-groups.js') }}" defer></script>
@endsection
