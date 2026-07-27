@extends('layouts.app')

@section('title', 'Serie numeracji - NEX-OMS')

@section('content')
    <style>
        .invoice-series-page {
            background: #f4f6f8;
            margin: -1.5rem;
            min-height: 100vh;
            padding: 24px;
        }

        .invoice-series-info {
            background: #ffffff;
            border: 1px solid #dfe4ea;
            border-left: 3px solid #0d6efd;
            border-radius: 7px;
            color: #4e565f;
            font-size: 13px;
            margin-bottom: 16px;
            padding: 13px 16px;
        }

        .invoice-series-panel {
            background: #ffffff;
            border: 1px solid #dfe4ea;
            border-radius: 8px;
            box-shadow: 0 1px 3px rgba(15, 23, 42, .06);
            overflow: visible;
        }

        .invoice-series-header {
            align-items: center;
            border-bottom: 1px solid #e5e7eb;
            display: flex;
            gap: 16px;
            justify-content: space-between;
            min-height: 58px;
            padding: 12px 16px;
        }

        .invoice-series-title {
            color: #111827;
            font-size: 17px;
            font-weight: 700;
            margin: 0;
        }

        .invoice-series-new {
            align-items: center;
            display: inline-flex;
            gap: 6px;
        }

        .invoice-series-table-wrap {
            overflow-x: auto;
        }

        .invoice-series-table {
            font-size: 13px;
            margin: 0;
            min-width: 760px;
        }

        .invoice-series-table th {
            background: #f8fafc;
            border-bottom-color: #dfe4ea;
            color: #4e565f;
            font-size: 11px;
            font-weight: 600;
            padding: 11px 14px;
            text-transform: uppercase;
        }

        .invoice-series-table td {
            border-bottom-color: #e8edf2;
            color: #4e565f;
            padding: 11px 14px;
            vertical-align: middle;
        }

        .invoice-series-table tbody tr:last-child td {
            border-bottom: 0;
        }

        .invoice-series-name {
            align-items: center;
            color: #1f2937;
            display: inline-flex;
            gap: 7px;
            font-weight: 500;
        }

        .invoice-system-star {
            color: #64748b;
            cursor: default;
            display: inline-flex;
            font-size: 14px;
        }

        .invoice-series-action,
        .invoice-series-action-disabled {
            align-items: center;
            border: 1px solid #dfe4ea;
            border-radius: 50%;
            display: inline-flex;
            height: 30px;
            justify-content: center;
            padding: 0;
            width: 30px;
        }

        .invoice-series-action {
            background: #ffffff;
            color: #4e565f;
        }

        .invoice-series-action:hover {
            background: #f8fafc;
            border-color: #bfc8d4;
            color: #0d6efd;
        }

        .invoice-series-action-disabled {
            background: #f8fafc;
            color: #b4bdc8;
            cursor: default;
        }

        .invoice-series-footer {
            align-items: center;
            border-top: 1px solid #e5e7eb;
            display: flex;
            gap: 16px;
            justify-content: space-between;
            min-height: 54px;
            padding: 10px 14px;
        }

        .invoice-series-count {
            color: #6b7280;
            font-size: 12px;
        }

        .invoice-series-empty {
            color: #6b7280;
            font-size: 13px;
            padding: 32px 16px;
            text-align: center;
        }

        @media (max-width: 767.98px) {
            .invoice-series-page {
                margin: -1rem;
                padding: 14px;
            }

            .invoice-series-header {
                align-items: flex-start;
                flex-direction: column;
            }

            .invoice-series-footer {
                align-items: flex-start;
                flex-direction: column;
            }
        }
    </style>

    <div class="invoice-series-page">
        @include('invoices._navigation')

        <div class="invoice-series-info" role="note">
            <i class="bi bi-info-circle me-2" aria-hidden="true"></i>
            Serie systemowe są zawsze aktywne i oznaczone pustą gwiazdką. Dodatkowe serie można aktywować, ukrywać i bezpiecznie usuwać.
        </div>

        <section class="invoice-series-panel" aria-labelledby="invoice-series-title">
            <header class="invoice-series-header">
                <h1 class="invoice-series-title" id="invoice-series-title">Serie numeracji</h1>
                <span title="Dodawanie serii numeracji będzie dostępne w kolejnym etapie.">
                    <button
                        class="btn btn-success btn-sm invoice-series-new"
                        type="button"
                        disabled
                        data-role="new-series-disabled"
                        aria-label="Dodawanie serii numeracji będzie dostępne w kolejnym etapie."
                    >
                        <i class="bi bi-plus-circle" aria-hidden="true"></i>
                        Nowa seria numeracji
                    </button>
                </span>
            </header>

            @if ($series->isEmpty())
                <div class="invoice-series-empty">Nie znaleziono serii numeracji.</div>
            @else
                <div class="invoice-series-table-wrap">
                    <table class="table invoice-series-table">
                        <thead>
                            <tr>
                                <th scope="col">Rodzaj</th>
                                <th scope="col">Nazwa</th>
                                <th scope="col">Format</th>
                                <th class="text-center" scope="col">Pokaż/ukryj</th>
                                <th class="text-center" scope="col">Edytuj</th>
                                <th class="text-center" scope="col">Usuń</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($series as $item)
                                <tr data-series-row="{{ $item->id }}">
                                    <td>{{ $item->document_type->label() }}</td>
                                    <td>
                                        <span class="invoice-series-name">
                                            @if ($item->is_system)
                                                <span
                                                    class="invoice-system-star"
                                                    data-role="system-series-marker"
                                                    data-series-id="{{ $item->id }}"
                                                    title="Predefiniowana seria systemowa"
                                                    aria-label="Predefiniowana seria systemowa"
                                                ><i class="bi bi-star" aria-hidden="true"></i></span>
                                            @endif
                                            {{ $item->name }}
                                        </span>
                                    </td>
                                    <td>{{ $item->number_format }}</td>
                                    <td class="text-center">
                                        @if ($item->is_system)
                                            <span
                                                class="invoice-series-action-disabled"
                                                data-role="system-active-indicator"
                                                data-series-id="{{ $item->id }}"
                                                title="Seria systemowa jest zawsze aktywna i nie może zostać ukryta."
                                                aria-label="Seria systemowa jest zawsze aktywna i nie może zostać ukryta."
                                            ><i class="bi bi-eye" aria-hidden="true"></i></span>
                                        @else
                                            <form
                                                class="d-inline"
                                                method="POST"
                                                action="{{ route('invoices.series.active', $item) }}"
                                                data-role="series-active-form"
                                                data-series-id="{{ $item->id }}"
                                            >
                                                @csrf
                                                @method('PATCH')
                                                <input type="hidden" name="is_active" value="{{ $item->is_active ? 0 : 1 }}">
                                                <button
                                                    class="invoice-series-action"
                                                    type="submit"
                                                    title="{{ $item->is_active ? 'Ukryj serię numeracji' : 'Aktywuj serię numeracji' }}"
                                                    aria-label="{{ $item->is_active ? 'Ukryj serię numeracji' : 'Aktywuj serię numeracji' }}"
                                                ><i class="bi {{ $item->is_active ? 'bi-eye' : 'bi-eye-slash' }}" aria-hidden="true"></i></button>
                                            </form>
                                        @endif
                                    </td>
                                    <td class="text-center">
                                        <span
                                            class="invoice-series-action-disabled"
                                            data-role="series-edit-disabled"
                                            data-series-id="{{ $item->id }}"
                                            title="Edycja serii numeracji będzie dostępna w kolejnym etapie."
                                            aria-label="Edycja serii numeracji będzie dostępna w kolejnym etapie."
                                            aria-disabled="true"
                                        ><i class="bi bi-pencil" aria-hidden="true"></i></span>
                                    </td>
                                    <td class="text-center">
                                        @if ($item->is_system)
                                            <span
                                                class="invoice-series-action-disabled"
                                                data-role="system-delete-disabled"
                                                data-series-id="{{ $item->id }}"
                                                title="Predefiniowanej serii systemowej nie można usunąć."
                                                aria-label="Predefiniowanej serii systemowej nie można usunąć."
                                                aria-disabled="true"
                                            ><i class="bi bi-x-lg" aria-hidden="true"></i></span>
                                        @elseif ($item->is_active || $item->series_using_as_default_correction_count > 0)
                                            <span
                                                class="invoice-series-action-disabled"
                                                data-role="series-delete-disabled"
                                                data-series-id="{{ $item->id }}"
                                                title="{{ $item->is_active ? 'Nie można usunąć aktywnej serii numeracji. Najpierw ją ukryj.' : 'Nie można usunąć serii numeracji, ponieważ jest przypisana jako seria korekt.' }}"
                                                aria-label="{{ $item->is_active ? 'Nie można usunąć aktywnej serii numeracji. Najpierw ją ukryj.' : 'Nie można usunąć serii numeracji, ponieważ jest przypisana jako seria korekt.' }}"
                                                aria-disabled="true"
                                            ><i class="bi bi-x-lg" aria-hidden="true"></i></span>
                                        @else
                                            <form
                                                class="d-inline"
                                                method="POST"
                                                action="{{ route('invoices.series.destroy', $item) }}"
                                                data-role="series-delete-form"
                                                data-series-id="{{ $item->id }}"
                                                data-confirm-message="Czy na pewno chcesz usunąć serię numeracji „{{ $item->name }}”?"
                                                onsubmit="return window.confirm(this.dataset.confirmMessage)"
                                            >
                                                @csrf
                                                @method('DELETE')
                                                <button
                                                    class="invoice-series-action text-danger"
                                                    type="submit"
                                                    title="Usuń serię numeracji"
                                                    aria-label="Usuń serię numeracji"
                                                ><i class="bi bi-x-lg" aria-hidden="true"></i></button>
                                            </form>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif

            <footer class="invoice-series-footer">
                <span class="invoice-series-count">Znaleziono: {{ $series->total() }}</span>
                <x-pagination-toolbar
                    :paginator="$series"
                    :per-page-options="[10]"
                    :per-page="10"
                    aria-label="Paginacja serii numeracji"
                />
            </footer>
        </section>
    </div>
@endsection
