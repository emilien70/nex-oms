@extends('layouts.app')

@section('title', 'Statusy zamowien - NEX-OMS')

@php
    $normalizeColor = fn (?string $color, string $fallback = '#f4ad42'): string => preg_match('/^#[0-9a-fA-F]{6}$/', (string) $color)
        ? strtolower((string) $color)
        : $fallback;
@endphp

@section('content')
    <style>
        .status-settings-page {
            background: #f4f6f8;
            margin: -1.5rem;
            min-height: 100vh;
            padding: 24px;
        }

        .status-card {
            background: #ffffff;
            border: 1px solid #dfe4ea;
            border-radius: 8px;
            box-shadow: 0 1px 3px rgba(15, 23, 42, .08);
            overflow: hidden;
        }

        .status-card-header {
            align-items: center;
            display: flex;
            justify-content: space-between;
            min-height: 60px;
            padding: 12px 16px 12px 24px;
        }

        .status-card-title {
            color: #111827;
            font-size: 16px;
            font-weight: 700;
            margin: 0;
        }

        .new-status-button {
            align-items: center;
            border-radius: 18px;
            display: inline-flex;
            font-weight: 700;
            gap: 6px;
            padding: 6px 14px 6px 8px;
        }

        .new-status-icon {
            align-items: center;
            border: 2px solid rgba(255, 255, 255, .9);
            border-radius: 50%;
            display: inline-flex;
            font-size: 20px;
            height: 28px;
            justify-content: center;
            line-height: 1;
            width: 28px;
        }

        .statuses-table {
            font-size: 13px;
            margin-bottom: 0;
        }

        .statuses-table thead th {
            background: #ffffff;
            border-bottom: 1px solid #d8dee7;
            color: #111827;
            font-size: 11px;
            font-weight: 700;
            padding: 14px 12px;
            text-transform: uppercase;
            white-space: nowrap;
        }

        .statuses-table tbody td {
            border-bottom: 1px solid #d8dee7;
            color: #374151;
            padding: 9px 12px;
            vertical-align: middle;
        }

        .statuses-table tbody tr:hover {
            background: #f8fafc;
        }

        .status-row.is-dragging {
            opacity: .45;
        }

        .status-row.is-drag-over {
            box-shadow: inset 0 2px 0 #0d6efd;
        }

        .status-drag-handle {
            align-items: center;
            background: transparent;
            border: 0;
            cursor: grab;
            display: inline-grid;
            gap: 3px 4px;
            grid-template-columns: repeat(2, 3px);
            grid-template-rows: repeat(3, 3px);
            height: 28px;
            justify-content: center;
            padding: 0;
            width: 22px;
        }

        .status-drag-handle:active {
            cursor: grabbing;
        }

        .status-drag-dot {
            background: #94a3b8;
            border-radius: 50%;
            display: block;
            height: 3px;
            width: 3px;
        }

        .status-star {
            color: #64748b;
            font-size: 18px;
            line-height: 1;
        }

        .status-dot {
            border-radius: 4px;
            display: inline-block;
            height: 10px;
            margin-right: 10px;
            width: 10px;
        }

        .status-action {
            align-items: center;
            background: #ffffff;
            border: 1px solid #eef2f7;
            border-radius: 50%;
            color: #475569;
            display: inline-flex;
            font-size: 16px;
            height: 34px;
            justify-content: center;
            line-height: 1;
            text-decoration: none;
            width: 34px;
        }

        .status-action:hover {
            background: #f8fafc;
            color: #0f172a;
        }

        .status-color-control {
            align-items: center;
            border: 1px solid #b8c4d4;
            border-radius: 6px;
            display: flex;
            height: 42px;
            overflow: hidden;
            width: 100%;
        }

        .status-color-native {
            background: #ffffff;
            border: 0;
            cursor: pointer;
            flex: 0 0 42px;
            height: 42px;
            padding: 4px;
            width: 42px;
        }

        .status-color-input {
            border: 0;
            border-left: 1px solid #e5e7eb;
            border-radius: 0;
            box-shadow: none;
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
            height: 42px;
        }

        .status-color-input:focus {
            box-shadow: none;
        }

        .status-delete-modal .modal-content {
            border: 0;
            border-radius: 8px;
        }

        .status-delete-warning {
            color: #f08c00;
            font-size: 46px;
            line-height: 1;
        }

        .status-delete-button {
            background: #f07c00;
            border-color: #f07c00;
            border-radius: 18px;
            color: #ffffff;
            font-weight: 700;
            min-width: 72px;
        }

        .status-delete-button:hover {
            background: #dc6f00;
            border-color: #dc6f00;
            color: #ffffff;
        }

        .replacement-status-dropdown {
            width: 100%;
        }

        .replacement-status-toggle {
            align-items: center;
            background: #ffffff;
            border-color: #cbd5e1;
            color: #111827;
            display: flex;
            font-size: 14px;
            gap: 8px;
            justify-content: flex-start;
            min-height: 38px;
            position: relative;
            text-align: left;
            width: 100%;
        }

        .replacement-status-toggle::after {
            margin-left: auto;
        }

        .replacement-status-menu {
            font-size: 14px;
            width: 100%;
        }

        .replacement-status-option {
            align-items: center;
            display: flex;
            gap: 8px;
        }

        .replacement-status-dot {
            border-radius: 4px;
            display: inline-block;
            flex: 0 0 auto;
            height: 12px;
            width: 12px;
        }
    </style>

    <div class="status-settings-page">
        <div class="status-card">
            <div class="status-card-header">
                <h1 class="status-card-title">Statusy zam&oacute;wie&#324;</h1>
                <button class="btn btn-success new-status-button" type="button" data-bs-toggle="modal" data-bs-target="#createStatusModal">
                    <span class="new-status-icon">+</span>
                    Nowy status
                </button>
            </div>

            <div class="table-responsive">
                <table class="table statuses-table align-middle">
                    <thead>
                        <tr>
                            <th style="width: 34px;"></th>
                            <th style="width: 34px;"></th>
                            <th>Nazwa</th>
                            <th>Opis statusu</th>
                            <th class="text-end" style="width: 120px;"></th>
                        </tr>
                    </thead>
                    <tbody data-status-sortable>
                        @foreach ($statuses as $status)
                            <tr class="status-row" data-status-row data-status="{{ $status['code'] }}">
                                <td class="text-center">
                                    <button class="status-drag-handle" type="button" draggable="true" aria-label="Zmien kolejnosc statusu {{ $status['name'] }}" title="Przeciagnij, aby zmienic kolejnosc">
                                        @for ($dot = 0; $dot < 6; $dot++)
                                            <span class="status-drag-dot"></span>
                                        @endfor
                                    </button>
                                </td>
                                <td class="text-center"><span class="status-star">&#9734;</span></td>
                                <td>
                                    <span class="status-dot" style="background: {{ $status['color'] }}"></span>
                                    {{ $status['name'] }}
                                </td>
                                <td>{{ $status['full_name'] ?: '...' }}</td>
                                <td class="text-end">
                                    <button class="status-action me-2" type="button" data-bs-toggle="modal" data-bs-target="#editStatusModal{{ $status['id'] }}" aria-label="Edytuj status {{ $status['name'] }}" title="Edytuj status">&#9998;</button>
                                    <button class="status-action" type="button" data-bs-toggle="modal" data-bs-target="#deleteStatusModal{{ $status['id'] }}" aria-label="Usun status {{ $status['name'] }}" title="Usun status">&times;</button>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <form id="statusOrderForm" method="POST" action="{{ route('settings.order-statuses.order') }}">
        @csrf
        @method('PATCH')
        <div data-status-order-inputs></div>
    </form>

    <div class="modal fade" id="createStatusModal" tabindex="-1" aria-labelledby="createStatusModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <form class="modal-content" method="POST" action="{{ route('settings.order-statuses.store') }}">
                @csrf
                <div class="modal-header">
                    <h2 class="modal-title fs-6" id="createStatusModalLabel">Nowy status</h2>
                    <button class="btn-close" type="button" data-bs-dismiss="modal" aria-label="Zamknij"></button>
                </div>
                <div class="modal-body">
                    @php
                        $createColor = $normalizeColor(old('color', '#f4ad42'));
                    @endphp
                    <div class="mb-3" data-status-color-field>
                        <label class="form-label" for="createStatusColorText">Kolor</label>
                        <div class="status-color-control">
                            <input id="createStatusColorNative" class="status-color-native" type="color" value="{{ $createColor }}" data-status-color-native aria-label="Wybierz kolor statusu">
                            <input id="createStatusColorText" class="form-control form-control-sm status-color-input" type="text" name="color" value="{{ $createColor }}" required pattern="^#[0-9a-fA-F]{6}$" data-status-color-input>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="createStatusName">Nazwa</label>
                        <input id="createStatusName" class="form-control form-control-sm" type="text" name="name" value="{{ old('name') }}" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="createStatusDescription">Opis statusu</label>
                        <input id="createStatusDescription" class="form-control form-control-sm" type="text" name="description" value="{{ old('description') }}" required>
                    </div>
                </div>
                <div class="modal-footer">
                    <button class="btn btn-sm btn-primary" type="submit">Zapisz</button>
                    <button class="btn btn-sm btn-outline-secondary" type="button" data-bs-dismiss="modal">Anuluj</button>
                </div>
            </form>
        </div>
    </div>

    @foreach ($statuses as $status)
        <div class="modal fade" id="editStatusModal{{ $status['id'] }}" tabindex="-1" aria-labelledby="editStatusModalLabel{{ $status['id'] }}" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <form class="modal-content" method="POST" action="{{ route('settings.order-statuses.update', $status['id']) }}">
                    @csrf
                    @method('PATCH')
                    <div class="modal-header">
                        <h2 class="modal-title fs-6" id="editStatusModalLabel{{ $status['id'] }}">Edycja statusu</h2>
                        <button class="btn-close" type="button" data-bs-dismiss="modal" aria-label="Zamknij"></button>
                    </div>
                    <div class="modal-body">
                        @php
                            $statusColor = $normalizeColor(old('color', $status['color']), $normalizeColor($status['color'] ?? null));
                        @endphp
                        <div class="mb-3" data-status-color-field>
                            <label class="form-label" for="statusColorText{{ $status['id'] }}">Kolor</label>
                            <div class="status-color-control">
                                <input id="statusColorNative{{ $status['id'] }}" class="status-color-native" type="color" value="{{ $statusColor }}" data-status-color-native aria-label="Wybierz kolor statusu">
                                <input id="statusColorText{{ $status['id'] }}" class="form-control form-control-sm status-color-input" type="text" name="color" value="{{ $statusColor }}" required pattern="^#[0-9a-fA-F]{6}$" data-status-color-input>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="statusName{{ $status['id'] }}">Nazwa</label>
                            <input id="statusName{{ $status['id'] }}" class="form-control form-control-sm" type="text" name="name" value="{{ old('name', $status['name']) }}" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="statusDescription{{ $status['id'] }}">Opis statusu</label>
                            <input id="statusDescription{{ $status['id'] }}" class="form-control form-control-sm" type="text" name="description" value="{{ old('description', $status['full_name']) }}">
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button class="btn btn-sm btn-primary" type="submit">Zapisz</button>
                        <button class="btn btn-sm btn-outline-secondary" type="button" data-bs-dismiss="modal">Anuluj</button>
                    </div>
                </form>
            </div>
        </div>

        <div class="modal fade status-delete-modal" id="deleteStatusModal{{ $status['id'] }}" tabindex="-1" aria-labelledby="deleteStatusModalLabel{{ $status['id'] }}" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <form class="modal-content" method="POST" action="{{ route('settings.order-statuses.destroy', $status['id']) }}">
                    @csrf
                    @method('DELETE')
                    <div class="modal-body text-center p-4">
                        <div class="status-delete-warning mb-3">&#9888;</div>
                        <h2 class="fs-5 mb-3" id="deleteStatusModalLabel{{ $status['id'] }}">Usuwanie statusu</h2>
                        <p class="mb-1">Czy na pewno chcesz usun&#261;&#263; status</p>
                        <p class="fw-semibold mb-4">&quot;{{ $status['name'] }}&quot;?</p>
                        <p class="text-secondary mb-4">Je&#380;eli status by&#322; wykorzystany w akcjach automatycznych, zostan&#261; one wy&#322;&#261;czone.</p>

                        @if ($status['orders_count'] > 0)
                            <div class="text-start mb-3">
                                <label class="form-label" for="replacementStatus{{ $status['id'] }}">Wybierz status do kt&oacute;rego maj&#261; zosta&#263; przeniesione zam&oacute;wienia z usuwanego statusu:</label>
                                @php
                                    $replacementOptions = collect($statuses)->filter(fn (array $option): bool => $option['code'] !== $status['code'])->values();
                                    $firstReplacement = $replacementOptions->first();
                                @endphp
                                <input id="replacementStatus{{ $status['id'] }}" type="hidden" name="replacement_status" value="{{ $firstReplacement['code'] ?? '' }}" required data-replacement-status-input>
                                <div class="dropdown replacement-status-dropdown">
                                    <button class="btn btn-light border dropdown-toggle replacement-status-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                                        @if ($firstReplacement)
                                            <span class="replacement-status-dot" data-replacement-status-dot style="background: {{ $firstReplacement['color'] }}"></span>
                                            <span data-replacement-status-label>{{ $firstReplacement['name'] }}</span>
                                        @else
                                            <span data-replacement-status-label>...</span>
                                        @endif
                                    </button>
                                    <ul class="dropdown-menu replacement-status-menu">
                                        @foreach ($replacementOptions as $replacementStatus)
                                            <li>
                                                <button class="dropdown-item replacement-status-option" type="button" data-replacement-status-option data-status="{{ $replacementStatus['code'] }}" data-status-label="{{ $replacementStatus['name'] }}" data-status-color="{{ $replacementStatus['color'] }}">
                                                    <span class="replacement-status-dot" style="background: {{ $replacementStatus['color'] }}"></span>
                                                    <span>{{ $replacementStatus['name'] }}</span>
                                                </button>
                                            </li>
                                        @endforeach
                                    </ul>
                                </div>
                                <select class="d-none" aria-hidden="true" tabindex="-1">
                                    @foreach ($statusOptions as $code => $label)
                                        @if ($code !== $status['code'])
                                            <option value="{{ $code }}">{{ $label }}</option>
                                        @endif
                                    @endforeach
                                </select>
                            </div>
                        @endif

                        <div class="d-flex justify-content-center gap-2 mt-3">
                            <button class="btn status-delete-button" type="submit">Usu&#324;</button>
                            <button class="btn btn-outline-secondary rounded-pill px-4" type="button" data-bs-dismiss="modal">Anuluj</button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    @endforeach

    <script>
        document.addEventListener('DOMContentLoaded', () => {
            document.querySelectorAll('[data-status-color-field]').forEach((field) => {
                const nativeInput = field.querySelector('[data-status-color-native]');
                const textInput = field.querySelector('[data-status-color-input]');

                if (!nativeInput || !textInput) {
                    return;
                }

                const normalizeColor = (value) => {
                    const trimmed = value.trim();
                    const prefixed = trimmed.startsWith('#') ? trimmed : `#${trimmed}`;

                    return /^#[0-9a-fA-F]{6}$/.test(prefixed) ? prefixed.toLowerCase() : null;
                };

                nativeInput.addEventListener('input', () => {
                    textInput.value = nativeInput.value.toLowerCase();
                });

                textInput.addEventListener('input', () => {
                    const color = normalizeColor(textInput.value);

                    if (color) {
                        nativeInput.value = color;
                    }
                });

                textInput.addEventListener('focus', () => nativeInput.click());
                textInput.addEventListener('click', () => nativeInput.click());

                textInput.closest('form')?.addEventListener('submit', () => {
                    const color = normalizeColor(textInput.value);

                    if (color) {
                        textInput.value = color;
                    }
                });
            });

            document.querySelectorAll('.replacement-status-dropdown').forEach((dropdown) => {
                const wrapper = dropdown.closest('.text-start');
                const input = wrapper?.querySelector('[data-replacement-status-input]');
                const label = dropdown.querySelector('[data-replacement-status-label]');
                const dot = dropdown.querySelector('[data-replacement-status-dot]');
                const options = dropdown.querySelectorAll('[data-replacement-status-option]');

                if (!input || !label || !dot) {
                    return;
                }

                options.forEach((option) => {
                    option.addEventListener('click', () => {
                        input.value = option.dataset.status || '';
                        label.textContent = option.dataset.statusLabel || option.textContent.trim();
                        dot.style.background = option.dataset.statusColor || '#64748b';
                    });
                });
            });

            const form = document.getElementById('statusOrderForm');
            const tbody = document.querySelector('[data-status-sortable]');
            const inputsWrapper = document.querySelector('[data-status-order-inputs]');

            if (!form || !tbody || !inputsWrapper) {
                return;
            }

            let draggedRow = null;
            let originalOrder = Array.from(tbody.querySelectorAll('[data-status-row]')).map((row) => row.dataset.status).join('|');

            const statusOrder = () => Array.from(tbody.querySelectorAll('[data-status-row]')).map((row) => row.dataset.status);

            const submitOrder = () => {
                inputsWrapper.innerHTML = '';

                statusOrder().forEach((status) => {
                    const input = document.createElement('input');
                    input.type = 'hidden';
                    input.name = 'statuses[]';
                    input.value = status;
                    inputsWrapper.appendChild(input);
                });

                form.requestSubmit();
            };

            const rowAfterPointer = (y) => {
                const rows = Array.from(tbody.querySelectorAll('[data-status-row]:not(.is-dragging)'));

                return rows.reduce((closest, row) => {
                    const box = row.getBoundingClientRect();
                    const offset = y - box.top - box.height / 2;

                    if (offset < 0 && offset > closest.offset) {
                        return { offset, row };
                    }

                    return closest;
                }, { offset: Number.NEGATIVE_INFINITY, row: null }).row;
            };

            tbody.querySelectorAll('.status-drag-handle').forEach((handle) => {
                handle.addEventListener('dragstart', (event) => {
                    draggedRow = handle.closest('[data-status-row]');

                    if (!draggedRow) {
                        return;
                    }

                    draggedRow.classList.add('is-dragging');
                    event.dataTransfer.effectAllowed = 'move';
                    event.dataTransfer.setData('text/plain', draggedRow.dataset.status);
                });

                handle.addEventListener('dragend', () => {
                    if (draggedRow) {
                        draggedRow.classList.remove('is-dragging');
                    }

                    tbody.querySelectorAll('.is-drag-over').forEach((row) => row.classList.remove('is-drag-over'));

                    if (draggedRow && statusOrder().join('|') !== originalOrder) {
                        submitOrder();
                    }

                    draggedRow = null;
                });
            });

            tbody.addEventListener('dragover', (event) => {
                if (!draggedRow) {
                    return;
                }

                event.preventDefault();

                const afterRow = rowAfterPointer(event.clientY);

                tbody.querySelectorAll('.is-drag-over').forEach((row) => row.classList.remove('is-drag-over'));

                if (afterRow) {
                    afterRow.classList.add('is-drag-over');
                    tbody.insertBefore(draggedRow, afterRow);
                } else {
                    tbody.appendChild(draggedRow);
                }
            });
        });
    </script>
@endsection
