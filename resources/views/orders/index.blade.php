@extends('layouts.app')

@section('title', 'Zamowienia - NEX-OMS')

@php
    $starColors = [
        '' => ['label' => 'Usu&#324; kolor gwiazdki', 'class' => 'star-empty', 'icon' => '&#9734;'],
        'orange' => ['label' => 'Ustaw pomara&#324;czow&#261; gwiazdk&#281;', 'class' => 'star-orange', 'icon' => '&#9733;'],
        'navy' => ['label' => 'Ustaw granatow&#261; gwiazdk&#281;', 'class' => 'star-navy', 'icon' => '&#9733;'],
        'green' => ['label' => 'Ustaw zielon&#261; gwiazdk&#281;', 'class' => 'star-green', 'icon' => '&#9733;'],
        'blue' => ['label' => 'Ustaw niebiesk&#261; gwiazdk&#281;', 'class' => 'star-blue', 'icon' => '&#9733;'],
        'red' => ['label' => 'Ustaw czerwon&#261; gwiazdk&#281;', 'class' => 'star-red', 'icon' => '&#9733;'],
    ];
    $starClass = fn ($color) => $starColors[$color ?? '']['class'] ?? 'star-empty';
    $money = fn ($value) => number_format((float) $value, 2, '.', '');
    $compactDate = fn ($value) => $value ? $value->format('d.m.Y H:i') : '...';
    $activeFilters = array_filter($filters ?? [], fn ($value) => $value !== null && $value !== '');
    $filterQueryBase = array_filter(array_merge($activeFilters, ['q' => $searchQuery, 'per_page' => $perPage]), fn ($value) => $value !== null && $value !== '');
    $filterValue = fn ($key) => $filters[$key] ?? '';
    $headerStatus = $currentStatus ? ($statusSettings[$currentStatus] ?? null) : null;
    $headerTitle = $showTrash ? 'Kosz' : ($headerStatus['name'] ?? 'Wszystkie zam&oacute;wienia');
    $headerColor = $showTrash ? '#64748b' : ($headerStatus['color'] ?? '#64748b');
    $showHeaderColor = $showTrash || $headerStatus !== null;
    $selectionScope = request()->except(['page', 'per_page']);
    ksort($selectionScope);
    $selectionScopeKey = md5(json_encode($selectionScope));
@endphp

@section('content')
    <style>
        .orders-page {
            --orders-button-border: #d1d5dc;
            background: #f4f6f8;
            color: #4e565f;
            font-size: 12px;
            margin: -1.5rem;
            min-height: 100vh;
            padding: 24px;
            position: relative;
        }

        .orders-page .btn-light,
        .orders-page .btn-outline-secondary,
        .orders-page .btn.border,
        .orders-page .selection-toggle,
        .orders-page .orders-icon {
            border-color: var(--orders-button-border) !important;
        }

        .orders-page.is-loading {
            cursor: progress;
        }

        .orders-page.is-loading::after {
            background: rgba(244, 246, 248, .45);
            content: '';
            inset: 0;
            pointer-events: none;
            position: absolute;
            z-index: 1080;
        }

        .orders-loading-indicator {
            align-items: center;
            background: #2694cf;
            border: 2px solid #ffffff;
            border-radius: 5px;
            box-shadow: 0 2px 7px rgba(15, 23, 42, .25);
            color: #ffffff;
            display: flex;
            font-size: 18px;
            font-weight: 600;
            gap: 10px;
            justify-content: center;
            left: 50%;
            min-width: 280px;
            opacity: 0;
            padding: 13px 22px;
            pointer-events: none;
            position: absolute;
            top: clamp(150px, 28vh, 260px);
            transform: translateX(-50%);
            visibility: hidden;
            z-index: 1081;
        }

        .orders-page.is-loading .orders-loading-indicator {
            animation: orders-loading-reveal .01s linear .2s forwards;
            visibility: visible;
        }

        .orders-loading-indicator .spinner-border {
            border-width: 2px;
            height: 20px;
            width: 20px;
        }

        @keyframes orders-loading-reveal {
            to {
                opacity: 1;
            }
        }

        .orders-table-card {
            background: #ffffff;
            border: 1px solid #dfe4ea;
            border-left: 0;
            border-radius: 0;
            border-right: 0;
            box-shadow: none;
            margin-left: -24px;
            margin-right: -24px;
            overflow: visible;
        }

        .orders-table {
            --bs-table-color: #4e565f;
            color: #4e565f;
            font-size: 12px;
            margin-bottom: 0;
        }

        .orders-table thead th {
            background: #f8fafc;
            border-bottom: 1px solid #dbe2ea;
            color: #4e565f;
            font-size: 12px;
            font-weight: 700;
            padding: 7px 6px;
            text-transform: uppercase;
            vertical-align: top;
            white-space: nowrap;
        }

        .orders-table thead th .orders-th-subtitle {
            text-transform: none;
        }

        .selection-menu {
            display: inline-block;
            position: relative;
        }

        .selection-toggle {
            align-items: center;
            background: transparent;
            border: 1px solid var(--orders-button-border);
            border-radius: 4px;
            color: #6c757d;
            display: inline-flex;
            gap: 7px;
            height: 31px;
            justify-content: center;
            padding: 0;
            width: 44px;
        }

        .selection-toggle:hover,
        .selection-toggle:focus-visible,
        .selection-toggle[aria-expanded="true"] {
            background: #6c757d;
            border-color: #6c757d;
            color: #ffffff;
            outline: 0;
        }

        .selection-checkbox-icon {
            color: inherit;
            font-size: 16px;
            line-height: 1;
        }

        .selection-caret {
            color: inherit;
            font-size: 10px;
            line-height: 1;
        }

        .selection-dropdown-menu {
            border: 1px solid #dbe2ea;
            border-radius: 4px;
            box-shadow: 0 12px 28px rgba(15, 23, 42, .12);
            font-size: 13px;
            left: 0;
            margin-top: 0;
            min-width: 320px;
            padding: 10px 0;
            top: 100%;
            z-index: 1060;
        }

        .selection-dropdown-menu .dropdown-item {
            color: #374151;
            padding: 7px 22px;
        }

        .selection-dropdown-menu .dropdown-item:hover {
            background: #f1f5f9;
            color: #0d6efd;
        }

        .selection-dropdown-heading {
            color: #111827;
            font-size: 11px;
            font-weight: 700;
            padding: 10px 22px 6px;
            text-transform: uppercase;
        }

        .orders-table tbody td {
            border-bottom: 1px solid #d1d5dc;
            padding: 7px 6px;
            vertical-align: top;
        }

        .orders-table tbody tr:hover {
            background: #f8fbff;
        }

        .orders-table th.order-select-column,
        .orders-table td.order-select-column {
            padding-left: 4px;
            padding-right: 4px;
            text-align: center;
            width: 22px;
        }

        .orders-table td.order-select-column .form-check-input {
            margin-left: 0;
            margin-right: 0;
        }

        .orders-table th.order-star-column,
        .orders-table td.order-star-column {
            padding-left: 0;
            padding-right: 2px;
            width: 18px;
        }

        .orders-table th.order-customer-column,
        .orders-table td.order-customer-column {
            padding-left: 0;
        }

        .order-number-link {
            color: #0077da;
            font-weight: 700;
            text-decoration: none;
        }

        .order-number-link:hover {
            text-decoration: underline;
        }

        .orders-status-heading {
            align-items: center;
            color: #111827;
            display: inline-flex;
            font-size: 18px;
            font-weight: 700;
            gap: 10px;
            line-height: 1.2;
            margin: 0;
        }

        .orders-status-heading-dot {
            border-radius: 4px;
            display: inline-block;
            height: 15px;
            width: 15px;
        }

        .trash-star {
            display: inline-block;
            font-size: 19px;
            line-height: 1;
        }

        .order-subline {
            color: #64748b;
            font-size: 12px;
            line-height: 1.35;
        }

        .order-customer-name {
            color: #4e565f;
            font-size: 12px;
            font-weight: 400;
        }

        .order-amount {
            color: #4e565f;
            font-weight: 400;
        }

        .order-items-list {
            color: #4e565f;
            font-weight: 400;
            line-height: 1.35;
            max-width: 320px;
        }

        .order-source-line {
            align-items: center;
            color: #0864b1;
            display: inline-flex;
            gap: 4px;
            margin-top: 1px;
        }

        .order-login-copy {
            background: transparent;
            border: 0;
            color: inherit;
            cursor: pointer;
            font: inherit;
            padding: 0;
            text-align: left;
        }

        .order-login-copy:focus-visible {
            outline: 2px solid #0864b1;
            outline-offset: 2px;
        }

        .order-login-tooltip {
            --bs-tooltip-bg: #414b54;
            --bs-tooltip-border-radius: 2px;
            --bs-tooltip-font-size: 12px;
            --bs-tooltip-opacity: 1;
        }

        .order-login-tooltip .tooltip-inner {
            padding: 3px 8px;
            white-space: nowrap;
        }

        .order-source-icon {
            align-items: center;
            border-radius: 3px;
            display: inline-flex;
            flex: 0 0 auto;
            font-size: 10px;
            font-weight: 700;
            height: 16px;
            justify-content: center;
            line-height: 1;
            width: 16px;
        }

        .order-source-icon.source-allegro {
            background: #9ca3af;
            color: #ffffff;
            font-size: 9px;
            text-transform: lowercase;
        }

        .order-source-icon.source-manual {
            background: #9ca3af;
            color: #ffffff;
            overflow: hidden;
            position: relative;
        }

        .order-source-icon.source-manual::before {
            background: #ffffff;
            border-radius: 50%;
            content: "";
            height: 6px;
            left: 5px;
            position: absolute;
            top: 3px;
            width: 6px;
        }

        .order-source-icon.source-manual::after {
            background: #ffffff;
            border-radius: 999px 999px 3px 3px;
            bottom: 3px;
            content: "";
            height: 6px;
            left: 3px;
            position: absolute;
            width: 10px;
        }

        .order-source-icon.source-prestashop {
            background: #9ca3af;
            color: #ffffff;
            overflow: hidden;
            position: relative;
        }

        .order-source-icon.source-prestashop::before {
            border: 2px solid #ffffff;
            border-top: 0;
            border-radius: 1px 1px 3px 3px;
            content: "";
            height: 7px;
            left: 3px;
            position: absolute;
            top: 4px;
            width: 10px;
        }

        .order-source-icon.source-prestashop::after {
            box-shadow: 8px 0 0 #ffffff;
            background: #ffffff;
            border-radius: 50%;
            bottom: 2px;
            content: "";
            height: 3px;
            left: 3px;
            position: absolute;
            width: 3px;
        }

        .order-source-icon.source-prestashop .source-cart-handle {
            background: #ffffff;
            border-radius: 999px;
            height: 2px;
            left: 2px;
            position: absolute;
            top: 3px;
            transform: rotate(10deg);
            width: 11px;
        }

        .order-status-pill {
            border-radius: 4px;
            display: inline-block;
            font-size: 11px;
            font-weight: 700;
            padding: 3px 6px;
        }

        .order-status-badge {
            border-radius: 4px;
            display: inline-block;
            font-size: 12px;
            font-weight: 500;
            line-height: 1.05;
            padding: 3px 7px;
            white-space: nowrap;
        }

        .order-status-badge-new {
            color: #ffffff !important;
        }

        .order-extra-column {
            padding-right: 4px !important;
            text-align: left;
        }

        th.order-extra-column {
            text-align: center;
        }

        .order-extra-content {
            align-items: flex-start;
            display: flex;
            gap: 10px;
            justify-content: flex-start;
        }

        .order-extra-text {
            flex: 1 1 auto;
            min-width: 0;
            text-align: left;
        }

        .order-extra-text .order-subline {
            color: #0864b1;
        }

        .status-filter-button {
            border-color: var(--status-color);
            color: var(--status-color);
            font-weight: 600;
        }

        .status-filter-button:hover,
        .status-filter-button.is-active {
            background: var(--status-color);
            border-color: var(--status-color);
            color: var(--status-text-color);
        }

        .status-filter-count {
            align-items: center;
            background: color-mix(in srgb, var(--status-color) 12%, transparent);
            border-radius: 999px;
            display: inline-flex;
            font-size: 11px;
            font-variant-numeric: tabular-nums;
            height: 18px;
            justify-content: center;
            margin-left: 5px;
            min-width: 18px;
            padding: 0 5px;
        }

        .status-filter-button:hover .status-filter-count,
        .status-filter-button.is-active .status-filter-count {
            background: rgba(255, 255, 255, .2);
        }

        .bulk-status-color-dot {
            border-radius: 4px;
            display: inline-block;
            height: 10px;
            margin-right: 8px;
            vertical-align: -1px;
            width: 10px;
        }

        .status-pending {
            background: #f59e0b;
            color: #111827;
        }

        .orders-icon-row {
            display: flex;
            flex: 0 0 auto;
            gap: 4px;
            justify-content: flex-end;
            margin-left: auto;
        }

        .orders-icon {
            align-items: center;
            border: 1px solid #dbe2ea;
            border-radius: 4px;
            display: inline-flex;
            font-size: 11px;
            font-weight: 700;
            height: 24px;
            justify-content: center;
            width: 24px;
        }

        .orders-icon-shipping-active {
            background: #3498d4;
            border-color: #3498d4;
            color: #fff;
        }

        .orders-icon .bi-truck {
            font-size: 15px;
            line-height: 1;
        }

        .star-button {
            background: transparent;
            border: 0;
            font-size: 15px;
            line-height: 1;
            padding: 0;
        }

        .star-empty { color: #94a3b8; }
        .star-orange { color: #f59e0b; }
        .star-navy { color: #1e3a8a; }
        .star-green { color: #16a34a; }
        .star-blue { color: #2563eb; }
        .star-red { color: #dc2626; }

        .star-picker {
            border: 1px solid #dbe2ea;
            border-radius: 8px;
            box-shadow: 0 12px 28px rgba(15, 23, 42, .14);
            min-width: auto;
            padding: 6px;
            z-index: 1055;
        }

        .star-color-button {
            align-items: center;
            background: #ffffff;
            border: 1px solid transparent;
            border-radius: 5px;
            display: inline-flex;
            font-size: 18px;
            height: 30px;
            justify-content: center;
            width: 30px;
        }

        .star-color-button.is-active,
        .star-color-button:hover {
            background: #eff6ff;
            border-color: #93c5fd;
        }

        .orders-header {
            align-items: center;
            display: flex;
            gap: 18px;
        }

        .orders-top-actions {
            align-items: center;
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            margin-left: auto;
            justify-content: flex-end;
        }

        .orders-add-button {
            border-radius: 6px;
            height: 38px;
            padding-left: 14px;
            padding-right: 14px;
            white-space: nowrap;
        }

        .orders-filters-toggle {
            align-items: center;
            background: #ffffff;
            border: 1px solid #cbd5e1;
            border-radius: 999px;
            color: #374151;
            display: inline-flex;
            font-size: 13px;
            gap: 7px;
            height: 44px;
            padding: 4px 13px 4px 4px;
            white-space: nowrap;
        }

        .orders-filters-toggle:hover,
        .orders-filters-toggle:focus-visible {
            background: #f8fafc;
            border-color: #94a3b8;
            color: #1f2937;
        }

        .orders-filters-toggle:focus-visible {
            box-shadow: 0 0 0 3px rgba(59, 130, 246, .18);
            outline: 0;
        }

        .orders-filters-toggle.is-active {
            background: #eff6ff;
            border-color: #bfdbfe;
        }

        .orders-filters-toggle-icon {
            align-items: center;
            background: #f8fafc;
            border: 1px solid #cbd5e1;
            border-radius: 50%;
            color: #334155;
            display: inline-flex;
            flex: 0 0 32px;
            font-size: 18px;
            height: 32px;
            justify-content: center;
            width: 32px;
        }

        .orders-filters-toggle-chevron {
            font-size: 11px;
            transition: transform .2s ease;
        }

        .orders-filters-toggle[aria-expanded="true"] .orders-filters-toggle-chevron {
            transform: rotate(180deg);
        }
        .orders-filters-panel {
            background: #ffffff;
            border: 1px solid #dfe4ea;
            border-radius: 8px;
            box-shadow: 0 1px 3px rgba(15, 23, 42, .06);
            margin-bottom: 16px;
            padding: 14px;
        }

        .orders-filters-grid {
            display: grid;
            gap: 10px;
            grid-template-columns: repeat(6, minmax(160px, 1fr));
        }

        .orders-filter-field label {
            color: #111827;
            font-size: 12px;
            margin-bottom: 3px;
        }

        .orders-filter-field .form-control,
        .orders-filter-field .form-select {
            border-color: #cbd5e1;
            font-size: 13px;
            min-height: 38px;
        }

        .orders-filter-actions {
            align-items: center;
            display: flex;
            gap: 10px;
            justify-content: flex-end;
            margin-top: 14px;
        }

        .orders-filter-action-button {
            align-items: center;
            border-radius: 999px;
            display: inline-flex;
            font-size: 13px;
            gap: 7px;
            height: 38px;
            justify-content: center;
            padding: 0 18px;
            white-space: nowrap;
        }

        .saved-filters-toggle {
            background: #ffffff;
            border-color: #cbd5e1;
            color: #374151;
        }

        .saved-filters-toggle.show,
        .saved-filters-toggle:hover,
        .saved-filters-toggle:focus,
        .saved-filters-toggle.is-active {
            background: #eaf4fc;
            border-color: #bfdbfe;
            color: #1f2937;
        }

        .saved-filters-toggle-icon {
            align-items: center;
            background: #e2e8f0;
            border-radius: 50%;
            display: inline-flex;
            height: 24px;
            justify-content: center;
            width: 24px;
        }

        .saved-filters-menu {
            border-color: #d7dde5;
            box-shadow: 0 8px 22px rgba(15, 23, 42, .14);
            min-width: 248px;
            padding: 10px 8px;
        }

        .saved-filter-entry {
            align-items: center;
            display: flex;
            gap: 4px;
            min-height: 36px;
        }

        .saved-filter-entry-link {
            border-radius: 4px;
            color: #172033;
            flex: 1 1 auto;
            overflow: hidden;
            padding: 8px 10px;
            text-decoration: none;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .saved-filter-entry-link:hover,
        .saved-filter-entry-link.is-active {
            background: #eef6fc;
            color: #172033;
        }

        .saved-filter-overwrite {
            align-items: center;
            background: transparent;
            border: 0;
            border-radius: 4px;
            color: #172033;
            display: inline-flex;
            flex: 0 0 32px;
            height: 32px;
            justify-content: center;
        }

        .saved-filter-overwrite:hover {
            background: #eef2f7;
            color: #0b6fc2;
        }

        .saved-filter-menu-action {
            align-items: center;
            border: 0;
            border-radius: 4px;
            color: #172033;
            display: flex;
            gap: 12px;
            padding: 9px 10px;
            text-align: left;
            width: 100%;
        }

        .saved-filter-menu-action:hover {
            background: #eef6fc;
        }

        .saved-filter-menu-action i {
            font-size: 16px;
            width: 18px;
        }

        .saved-views-list {
            border-bottom: 1px solid #d5dbe3;
            margin-top: 44px;
        }

        .saved-views-list-heading {
            border-bottom: 1px solid #d5dbe3;
            color: #111827;
            font-size: 11px;
            padding: 0 46px 10px;
            text-transform: uppercase;
        }

        .saved-view-manage-row {
            align-items: center;
            border-bottom: 1px solid #d5dbe3;
            display: flex;
            min-height: 41px;
        }

        .saved-view-manage-row:last-child {
            border-bottom: 0;
        }

        .saved-view-manage-row.is-dragging {
            background: #eef6fc;
            opacity: .65;
        }

        .saved-view-drag-handle {
            background: transparent;
            border: 0;
            color: #94a3b8;
            cursor: grab;
            flex: 0 0 34px;
            padding: 8px;
        }

        .saved-view-drag-handle:active {
            cursor: grabbing;
        }

        .saved-view-name {
            color: #4b5563;
            flex: 1 1 auto;
            overflow: hidden;
            padding: 0 4px;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .saved-view-actions {
            align-items: center;
            display: flex;
            flex: 0 0 auto;
            gap: 10px;
            padding-left: 12px;
        }

        .saved-view-icon-button {
            align-items: center;
            background: #ffffff;
            border: 1px solid #edf0f4;
            border-radius: 50%;
            color: #52606d;
            display: inline-flex;
            height: 34px;
            justify-content: center;
            width: 34px;
        }

        .saved-view-icon-button:hover {
            background: #f8fafc;
            border-color: #dbe2ea;
        }

        .saved-view-rename-form {
            align-items: center;
            display: none;
            flex: 1 1 auto;
            gap: 6px;
        }

        .saved-view-manage-row.is-editing .saved-view-name,
        .saved-view-manage-row.is-editing .saved-view-actions {
            display: none;
        }

        .saved-view-manage-row.is-editing .saved-view-rename-form {
            display: flex;
        }

        .saved-views-empty {
            color: #6b7280;
            padding: 18px 10px;
            text-align: center;
        }

        .orders-list-toolbar {
            align-items: center;
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
        }

        @media (max-width: 991.98px) {
            .orders-header {
                align-items: stretch;
                flex-direction: column;
            }

            .orders-top-actions {
                justify-content: flex-start;
                margin-left: 0;
            }

            .orders-filters-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }

        }

        @media (max-width: 575.98px) {
            .orders-filters-grid {
                grid-template-columns: 1fr;
            }

            .orders-table-card {
                margin-left: -16px;
                margin-right: -16px;
            }

            .orders-filter-actions {
                align-items: stretch;
                flex-direction: column;
            }

            .orders-filter-action-button,
            .orders-filter-actions .dropdown {
                width: 100%;
            }
        }
    </style>

    <div
        class="orders-page"
        data-orders-page
        data-list-signature="{{ $listSignature }}"
        data-selection-scope-key="{{ $selectionScopeKey }}"
        data-all-matching-order-ids='@json($allMatchingOrderIds ?? [])'
        data-starred-matching-order-ids='@json($starredMatchingOrderIds ?? [])'
    >
        <div class="orders-loading-indicator" role="status" aria-live="polite">
            <span class="spinner-border spinner-border-sm" aria-hidden="true"></span>
            <span>Prosz&#281; czeka&#263;</span>
        </div>

        <form id="bulkOrdersForm" method="POST" action="{{ route('orders.bulk-trash') }}">
            @csrf
        </form>

        <div class="orders-header mb-4">
            <div>
                <h1 class="orders-status-heading">
                    @if ($showHeaderColor)
                        <span class="orders-status-heading-dot" style="background: {{ $headerColor }}"></span>
                    @endif
                    <span>{!! $headerTitle !!}</span>
                </h1>
            </div>
            <div class="orders-top-actions">
                <button class="orders-filters-toggle {{ $hasActiveFilters ? 'is-active' : '' }}" type="button" data-bs-toggle="collapse" data-bs-target="#ordersFiltersPanel" aria-expanded="{{ $hasActiveFilters ? 'true' : 'false' }}" aria-controls="ordersFiltersPanel">
                    <span class="orders-filters-toggle-icon"><i class="bi bi-filter-left" aria-hidden="true"></i></span>
                    <span>Filtrowanie zam&oacute;wie&#324;</span>
                    <i class="bi bi-chevron-down orders-filters-toggle-chevron" aria-hidden="true"></i>
                </button>
            </div>
        </div>

        <div class="collapse {{ $hasActiveFilters ? 'show' : '' }}" id="ordersFiltersPanel">
            <form class="orders-filters-panel" method="GET" action="{{ route('orders.index') }}">
                @if ($searchQuery !== '')
                    <input type="hidden" name="q" value="{{ $searchQuery }}">
                @endif
                @if ($currentStatus)
                    <input type="hidden" name="status" value="{{ $currentStatus }}">
                @endif
                @if ($showTrash)
                    <input type="hidden" name="trash" value="1">
                @endif
                <input type="hidden" name="per_page" value="{{ $perPage }}">

                <div class="orders-filters-grid">
                    <div class="orders-filter-field"><label for="filter_number">Numer</label><input id="filter_number" class="form-control form-control-sm" type="text" name="number" value="{{ $filterValue('number') }}"></div>
                    <div class="orders-filter-field"><label for="filter_store_number">Numer w sklepie</label><input id="filter_store_number" class="form-control form-control-sm" type="text" name="store_number" value="{{ $filterValue('store_number') }}"></div>
                    <div class="orders-filter-field"><label for="filter_ordered_from">Data z&#322;o&#380;enia od</label><input id="filter_ordered_from" class="form-control form-control-sm" type="date" name="ordered_from" value="{{ $filterValue('ordered_from') }}"></div>
                    <div class="orders-filter-field"><label for="filter_ordered_to">Data z&#322;o&#380;enia do</label><input id="filter_ordered_to" class="form-control form-control-sm" type="date" name="ordered_to" value="{{ $filterValue('ordered_to') }}"></div>
                    <div class="orders-filter-field"><label for="filter_status_from">Data w statusie od</label><input id="filter_status_from" class="form-control form-control-sm" type="date" name="status_from" value="{{ $filterValue('status_from') }}"></div>
                    <div class="orders-filter-field"><label for="filter_status_to">Data w statusie do</label><input id="filter_status_to" class="form-control form-control-sm" type="date" name="status_to" value="{{ $filterValue('status_to') }}"></div>

                    <div class="orders-filter-field"><label for="filter_source">&#377;r&oacute;d&#322;o zam.</label><select id="filter_source" class="form-select form-select-sm" name="source"><option value="">Wszystkie</option><option value="allegro" @selected($filterValue('source') === 'allegro')>Allegro</option><option value="prestashop" @selected($filterValue('source') === 'prestashop')>PrestaShop</option></select></div>
                    <div class="orders-filter-field"><label for="filter_customer">Imi&#281; / Nazwisko / Firma</label><input id="filter_customer" class="form-control form-control-sm" type="text" name="customer" value="{{ $filterValue('customer') }}"></div>
                    <div class="orders-filter-field"><label for="filter_login">Login</label><input id="filter_login" class="form-control form-control-sm" type="text" name="login" value="{{ $filterValue('login') }}"></div>
                    <div class="orders-filter-field"><label for="filter_email">E-mail</label><input id="filter_email" class="form-control form-control-sm" type="text" name="email" value="{{ $filterValue('email') }}"></div>
                    <div class="orders-filter-field"><label for="filter_phone">Telefon</label><input id="filter_phone" class="form-control form-control-sm" type="text" name="phone" value="{{ $filterValue('phone') }}"></div>
                    <div class="orders-filter-field"><label for="filter_city">Miasto</label><input id="filter_city" class="form-control form-control-sm" type="text" name="city" value="{{ $filterValue('city') }}"></div>

                    <div class="orders-filter-field"><label for="filter_postal_code">Kod pocztowy</label><input id="filter_postal_code" class="form-control form-control-sm" type="text" name="postal_code" value="{{ $filterValue('postal_code') }}"></div>
                    <div class="orders-filter-field"><label for="filter_shipping_method">Spos&oacute;b wysy&#322;ki</label><input id="filter_shipping_method" class="form-control form-control-sm" type="text" name="shipping_method" value="{{ $filterValue('shipping_method') }}"></div>
                    <div class="orders-filter-field"><label for="filter_tracking_number">Numer nadania (ostatni)</label><input id="filter_tracking_number" class="form-control form-control-sm" type="text" name="tracking_number" value="{{ $filterValue('tracking_number') }}" placeholder="Brak pola w MVP"></div>
                    <div class="orders-filter-field"><label for="filter_cash_on_delivery">Pobranie</label><select id="filter_cash_on_delivery" class="form-select form-select-sm" name="cash_on_delivery"><option value="">Wszystkie</option><option value="1" @selected($filterValue('cash_on_delivery') === '1')>Tak</option><option value="0" @selected($filterValue('cash_on_delivery') === '0')>Nie</option></select></div>
                    <div class="orders-filter-field"><label for="filter_payment">P&#322;atno&#347;&#263;</label><select id="filter_payment" class="form-select form-select-sm" name="payment"><option value="">Wszystkie</option><option value="unpaid" @selected($filterValue('payment') === 'unpaid')>Nieop&#322;acone</option><option value="partial" @selected($filterValue('payment') === 'partial')>Cz&#281;&#347;ciowo op&#322;acone</option><option value="paid" @selected($filterValue('payment') === 'paid')>Op&#322;acone</option></select></div>
                    <div class="orders-filter-field"><label for="filter_payment_method">Spos&oacute;b p&#322;atno&#347;ci</label><input id="filter_payment_method" class="form-control form-control-sm" type="text" name="payment_method" value="{{ $filterValue('payment_method') }}"></div>

                    <div class="orders-filter-field"><label for="filter_total_from">&#321;&#261;czna cena od</label><input id="filter_total_from" class="form-control form-control-sm" type="number" step="0.01" min="0" name="total_from" value="{{ $filterValue('total_from') }}"></div>
                    <div class="orders-filter-field"><label for="filter_total_to">&#321;&#261;czna cena do</label><input id="filter_total_to" class="form-control form-control-sm" type="number" step="0.01" min="0" name="total_to" value="{{ $filterValue('total_to') }}"></div>
                    <div class="orders-filter-field"><label for="filter_delivery_cost_from">Koszt wysy&#322;ki od</label><input id="filter_delivery_cost_from" class="form-control form-control-sm" type="number" step="0.01" min="0" name="delivery_cost_from" value="{{ $filterValue('delivery_cost_from') }}"></div>
                    <div class="orders-filter-field"><label for="filter_delivery_cost_to">Koszt wysy&#322;ki do</label><input id="filter_delivery_cost_to" class="form-control form-control-sm" type="number" step="0.01" min="0" name="delivery_cost_to" value="{{ $filterValue('delivery_cost_to') }}"></div>
                    <div class="orders-filter-field"><label for="filter_product">Produkt</label><input id="filter_product" class="form-control form-control-sm" type="text" name="product" value="{{ $filterValue('product') }}"></div>
                    <div class="orders-filter-field"><label for="filter_notes">Uwagi</label><input id="filter_notes" class="form-control form-control-sm" type="text" name="notes" value="{{ $filterValue('notes') }}"></div>
                </div>

                <div class="orders-filter-actions">
                    <div class="dropdown">
                        <button class="btn btn-outline-secondary orders-filter-action-button saved-filters-toggle {{ $activeSavedViewId ? 'is-active' : '' }}" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                            <span class="saved-filters-toggle-icon"><i class="bi bi-table" aria-hidden="true"></i></span>
                            <span>Zapisane filtry</span>
                            <i class="bi bi-chevron-down" aria-hidden="true"></i>
                        </button>
                        <div class="dropdown-menu saved-filters-menu dropdown-menu-end">
                            <div data-saved-filter-entries>
                                @forelse ($savedViews as $savedView)
                                    <div class="saved-filter-entry" data-saved-filter-entry data-view-id="{{ $savedView->id }}">
                                        <a
                                            class="saved-filter-entry-link {{ $activeSavedViewId === $savedView->id ? 'is-active' : '' }}"
                                            href="{{ route('orders.index', array_merge($savedView->filters ?? [], ['per_page' => $perPage])) }}"
                                            title="{{ $savedView->name }}"
                                        >{{ $savedView->name }}</a>
                                        <button
                                            class="saved-filter-overwrite"
                                            type="button"
                                            data-overwrite-saved-view
                                            data-url="{{ route('orders.saved-views.update', $savedView) }}"
                                            data-view-name="{{ $savedView->name }}"
                                            title="Zapisz aktualne filtry w tym widoku"
                                            aria-label="Zapisz aktualne filtry w widoku {{ $savedView->name }}"
                                        ><i class="bi bi-floppy" aria-hidden="true"></i></button>
                                    </div>
                                @empty
                                    <div class="saved-views-empty">Brak zapisanych widok&oacute;w.</div>
                                @endforelse
                            </div>
                            <hr class="dropdown-divider mx-2">
                            <button class="saved-filter-menu-action" type="button" data-bs-toggle="modal" data-bs-target="#createSavedViewModal">
                                <i class="bi bi-plus-lg" aria-hidden="true"></i>
                                <span>Stw&oacute;rz nowy widok</span>
                                <i class="bi bi-question-circle ms-auto" aria-hidden="true" title="Zapisuje aktualnie wprowadzone kryteria filtrowania."></i>
                            </button>
                            <button class="saved-filter-menu-action" type="button" data-bs-toggle="modal" data-bs-target="#manageSavedViewsModal">
                                <i class="bi bi-table" aria-hidden="true"></i>
                                <span>Zarz&#261;dzanie widokami</span>
                            </button>
                        </div>
                    </div>
                    <a class="btn btn-outline-secondary orders-filter-action-button" href="{{ route('orders.index', array_filter(['q' => $searchQuery, 'status' => $currentStatus, 'trash' => $showTrash ? 1 : null, 'per_page' => $perPage], fn ($value) => $value !== null && $value !== '')) }}">Wyczy&#347;&#263; filtry</a>
                    <button class="btn btn-primary orders-filter-action-button" type="submit">Ustaw filtry</button>
                </div>
            </form>
        </div>

        <div class="orders-list-toolbar mb-3">
            <div class="selection-menu dropdown">
                <button class="selection-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false" aria-label="Menu zaznaczania zamowien">
                    <input class="visually-hidden" type="checkbox" aria-label="Stan zaznaczenia zamowien na stronie" data-order-select-all disabled>
                    <i class="bi bi-check-square selection-checkbox-icon" aria-hidden="true"></i>
                    <i class="bi bi-chevron-down selection-caret" aria-hidden="true"></i>
                </button>
                <div class="dropdown-menu selection-dropdown-menu">
                    <button class="dropdown-item" type="button" data-selection-action="select-page-all">Zaznacz wszystkie</button>
                    <button class="dropdown-item" type="button" data-selection-action="select-page-starred">Zaznacz oznaczone gwiazdk&#261;</button>
                    <button class="dropdown-item" type="button" data-selection-action="clear-page-all">Odznacz wszystkie</button>
                    <button class="dropdown-item" type="button" data-selection-action="clear-page-starred">Odznacz oznaczone gwiazdk&#261;</button>
                    <div class="selection-dropdown-heading">Na wszystkich stronach</div>
                    <button class="dropdown-item" type="button" data-selection-action="select-all-pages">Zaznacz wszystkie</button>
                    <button class="dropdown-item" type="button" data-selection-action="select-all-pages-starred">Zaznacz oznaczone gwiazdk&#261;</button>
                    <button class="dropdown-item" type="button" data-selection-action="clear-all-pages">Odznacz wszystkie</button>
                    <button class="dropdown-item" type="button" data-selection-action="clear-all-pages-starred">Odznacz oznaczone gwiazdk&#261;</button>
                </div>
            </div>
            @if ($showTrash)
                <button class="btn btn-sm btn-outline-danger" type="submit" form="bulkOrdersForm" formaction="{{ route('orders.bulk-force-delete') }}" title="Usu&#324; zam&oacute;wienia z kosza" aria-label="Usu&#324; zam&oacute;wienia z kosza" data-trash-selection-action data-trash-force-delete>
                    <span aria-hidden="true">&#128465;</span>
                </button>
                <button class="btn btn-sm btn-outline-primary" type="submit" form="bulkOrdersForm" formaction="{{ route('orders.bulk-restore') }}" title="Przywr&oacute;&#263; zam&oacute;wienia" aria-label="Przywr&oacute;&#263; zam&oacute;wienia" data-trash-selection-action>
                    Przywr&oacute;&#263;
                </button>
            @else
                <div class="dropdown">
                    <button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                        Akcje
                    </button>
                    <ul class="dropdown-menu">
                        <li>
                            <button class="dropdown-item text-danger" type="submit" form="bulkOrdersForm" data-bulk-trash-submit>
                                Usu&#324; zam&oacute;wienia
                            </button>
                        </li>
                    </ul>
                </div>
                <div class="dropdown">
                    <button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                        Zmie&#324; status
                    </button>
                    <ul class="dropdown-menu">
                        @foreach ($statuses as $status => $label)
                            @php
                                $bulkStatusColor = $statusSettings[$status]['color'] ?? '#64748b';
                            @endphp
                            <li>
                                <button class="dropdown-item" type="submit" form="bulkOrdersForm" formaction="{{ route('orders.bulk-status') }}" name="status" value="{{ $status }}" data-bulk-status-submit>
                                    <span class="bulk-status-color-dot" style="background: {{ $bulkStatusColor }}"></span>
                                    {{ $label }}
                                </button>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif
            <x-pagination-toolbar
                :paginator="$orders"
                :per-page-options="$perPageOptions"
                :per-page="$perPage"
                aria-label="Paginacja zam&oacute;wie&#324;"
            />
        </div>

        <div class="orders-table-card">
            <div class="table-responsive">
                <table class="table orders-table align-middle">
                    <colgroup>
                        <col style="width: 22px;">
                        <col style="width: 18px;">
                        <col style="width: 96px;">
                        <col style="width: 152px;">
                        <col>
                        <col style="width: 104px;">
                        <col style="width: 220px;">
                        <col style="width: 118px;">
                    </colgroup>
                    <thead>
                        <tr>
                            <th class="order-select-column"></th>
                            <th class="order-star-column"></th>
                            <th>Numer<br><span class="fw-normal text-secondary orders-th-subtitle">(w sklepie)</span></th>
                            <th class="order-customer-column">Imi&#281; i nazwisko<br><span class="fw-normal text-secondary orders-th-subtitle">(&#378;r&oacute;d&#322;o)</span></th>
                            <th>Przedmioty</th>
                            <th>Kwota</th>
                            <th class="order-extra-column">Informacje dodatkowe</th>
                            <th>Data z&#322;o&#380;enia</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($orders as $order)
                            @php
                                $storeOrderNumber = $order->source === 'prestashop' ? $order->external_id : null;
                                $prestashopSourceName = $order->source === 'prestashop'
                                    ? trim((string) $order->prestashopOrderLinks->first()?->account?->name)
                                    : '';
                                $sourceLabel = $prestashopSourceName !== ''
                                    ? $prestashopSourceName
                                    : ($sourceOptions[$order->source] ?? ($order->source ?: '...'));
                                $sourceDisplayLabel = $order->source === 'allegro'
                                    ? '('.($order->customer_login ?: '...').')'
                                    : $sourceLabel;
                                $allegroSourceName = '';
                                if ($order->source === 'allegro' && $order->allegroOrderLink) {
                                    $allegroSourceName = trim((string) ($order->allegroOrderLink->account?->name ?: $order->allegroOrderLink->account_name));
                                    $sourceDisplayLabel .= $allegroSourceName !== '' ? ' z '.$allegroSourceName : '';
                                }
                                $sourceIconClass = match ($order->source) {
                                    'allegro' => 'source-allegro',
                                    'prestashop' => 'source-prestashop',
                                    default => 'source-manual',
                                };
                                $sourceIcon = match ($order->source) {
                                    'allegro' => 'al',
                                    'prestashop' => '<span class="source-cart-handle"></span>',
                                    default => '',
                                };
                                $currentStar = $order->star_color ?? '';
                                $visibleItems = $order->items->take(3);
                                $paidAmount = (float) $order->paid_amount;
                                $totalGross = (float) $order->total_gross;
                                $isFullyPaid = $totalGross > 0 && abs($paidAmount - $totalGross) < 0.005;
                                $isOverpaid = $paidAmount > $totalGross && $paidAmount > 0;
                                $amountIconClass = $isFullyPaid
                                    ? 'bg-success text-white border-success'
                                    : ($isOverpaid
                                        ? 'bg-danger text-white border-danger'
                                        : ($order->cash_on_delivery
                                        ? 'bg-warning text-dark border-warning'
                                        : ($paidAmount <= 0 ? 'bg-light text-secondary' : 'bg-danger text-white border-danger')));
                            @endphp
                            <tr>
                                <td class="order-select-column"><input class="form-check-input" type="checkbox" name="order_ids[]" value="{{ $order->id }}" form="bulkOrdersForm" aria-label="Zaznacz zamowienie {{ $order->id }}" data-order-checkbox data-order-starred="{{ $currentStar ? '1' : '0' }}"></td>
                                <td class="order-star-column">
                                    @if ($showTrash)
                                        <span class="trash-star {{ $starClass($currentStar) }}" aria-hidden="true">{!! $currentStar ? '&#9733;' : '&#9734;' !!}</span>
                                    @else
                                        <div class="dropdown">
                                            <button class="star-button {{ $starClass($currentStar) }}" type="button" data-bs-toggle="dropdown" data-bs-boundary="viewport" aria-expanded="false" aria-label="Wybierz kolor oznaczenia zamowienia">
                                                {!! $currentStar ? '&#9733;' : '&#9734;' !!}
                                            </button>
                                            <div class="dropdown-menu star-picker">
                                                <div class="d-flex gap-1">
                                                    @foreach ($starColors as $color => $config)
                                                        <form method="POST" action="{{ route('orders.star-color.update', $order) }}">
                                                            @csrf
                                                            @method('PATCH')
                                                            <input type="hidden" name="star_color" value="{{ $color }}">
                                                            <button type="submit" class="star-color-button {{ $config['class'] }} {{ $currentStar === $color ? 'is-active' : '' }}" aria-label="{!! $config['label'] !!}" title="{!! $config['label'] !!}">{!! $config['icon'] !!}</button>
                                                        </form>
                                                    @endforeach
                                                </div>
                                            </div>
                                        </div>
                                    @endif
                                </td>
                                <td class="order-customer-column">
                                    @if ($showTrash)
                                        <span class="fw-bold text-secondary">{{ $order->id }}</span>
                                    @else
                                        <a class="order-number-link" href="{{ route('orders.show', ['order' => $order, 'status' => $currentStatus ?? 'all']) }}" data-page-navigation-loading>{{ $order->id }}</a>
                                    @endif
                                    @if ($storeOrderNumber)
                                        <div class="order-subline">({{ $storeOrderNumber }})</div>
                                    @endif
                                </td>
                                <td>
                                    <div class="order-customer-name">{{ $order->shipping_name ?: ($order->customer_login ?: '...') }}</div>
                                    <div class="order-subline order-source-line">
                                        <span class="order-source-icon {{ $sourceIconClass }}" aria-hidden="true">{!! $sourceIcon !!}</span>
                                        @if ($order->source === 'allegro' && filled($order->customer_login))
                                            <span>(<button type="button" class="order-login-copy" data-order-copy-login="{{ $order->customer_login }}" title="Kopiuj do schowka" aria-label="Kopiuj login {{ $order->customer_login }} do schowka">{{ $order->customer_login }}</button>){{ $allegroSourceName !== '' ? ' z '.$allegroSourceName : '' }}</span>
                                        @else
                                            <span>{{ $sourceDisplayLabel }}</span>
                                        @endif
                                    </div>
                                </td>
                                <td>
                                    <div class="order-items-list">
                                        @forelse ($visibleItems as $item)
                                            <div>{{ $item->quantity }}x {{ $item->product_name }}</div>
                                        @empty
                                            <span class="text-secondary">...</span>
                                        @endforelse
                                        @if ($order->items->count() > 3)
                                            <div class="order-subline">+{{ $order->items->count() - 3 }} wi&#281;cej</div>
                                        @endif
                                    </div>
                                </td>
                                <td>
                                    <span class="order-amount">{{ $money($order->total_gross) }} {{ $order->currency }}</span>
                                </td>
                                <td class="order-extra-column">
                                    <div class="order-extra-content">
                                        <div class="order-extra-text">
                                            <span class="order-status-badge {{ $order->status === 'new' ? 'order-status-badge-new' : '' }}" style="background: {{ $order->statusColor() }}; color: {{ $order->statusTextColor() }};">{{ $order->statusLabel() }}</span>
                                            <div class="order-subline mt-1">{{ $order->shipping_method ?: '...' }}</div>
                                        </div>
                                        <div class="orders-icon-row">
                                            <span class="orders-icon {{ $amountIconClass }}" title="Kwota" aria-label="Kwota">$</span>
                                            <span class="orders-icon {{ $order->shipments_exists ? 'orders-icon-shipping-active' : 'bg-light text-secondary' }}" title="Wysy&#322;ka" aria-label="Wysy&#322;ka"><i class="bi bi-truck" aria-hidden="true"></i></span>
                                            <span class="orders-icon bg-light text-secondary" title="Informacje" aria-label="Informacje">i</span>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <div>{{ $compactDate($order->purchased_at ?? $order->created_at) }}</div>
                                    <div class="order-subline text-primary">{{ $compactDate($order->updated_at) }}</div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="8" class="text-center text-secondary py-4">Brak zam&oacute;wie&#324; do wy&#347;wietlenia.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <div class="modal fade" id="createSavedViewModal" tabindex="-1" aria-labelledby="createSavedViewModalLabel" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <form class="modal-content" action="{{ route('orders.saved-views.store') }}" method="POST" data-saved-view-create-form>
                    @csrf
                    <div class="modal-header">
                        <h2 class="modal-title fs-6" id="createSavedViewModalLabel">Stw&oacute;rz nowy widok</h2>
                        <button class="btn-close" type="button" data-bs-dismiss="modal" aria-label="Zamknij"></button>
                    </div>
                    <div class="modal-body">
                        <label class="form-label" for="savedViewName">Nazwa widoku</label>
                        <input id="savedViewName" class="form-control" type="text" name="name" maxlength="100" required autocomplete="off">
                        <div class="form-text">Zapisane zostan&#261; aktualnie wprowadzone kryteria filtrowania.</div>
                    </div>
                    <div class="modal-footer">
                        <button class="btn btn-primary" type="submit">Zapisz</button>
                        <button class="btn btn-outline-secondary" type="button" data-bs-dismiss="modal">Zamknij</button>
                    </div>
                </form>
            </div>
        </div>

        <div class="modal fade" id="manageSavedViewsModal" tabindex="-1" aria-labelledby="manageSavedViewsModalLabel" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <div class="modal-header border-0">
                        <h2 class="modal-title fs-6" id="manageSavedViewsModalLabel">Zarz&#261;dzaj</h2>
                        <button class="btn-close" type="button" data-bs-dismiss="modal" aria-label="Zamknij"></button>
                    </div>
                    <div class="modal-body px-4 pb-4">
                        <div class="saved-views-list">
                            <div class="saved-views-list-heading">Nazwa</div>
                            <div data-saved-views-sortable>
                                @forelse ($savedViews as $savedView)
                                    <div class="saved-view-manage-row" data-saved-view-row data-view-id="{{ $savedView->id }}">
                                        <button class="saved-view-drag-handle" type="button" draggable="true" title="Przeci&#261;gnij, aby zmieni&#263; kolejno&#347;&#263;" aria-label="Zmie&#324; kolejno&#347;&#263; widoku {{ $savedView->name }}">
                                            <i class="bi bi-grip-vertical" aria-hidden="true"></i>
                                        </button>
                                        <span class="saved-view-name">{{ $savedView->name }}</span>
                                        <div class="saved-view-actions">
                                            <button class="saved-view-icon-button" type="button" data-edit-saved-view title="Edytuj" aria-label="Edytuj widok {{ $savedView->name }}"><i class="bi bi-pencil" aria-hidden="true"></i></button>
                                            <button
                                                class="saved-view-icon-button"
                                                type="button"
                                                data-delete-saved-view
                                                data-url="{{ route('orders.saved-views.destroy', $savedView) }}"
                                                data-view-name="{{ $savedView->name }}"
                                                title="Usu&#324;"
                                                aria-label="Usu&#324; widok {{ $savedView->name }}"
                                            ><i class="bi bi-trash" aria-hidden="true"></i></button>
                                        </div>
                                        <form class="saved-view-rename-form" action="{{ route('orders.saved-views.update', $savedView) }}" method="POST" data-saved-view-rename-form>
                                            @csrf
                                            @method('PATCH')
                                            <input class="form-control form-control-sm" type="text" name="name" value="{{ $savedView->name }}" maxlength="100" required aria-label="Nowa nazwa widoku">
                                            <button class="saved-view-icon-button" type="submit" title="Zapisz" aria-label="Zapisz nazw&#281;"><i class="bi bi-check-lg" aria-hidden="true"></i></button>
                                            <button class="saved-view-icon-button" type="button" data-cancel-saved-view-edit title="Anuluj" aria-label="Anuluj edycj&#281;"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
                                        </form>
                                    </div>
                                @empty
                                    <div class="saved-views-empty">Brak zapisanych widok&oacute;w.</div>
                                @endforelse
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer border-0 pt-0">
                        <button class="btn btn-outline-secondary rounded-pill px-4" type="button" data-bs-dismiss="modal">Zamknij</button>
                    </div>
                </div>
            </div>
        </div>

        @if ($showTrash)
            <div class="modal fade" id="noOrdersSelectedModal" tabindex="-1" aria-labelledby="noOrdersSelectedModalLabel" aria-hidden="true">
                <div class="modal-dialog modal-dialog-centered modal-sm">
                    <div class="modal-content">
                        <div class="modal-body text-center pt-4" id="noOrdersSelectedModalLabel">
                            Nie zaznaczono &#380;adnego zam&oacute;wienia.
                        </div>
                        <div class="modal-footer border-0 justify-content-center pt-0">
                            <button class="btn btn-primary px-4" type="button" data-bs-dismiss="modal">OK</button>
                        </div>
                    </div>
                </div>
            </div>

            <div class="modal fade" id="confirmTrashDeleteModal" tabindex="-1" aria-labelledby="confirmTrashDeleteModalLabel" aria-hidden="true">
                <div class="modal-dialog modal-dialog-centered">
                    <div class="modal-content">
                        <div class="modal-header border-0 pb-0">
                            <h2 class="modal-title fs-5" id="confirmTrashDeleteModalLabel">Trwa&#322;e usuni&#281;cie zam&oacute;wie&#324;</h2>
                            <button class="btn-close" type="button" data-bs-dismiss="modal" aria-label="Zamknij"></button>
                        </div>
                        <div class="modal-body">
                            Czy na pewno chcesz trwale usun&#261;&#263; zaznaczone zam&oacute;wienia z kosza? Tej operacji nie mo&#380;na cofn&#261;&#263;.
                        </div>
                        <div class="modal-footer border-0 pt-0">
                            <button class="btn btn-light border" type="button" data-bs-dismiss="modal">Anuluj</button>
                            <button class="btn btn-danger" type="button" data-confirm-trash-delete>Usu&#324; trwale</button>
                        </div>
                    </div>
                </div>
            </div>
        @endif
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const initializeOrdersList = () => {
            const ordersPage = document.querySelector('[data-orders-page]');

            if (!ordersPage) {
                return;
            }

            ordersPage.querySelectorAll('[data-order-copy-login]').forEach((button) => {
                bootstrap.Tooltip.getOrCreateInstance(button, {
                    container: 'body',
                    placement: 'left',
                    trigger: 'hover',
                    customClass: 'order-login-tooltip',
                });

                button.addEventListener('click', async (event) => {
                    event.preventDefault();
                    event.stopPropagation();

                    try {
                        if (navigator.clipboard?.writeText && window.isSecureContext) {
                            await navigator.clipboard.writeText(button.dataset.orderCopyLogin);
                            return;
                        }

                        const input = document.createElement('textarea');
                        input.value = button.dataset.orderCopyLogin;
                        input.setAttribute('readonly', 'readonly');
                        input.style.position = 'fixed';
                        input.style.opacity = '0';
                        document.body.appendChild(input);

                        try {
                            input.select();
                            if (!document.execCommand('copy')) {
                                throw new Error('Nie udało się skopiować loginu do schowka.');
                            }
                        } finally {
                            input.remove();
                            button.focus({ preventScroll: true });
                        }
                    } catch (error) {
                        showRequestError('Nie udało się skopiować loginu do schowka. Sprawdź uprawnienia przeglądarki.');
                    }
                });
            });

            const parseOrderIds = (value) => {
                try {
                    const parsed = JSON.parse(value || '[]');

                    return Array.isArray(parsed) ? parsed : [];
                } catch (error) {
                    return [];
                }
            };
            const allMatchingOrderIds = parseOrderIds(ordersPage.dataset.allMatchingOrderIds);
            const starredMatchingOrderIds = parseOrderIds(ordersPage.dataset.starredMatchingOrderIds);
            const selectionStorageKey = 'nexOmsOrderSelection:' + ordersPage.dataset.selectionScopeKey;
            const selectAll = document.querySelector('[data-order-select-all]');
            const checkboxes = Array.from(document.querySelectorAll('[data-order-checkbox]'));
            const selectionButtons = Array.from(document.querySelectorAll('[data-selection-action]'));
            const bulkTrashSubmit = document.querySelector('[data-bulk-trash-submit]');
            const bulkStatusButtons = Array.from(document.querySelectorAll('[data-bulk-status-submit]'));
            const trashSelectionActions = Array.from(document.querySelectorAll('[data-trash-selection-action]'));
            const noOrdersSelectedModalElement = document.getElementById('noOrdersSelectedModal');
            const forceDeleteButton = document.querySelector('[data-trash-force-delete]');
            const confirmTrashDeleteModalElement = document.getElementById('confirmTrashDeleteModal');
            const confirmTrashDeleteButton = document.querySelector('[data-confirm-trash-delete]');
            const bulkOrdersForm = document.getElementById('bulkOrdersForm');
            const checkboxById = new Map(checkboxes.map((checkbox) => [String(checkbox.value), checkbox]));
            const storedSelectedIds = new Set();

            const readStoredSelection = () => {
                try {
                    const parsed = JSON.parse(sessionStorage.getItem(selectionStorageKey) || '[]');

                    if (Array.isArray(parsed)) {
                        parsed.map((id) => String(id)).forEach((id) => storedSelectedIds.add(id));
                    }
                } catch (error) {
                    sessionStorage.removeItem(selectionStorageKey);
                }
            };

            const writeStoredSelection = () => {
                if (storedSelectedIds.size === 0) {
                    sessionStorage.removeItem(selectionStorageKey);
                    return;
                }

                sessionStorage.setItem(selectionStorageKey, JSON.stringify(Array.from(storedSelectedIds)));
            };

            const selectedOrderIds = () => {
                const ids = new Set();

                checkboxes.forEach((checkbox) => {
                    if (checkbox.checked) {
                        ids.add(String(checkbox.value));
                    }
                });

                if (bulkOrdersForm) {
                    Array.from(bulkOrdersForm.querySelectorAll('[data-selection-hidden]')).forEach((input) => {
                        ids.add(String(input.value));
                    });
                }

                return ids;
            };

            const removeSelectionInputs = (predicate = null) => {
                if (!bulkOrdersForm) {
                    return;
                }

                Array.from(bulkOrdersForm.querySelectorAll('[data-selection-hidden]')).forEach((input) => {
                    if (!predicate || predicate(String(input.value))) {
                        input.remove();
                    }
                });
            };

            const ensureHiddenSelectionInputs = (ids) => {
                if (!bulkOrdersForm) {
                    return;
                }

                const existing = new Set(
                    Array.from(bulkOrdersForm.querySelectorAll('[data-selection-hidden]')).map((input) => String(input.value))
                );

                ids.map((id) => String(id)).forEach((id) => {
                    const visibleCheckbox = checkboxById.get(id);

                    if (visibleCheckbox?.checked || existing.has(id)) {
                        return;
                    }

                    const input = document.createElement('input');
                    input.type = 'hidden';
                    input.name = 'order_ids[]';
                    input.value = id;
                    input.dataset.selectionHidden = '1';
                    bulkOrdersForm.appendChild(input);
                    existing.add(id);
                });
            };

            const setVisibleSelection = (predicate, checked) => {
                checkboxes.forEach((checkbox) => {
                    if (predicate(checkbox)) {
                        checkbox.checked = checked;

                        if (checked) {
                            storedSelectedIds.add(String(checkbox.value));
                        } else {
                            storedSelectedIds.delete(String(checkbox.value));
                        }
                    }
                });
            };

            const setAllPagesSelection = (ids, checked) => {
                const idSet = new Set(ids.map((id) => String(id)));

                setVisibleSelection((checkbox) => idSet.has(String(checkbox.value)), checked);

                if (checked) {
                    idSet.forEach((id) => storedSelectedIds.add(id));
                    ensureHiddenSelectionInputs(ids);
                } else {
                    idSet.forEach((id) => storedSelectedIds.delete(id));
                    removeSelectionInputs((id) => idSet.has(id));
                }

                writeStoredSelection();
                updateSelectAllState();
            };

            const restoreStoredSelection = () => {
                if (storedSelectedIds.size === 0) {
                    return;
                }

                checkboxes.forEach((checkbox) => {
                    checkbox.checked = storedSelectedIds.has(String(checkbox.value));
                });

                ensureHiddenSelectionInputs(Array.from(storedSelectedIds));
            };

            const updateSelectAllState = () => {
                if (!selectAll) {
                    return;
                }

                const checkedCount = checkboxes.filter((checkbox) => checkbox.checked).length;
                selectAll.checked = checkboxes.length > 0 && checkedCount === checkboxes.length;
                selectAll.indeterminate = checkedCount > 0 && checkedCount < checkboxes.length;
            };

            if (selectAll) {
                selectAll.addEventListener('change', () => {
                    checkboxes.forEach((checkbox) => {
                        checkbox.checked = selectAll.checked;
                    });
                    updateSelectAllState();
                });
            }

            selectionButtons.forEach((button) => {
                button.addEventListener('click', () => {
                    const action = button.dataset.selectionAction;

                    if (action === 'select-page-all') {
                        setVisibleSelection(() => true, true);
                    }

                    if (action === 'select-page-starred') {
                        setVisibleSelection((checkbox) => checkbox.dataset.orderStarred === '1', true);
                    }

                    if (action === 'clear-page-all') {
                        setVisibleSelection(() => true, false);
                    }

                    if (action === 'clear-page-starred') {
                        setVisibleSelection((checkbox) => checkbox.dataset.orderStarred === '1', false);
                    }

                    if (action === 'select-all-pages') {
                        setAllPagesSelection(allMatchingOrderIds, true);
                        return;
                    }

                    if (action === 'select-all-pages-starred') {
                        setAllPagesSelection(starredMatchingOrderIds, true);
                        return;
                    }

                    if (action === 'clear-all-pages') {
                        setAllPagesSelection(allMatchingOrderIds, false);
                        return;
                    }

                    if (action === 'clear-all-pages-starred') {
                        setAllPagesSelection(starredMatchingOrderIds, false);
                        return;
                    }

                    writeStoredSelection();
                    updateSelectAllState();
                });
            });

            checkboxes.forEach((checkbox) => {
                checkbox.addEventListener('change', () => {
                    if (!checkbox.checked) {
                        storedSelectedIds.delete(String(checkbox.value));
                        removeSelectionInputs((id) => id === String(checkbox.value));
                    } else {
                        storedSelectedIds.add(String(checkbox.value));
                    }

                    writeStoredSelection();
                    updateSelectAllState();
                });
            });

            if (bulkTrashSubmit) {
                bulkTrashSubmit.addEventListener('click', (event) => {
                    const selectedCount = selectedOrderIds().size;

                    if (selectedCount === 0) {
                        event.preventDefault();
                        alert('Zaznacz przynajmniej jedno zamowienie.');
                        return;
                    }

                    if (!confirm('Przeniesc zaznaczone zamowienia do kosza?')) {
                        event.preventDefault();
                    }
                });
            }

            bulkStatusButtons.forEach((button) => {
                button.addEventListener('click', (event) => {
                    const selectedCount = selectedOrderIds().size;

                    if (selectedCount === 0) {
                        event.preventDefault();
                        alert('Zaznacz przynajmniej jedno zamowienie.');
                    }
                });
            });

            trashSelectionActions.forEach((button) => {
                button.addEventListener('click', (event) => {
                    const selectedCount = selectedOrderIds().size;

                    if (selectedCount === 0) {
                        event.preventDefault();

                        if (noOrdersSelectedModalElement) {
                            bootstrap.Modal.getOrCreateInstance(noOrdersSelectedModalElement).show();
                        }

                        return;
                    }

                    if (button === forceDeleteButton && confirmTrashDeleteModalElement) {
                        event.preventDefault();
                        bootstrap.Modal.getOrCreateInstance(confirmTrashDeleteModalElement).show();
                    }
                });
            });

            if (confirmTrashDeleteButton && forceDeleteButton && bulkOrdersForm) {
                confirmTrashDeleteButton.addEventListener('click', () => {
                    confirmTrashDeleteButton.disabled = true;
                    bulkOrdersForm.action = forceDeleteButton.formAction;
                    bulkOrdersForm.submit();
                });
            }

            readStoredSelection();
            restoreStoredSelection();
            updateSelectAllState();
            initializeSavedViews();
            };

            const ordersIndexUrl = new URL(@json(route('orders.index')), window.location.origin);
            let navigationController = null;
            let navigationSequence = 0;
            let listRequestActive = false;

            const currentOrdersPage = () => document.querySelector('[data-orders-page]');

            const selectionStorageKey = (ordersPage = currentOrdersPage()) => ordersPage
                ? 'nexOmsOrderSelection:' + ordersPage.dataset.selectionScopeKey
                : null;

            const clearStoredSelection = (ordersPage = currentOrdersPage()) => {
                const key = selectionStorageKey(ordersPage);

                if (key) {
                    sessionStorage.removeItem(key);
                }
            };

            const replaceOrdersContext = (nextDocument) => {
                const currentContext = document.querySelector('.orders-context-list');
                const nextContext = nextDocument.querySelector('.orders-context-list');

                if (currentContext && nextContext) {
                    currentContext.replaceWith(nextContext);
                }
            };

            const showRequestError = (message) => {
                window.alert(message || 'Nie uda\u0142o si\u0119 wykona\u0107 operacji. Spr\u00f3buj ponownie.');
            };

            const responseErrorMessage = async (response) => {
                const contentType = response.headers.get('content-type') || '';

                if (!contentType.includes('application/json')) {
                    return 'Nie uda\u0142o si\u0119 wykona\u0107 operacji. Spr\u00f3buj ponownie.';
                }

                const payload = await response.json().catch(() => ({}));
                const validationErrors = payload.errors
                    ? Object.values(payload.errors).flat().filter(Boolean)
                    : [];

                return validationErrors.join('\n') || payload.message || 'Nie uda\u0142o si\u0119 wykona\u0107 operacji.';
            };

            const savedViewsOrderUrl = @json(route('orders.saved-views.order'));
            const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';

            const appendCurrentFilterState = (formData) => {
                const filterForm = currentOrdersPage()?.querySelector('.orders-filters-panel');

                if (!filterForm) {
                    return;
                }

                new FormData(filterForm).forEach((value, key) => {
                    if (['per_page', 'trash'].includes(key) || typeof value !== 'string' || value.trim() === '') {
                        return;
                    }

                    formData.append(`filters[${key}]`, value.trim());
                });
            };

            const applyUrlWithCurrentPageSize = (value) => {
                const url = new URL(value, window.location.href);
                const perPage = currentOrdersPage()?.querySelector('.orders-filters-panel [name="per_page"]')?.value;

                if (perPage) {
                    url.searchParams.set('per_page', perPage);
                }

                return url.toString();
            };

            const requestSavedViewMutation = async (url, formData) => {
                currentOrdersPage()?.classList.add('is-loading');

                try {
                    const response = await fetch(url, {
                        method: 'POST',
                        body: formData,
                        headers: {
                            Accept: 'application/json',
                            'X-Requested-With': 'XMLHttpRequest',
                            'X-CSRF-TOKEN': csrfToken,
                        },
                    });

                    if (!response.ok) {
                        throw new Error(await responseErrorMessage(response));
                    }

                    return await response.json();
                } finally {
                    currentOrdersPage()?.classList.remove('is-loading');
                }
            };

            const initializeSavedViews = () => {
                const ordersPage = currentOrdersPage();

                if (!ordersPage || ordersPage.dataset.savedViewsInitialized === '1') {
                    return;
                }

                ordersPage.dataset.savedViewsInitialized = '1';

                const createForm = ordersPage.querySelector('[data-saved-view-create-form]');

                createForm?.addEventListener('submit', async (event) => {
                    event.preventDefault();
                    const submitter = event.submitter;
                    const formData = new FormData(createForm);
                    appendCurrentFilterState(formData);
                    submitter?.setAttribute('disabled', 'disabled');

                    try {
                        const payload = await requestSavedViewMutation(createForm.action, formData);
                        bootstrap.Modal.getInstance(ordersPage.querySelector('#createSavedViewModal'))?.hide();
                        createForm.reset();
                        await loadOrdersPage(applyUrlWithCurrentPageSize(payload.view.apply_url), { pushHistory: true });
                    } catch (error) {
                        showRequestError(error.message);
                    } finally {
                        submitter?.removeAttribute('disabled');
                    }
                });

                ordersPage.querySelectorAll('[data-overwrite-saved-view]').forEach((button) => {
                    button.addEventListener('click', async () => {
                        if (!window.confirm(`Zastąpić ustawienia widoku "${button.dataset.viewName}" aktualnymi filtrami?`)) {
                            return;
                        }

                        const formData = new FormData();
                        formData.append('_method', 'PATCH');
                        formData.append('replace_filters', '1');
                        appendCurrentFilterState(formData);
                        button.disabled = true;

                        try {
                            const payload = await requestSavedViewMutation(button.dataset.url, formData);
                            await loadOrdersPage(applyUrlWithCurrentPageSize(payload.view.apply_url), { pushHistory: true });
                        } catch (error) {
                            showRequestError(error.message);
                        } finally {
                            button.disabled = false;
                        }
                    });
                });

                ordersPage.querySelectorAll('[data-edit-saved-view]').forEach((button) => {
                    button.addEventListener('click', () => {
                        const row = button.closest('[data-saved-view-row]');

                        if (!row) {
                            return;
                        }

                        row.classList.add('is-editing');
                        row.querySelector('[name="name"]')?.focus();
                    });
                });

                ordersPage.querySelectorAll('[data-cancel-saved-view-edit]').forEach((button) => {
                    button.addEventListener('click', () => {
                        const row = button.closest('[data-saved-view-row]');

                        if (!row) {
                            return;
                        }

                        const name = row.querySelector('.saved-view-name')?.textContent?.trim() || '';
                        const input = row.querySelector('[name="name"]');

                        if (input) {
                            input.value = name;
                        }

                        row.classList.remove('is-editing');
                    });
                });

                ordersPage.querySelectorAll('[data-saved-view-rename-form]').forEach((form) => {
                    form.addEventListener('submit', async (event) => {
                        event.preventDefault();
                        const submitter = event.submitter;
                        const row = form.closest('[data-saved-view-row]');
                        const formData = new FormData(form);
                        submitter?.setAttribute('disabled', 'disabled');

                        try {
                            const payload = await requestSavedViewMutation(form.action, formData);
                            const name = payload.view.name;
                            const dropdownEntry = ordersPage.querySelector(`[data-saved-filter-entry][data-view-id="${payload.view.id}"]`);
                            const dropdownLink = dropdownEntry?.querySelector('.saved-filter-entry-link');
                            const overwriteButton = dropdownEntry?.querySelector('[data-overwrite-saved-view]');

                            if (row) {
                                row.querySelector('.saved-view-name').textContent = name;
                                row.querySelector('[name="name"]').value = name;
                                row.classList.remove('is-editing');
                            }

                            if (dropdownLink) {
                                dropdownLink.textContent = name;
                                dropdownLink.title = name;
                            }

                            if (overwriteButton) {
                                overwriteButton.dataset.viewName = name;
                            }
                        } catch (error) {
                            showRequestError(error.message);
                        } finally {
                            submitter?.removeAttribute('disabled');
                        }
                    });
                });

                ordersPage.querySelectorAll('[data-delete-saved-view]').forEach((button) => {
                    button.addEventListener('click', async () => {
                        if (!window.confirm(`Usunąć zapisany widok "${button.dataset.viewName}"?`)) {
                            return;
                        }

                        const formData = new FormData();
                        formData.append('_method', 'DELETE');
                        button.disabled = true;

                        try {
                            await requestSavedViewMutation(button.dataset.url, formData);
                            const row = button.closest('[data-saved-view-row]');
                            const viewId = row?.dataset.viewId;
                            row?.remove();
                            ordersPage.querySelector(`[data-saved-filter-entry][data-view-id="${viewId}"]`)?.remove();

                            if (!ordersPage.querySelector('[data-saved-view-row]')) {
                                ordersPage.querySelector('[data-saved-views-sortable]').innerHTML = '<div class="saved-views-empty">Brak zapisanych widoków.</div>';
                                ordersPage.querySelector('[data-saved-filter-entries]').innerHTML = '<div class="saved-views-empty">Brak zapisanych widoków.</div>';
                            }
                        } catch (error) {
                            showRequestError(error.message);
                            button.disabled = false;
                        }
                    });
                });

                const sortable = ordersPage.querySelector('[data-saved-views-sortable]');

                if (!sortable) {
                    return;
                }

                let draggedRow = null;
                let originalOrder = '';
                const viewOrder = () => Array.from(sortable.querySelectorAll('[data-saved-view-row]'))
                    .map((row) => row.dataset.viewId);
                const rowAfterPointer = (y) => Array.from(sortable.querySelectorAll('[data-saved-view-row]:not(.is-dragging)'))
                    .reduce((closest, row) => {
                        const box = row.getBoundingClientRect();
                        const offset = y - box.top - box.height / 2;

                        return offset < 0 && offset > closest.offset ? { offset, row } : closest;
                    }, { offset: Number.NEGATIVE_INFINITY, row: null }).row;

                const saveViewOrder = async () => {
                    const ids = viewOrder();
                    const formData = new FormData();
                    formData.append('_method', 'PATCH');
                    ids.forEach((id) => formData.append('views[]', id));

                    try {
                        await requestSavedViewMutation(savedViewsOrderUrl, formData);
                        const entries = ordersPage.querySelector('[data-saved-filter-entries]');

                        ids.forEach((id) => {
                            const entry = entries?.querySelector(`[data-saved-filter-entry][data-view-id="${id}"]`);

                            if (entry) {
                                entries.appendChild(entry);
                            }
                        });
                    } catch (error) {
                        showRequestError(error.message);
                        await loadOrdersPage(window.location.href, { pushHistory: false });
                    }
                };

                sortable.querySelectorAll('.saved-view-drag-handle').forEach((handle) => {
                    handle.addEventListener('dragstart', (event) => {
                        draggedRow = handle.closest('[data-saved-view-row]');
                        originalOrder = viewOrder().join('|');
                        draggedRow?.classList.add('is-dragging');
                        event.dataTransfer.effectAllowed = 'move';
                        event.dataTransfer.setData('text/plain', draggedRow?.dataset.viewId || '');
                    });

                    handle.addEventListener('dragend', () => {
                        draggedRow?.classList.remove('is-dragging');

                        if (draggedRow && viewOrder().join('|') !== originalOrder) {
                            saveViewOrder();
                        }

                        draggedRow = null;
                    });
                });

                sortable.addEventListener('dragover', (event) => {
                    if (!draggedRow) {
                        return;
                    }

                    event.preventDefault();
                    const afterRow = rowAfterPointer(event.clientY);

                    if (afterRow) {
                        sortable.insertBefore(draggedRow, afterRow);
                    } else {
                        sortable.appendChild(draggedRow);
                    }
                });
            };

            const loadOrdersPage = async (url, { pushHistory = true } = {}) => {
                const targetUrl = new URL(url, window.location.href);
                const currentPage = currentOrdersPage();
                const requestSequence = ++navigationSequence;

                navigationController?.abort();
                navigationController = new AbortController();
                listRequestActive = true;
                currentPage?.classList.add('is-loading');

                try {
                    const response = await fetch(targetUrl.toString(), {
                        headers: {
                            Accept: 'text/html',
                            'X-Requested-With': 'XMLHttpRequest',
                        },
                        signal: navigationController.signal,
                    });

                    if (!response.ok) {
                        throw new Error('Nie uda\u0142o si\u0119 od\u015bwie\u017cy\u0107 listy zam\u00f3wie\u0144.');
                    }

                    if (response.redirected) {
                        const responseUrl = new URL(response.url, window.location.href);

                        if (responseUrl.pathname !== targetUrl.pathname) {
                            window.location.assign(responseUrl.toString());
                            return true;
                        }
                    }

                    const nextDocument = new DOMParser().parseFromString(await response.text(), 'text/html');
                    const nextOrdersPage = nextDocument.querySelector('[data-orders-page]');
                    const activeOrdersPage = currentOrdersPage();

                    if (!nextOrdersPage || !activeOrdersPage) {
                        throw new Error('Odpowied\u017a serwera nie zawiera listy zam\u00f3wie\u0144.');
                    }

                    activeOrdersPage.querySelectorAll('[data-order-copy-login]').forEach((button) => {
                        bootstrap.Tooltip.getInstance(button)?.dispose();
                    });
                    activeOrdersPage.replaceWith(nextOrdersPage);
                    replaceOrdersContext(nextDocument);
                    const globalSearchInput = document.querySelector('[data-global-order-search-input]');
                    const nextGlobalSearchInput = nextDocument.querySelector('[data-global-order-search-input]');

                    if (globalSearchInput && nextGlobalSearchInput) {
                        globalSearchInput.value = nextGlobalSearchInput.value;
                    }

                    document.title = nextDocument.title || document.title;

                    if (pushHistory && targetUrl.toString() !== window.location.href) {
                        window.history.pushState({ ordersList: true }, '', targetUrl.toString());
                    }

                    initializeOrdersList();
                    document.querySelectorAll('.modal-backdrop').forEach((backdrop) => backdrop.remove());
                    document.body.classList.remove('modal-open');
                    document.body.style.removeProperty('padding-right');

                    return true;
                } catch (error) {
                    if (error.name !== 'AbortError') {
                        showRequestError(error.message);
                    }

                    return false;
                } finally {
                    if (requestSequence === navigationSequence) {
                        listRequestActive = false;
                        currentOrdersPage()?.classList.remove('is-loading');
                    }
                }
            };

            const submitMutation = async (form, submitter = null, { clearSelection = false } = {}) => {
                const action = submitter?.getAttribute('formaction') || form.action;
                const method = (submitter?.getAttribute('formmethod') || form.method || 'POST').toUpperCase();
                const formData = new FormData(form);

                if (submitter?.name && !formData.has(submitter.name)) {
                    formData.append(submitter.name, submitter.value);
                }

                submitter?.setAttribute('disabled', 'disabled');
                currentOrdersPage()?.classList.add('is-loading');

                try {
                    const response = await fetch(action, {
                        method,
                        body: formData,
                        headers: {
                            Accept: 'application/json',
                            'X-Requested-With': 'XMLHttpRequest',
                        },
                    });

                    if (!response.ok) {
                        throw new Error(await responseErrorMessage(response));
                    }

                    document.dispatchEvent(new Event('nexoms:automation-wake'));

                    if (clearSelection) {
                        clearStoredSelection();
                    }

                    await loadOrdersPage(window.location.href, { pushHistory: false });
                } catch (error) {
                    showRequestError(error.message);
                    currentOrdersPage()?.classList.remove('is-loading');
                } finally {
                    if (submitter?.isConnected) {
                        submitter.removeAttribute('disabled');
                    }
                }
            };

            const submitGetForm = (form) => {
                const targetUrl = new URL(form.action || ordersIndexUrl.toString(), window.location.href);
                targetUrl.search = '';

                new FormData(form).forEach((value, key) => {
                    if (typeof value === 'string' && value !== '') {
                        targetUrl.searchParams.append(key, value);
                    }
                });

                form.removeAttribute('data-orders-form-dirty');
                loadOrdersPage(targetUrl, { pushHistory: true });
            };

            const isOrdersIndexUrl = (url) => {
                const targetUrl = new URL(url, window.location.href);

                return targetUrl.origin === ordersIndexUrl.origin
                    && targetUrl.pathname.replace(/\/$/, '') === ordersIndexUrl.pathname.replace(/\/$/, '');
            };

            document.addEventListener('submit', (event) => {
                const form = event.target;

                if (!(form instanceof HTMLFormElement)) {
                    return;
                }

                if (form.matches('.orders-search-form, .orders-filters-panel')) {
                    event.preventDefault();
                    submitGetForm(form);
                    return;
                }

                if (form.closest('.star-picker')) {
                    event.preventDefault();
                    submitMutation(form, event.submitter);
                    return;
                }

                if (form.id === 'bulkOrdersForm') {
                    event.preventDefault();
                    submitMutation(form, event.submitter, { clearSelection: true });
                }
            });

            document.addEventListener('click', (event) => {
                const anchor = event.target.closest('a[href]');

                if (!anchor || event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
                    return;
                }

                if (!anchor.closest('[data-orders-page], .orders-context-list') || !isOrdersIndexUrl(anchor.href)) {
                    return;
                }

                event.preventDefault();
                loadOrdersPage(anchor.href, { pushHistory: true });
            });

            document.addEventListener('input', (event) => {
                const form = event.target.closest('.orders-search-form, .orders-filters-panel');

                if (form) {
                    form.dataset.ordersFormDirty = '1';
                }
            });

            document.addEventListener('click', (event) => {
                const confirmButton = event.target.closest('[data-confirm-trash-delete]');

                if (!confirmButton) {
                    return;
                }

                event.preventDefault();
                event.stopImmediatePropagation();

                const ordersPage = currentOrdersPage();
                const form = ordersPage?.querySelector('#bulkOrdersForm');
                const forceDeleteButton = ordersPage?.querySelector('[data-trash-force-delete]');
                const modalElement = ordersPage?.querySelector('#confirmTrashDeleteModal');

                if (!form || !forceDeleteButton) {
                    return;
                }

                if (modalElement) {
                    bootstrap.Modal.getInstance(modalElement)?.hide();
                }

                submitMutation(form, forceDeleteButton, { clearSelection: true });
            }, true);

            window.addEventListener('popstate', () => {
                loadOrdersPage(window.location.href, { pushHistory: false });
            });

            initializeOrdersList();
        });
    </script>
@endsection
