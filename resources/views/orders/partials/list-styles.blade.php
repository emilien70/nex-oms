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
            border-radius: 3px;
            display: inline-flex;
            align-items: center;
            height: 15px;
            font-size: 11px;
            font-weight: 700;
            line-height: 13px;
            padding: 1px 5px;
            vertical-align: top;
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

        .order-indicators { display: grid; grid-template-columns: repeat(3, 22px); grid-auto-rows: 22px; gap: 2px; flex: 0 0 70px; align-self: flex-start; margin-top: 1px; }
        .order-indicator { display: inline-flex; align-items: center; justify-content: center; width: 22px; height: 22px; border-radius: 2px; color: #fff; font-family: Arial, sans-serif; font-size: 18px; line-height: 1; font-weight: 700; }
        .order-indicator i { font-size: 17px; line-height: 1; }
        .order-indicator.confirmed { background: #147042; }
        .order-indicator.inactive { background: #9ba3ad; }
        .order-indicator-delivery.inactive { background: #ccd0d5; }
        .order-indicator.cod { background: #eb840f; }
        .order-indicator.shipping, .order-indicator.invoice { background: #499fd8; }
        .order-indicator.attention { background: #d54b49; }
        .order-indicator.cancelled { background: #d54839; border-radius: 3px; font-size: 20px; }
        .order-indicator.invoice { position: relative; }
        .order-indicator.invoice i { font-size: 16px; }
        .order-indicator .invoice-lines { position: absolute; top: 6px; left: 7px; width: 4px; height: 1px; background: #499fd8; box-shadow: 0 2px #499fd8; }
        .order-indicator .invoice-currency { position: absolute; top: 9px; left: 9px; color: #499fd8; font: bold 9px/1 Arial, sans-serif; }

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

        @media (max-width: 767.98px) {
            .orders-page[data-orders-page] {
                min-height: 0;
                padding: 16px 12px;
            }

            [data-orders-page] .orders-header {
                align-items: center;
                flex-direction: row;
                gap: 8px;
            }

            [data-orders-page] .orders-status-heading {
                font-size: 16px;
                overflow-wrap: anywhere;
            }

            [data-orders-page] .orders-top-actions {
                flex: 0 0 auto;
                margin-left: auto;
            }

            [data-orders-page] .orders-filters-toggle {
                justify-content: center;
                padding: 0;
                width: 38px;
            }

            [data-orders-page] .orders-filters-toggle > span:not(.orders-filters-toggle-icon),
            [data-orders-page] .orders-filters-toggle-chevron {
                display: none;
            }

            [data-orders-page] .orders-table-card {
                margin-left: -12px;
                margin-right: -12px;
            }

            [data-orders-page] .orders-table {
                font-size: 11px;
                table-layout: fixed;
                width: 100%;
            }

            [data-orders-page] .orders-table colgroup,
            [data-orders-page] .orders-table .order-items-column,
            [data-orders-page] .orders-table .order-date-column {
                display: none;
            }

            [data-orders-page] .orders-table thead th {
                font-size: 10px;
                line-height: 1.35;
                overflow-wrap: anywhere;
                padding: 8px 3px;
                white-space: normal;
            }

            [data-orders-page] .orders-table tbody td {
                overflow-wrap: anywhere;
                padding: 7px 3px;
            }

            [data-orders-page] .orders-table th.order-select-column,
            [data-orders-page] .orders-table td.order-select-column {
                padding-left: 3px;
                padding-right: 3px;
                width: 20px;
            }

            [data-orders-page] .orders-table .order-number-column {
                width: 12%;
            }

            [data-orders-page] .orders-table .order-amount-column {
                width: 17%;
            }

            [data-orders-page] .orders-table .order-extra-column {
                width: 37%;
            }

            [data-orders-page] .orders-table .order-customer-name,
            [data-orders-page] .orders-table .order-subline {
                font-size: 11px;
                overflow-wrap: anywhere;
            }

            [data-orders-page] .orders-table .order-source-line {
                align-items: flex-start;
                display: flex;
                gap: 3px;
            }

            [data-orders-page] .orders-table .order-source-line > span:last-child {
                min-width: 0;
            }

            [data-orders-page] .orders-table .order-login-copy {
                max-width: 100%;
                overflow-wrap: anywhere;
            }

            [data-orders-page] .orders-table .order-extra-content {
                flex-wrap: wrap;
                gap: 4px;
            }

            [data-orders-page] .orders-table .order-extra-text {
                flex: 1 1 56px;
            }

            [data-orders-page] .orders-table .order-number-link {
                display: inline-block;
                min-height: 24px;
                padding-top: 2px;
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
