<style>
    .inpost-page {
        background: #f4f6f8;
        margin: -1.5rem;
        min-height: 100vh;
        padding: 18px;
    }

    .inpost-page-header {
        align-items: center;
        display: flex;
        justify-content: space-between;
        margin-bottom: 14px;
    }

    .inpost-page-title {
        align-items: center;
        color: #172033;
        display: flex;
        font-size: 18px;
        font-weight: 700;
        gap: 9px;
        margin: 0;
    }

    .inpost-title-dot {
        background: #0783dc;
        border-radius: 50%;
        height: 9px;
        width: 9px;
    }

    .inpost-panel {
        background: #fff;
        border: 1px solid #dce2e8;
        border-radius: 7px;
        box-shadow: 0 1px 3px rgba(15, 23, 42, .07);
        margin-bottom: 16px;
        overflow: hidden;
    }

    .inpost-panel-header {
        align-items: center;
        background: #087fd1;
        color: #fff;
        display: flex;
        font-size: 13px;
        font-weight: 600;
        justify-content: space-between;
        min-height: 42px;
        padding: 10px 13px;
    }

    .inpost-filter-header {
        background: #fff;
        color: #1f2937;
        cursor: pointer;
        min-height: 54px;
    }

    .inpost-filter-title {
        align-items: center;
        display: flex;
        gap: 9px;
    }

    .inpost-filter-icon {
        align-items: center;
        border: 1px solid #d7dee7;
        border-radius: 5px;
        color: #536174;
        display: inline-flex;
        font-size: 16px;
        height: 30px;
        justify-content: center;
        width: 30px;
    }

    .inpost-filter-body {
        border-top: 1px solid #e8edf2;
        padding: 14px 10px 16px;
    }

    .inpost-filter-grid {
        display: grid;
        gap: 10px 8px;
        grid-template-columns: repeat(5, minmax(150px, 1fr));
    }

    .inpost-field label {
        color: #4b5563;
        display: block;
        font-size: 11px;
        margin-bottom: 3px;
    }

    .inpost-field .form-control,
    .inpost-field .form-select {
        border-color: #d5dde6;
        font-size: 12px;
        min-height: 34px;
    }

    .inpost-date-pair {
        display: grid;
        gap: 6px;
        grid-column: span 2;
        grid-template-columns: 1fr 1fr;
    }

    .inpost-filter-actions {
        display: flex;
        gap: 8px;
        justify-content: flex-end;
        margin-top: 14px;
    }

    .inpost-table-wrap {
        overflow-x: auto;
    }

    .inpost-table {
        font-size: 12px;
        margin: 0;
        min-width: 1080px;
        vertical-align: middle;
    }

    .inpost-table thead th {
        background: #fff;
        border-bottom: 1px solid #ccd5df;
        color: #344054;
        font-size: 10px;
        font-weight: 600;
        padding: 10px 8px;
        text-transform: uppercase;
        white-space: nowrap;
    }

    .inpost-table tbody td {
        border-bottom: 1px solid #e2e7ed;
        color: #4b5563;
        padding: 7px 8px;
    }

    .inpost-table tbody tr:hover td {
        background: #f7fbff;
    }

    .inpost-table td.inpost-label-cell {
        padding-right: 20px;
    }

    .inpost-link {
        color: #0074c8;
        font-weight: 500;
        text-decoration: none;
    }

    .inpost-link:hover {
        text-decoration: underline;
    }

    .inpost-link-button {
        background: transparent;
        border: 0;
        padding: 0;
    }

    .shipment-details-list {
        margin: 0;
    }

    .shipment-details-row {
        align-items: start;
        border-bottom: 1px solid #edf0f3;
        display: grid;
        gap: 16px;
        grid-template-columns: minmax(130px, 38%) minmax(0, 1fr);
        padding: 10px 0;
    }

    .shipment-details-row:first-child {
        padding-top: 0;
    }

    .shipment-details-row:last-child {
        border-bottom: 0;
        padding-bottom: 0;
    }

    .shipment-details-row dt {
        color: #667085;
        font-size: 12px;
        font-weight: 400;
        margin: 0;
    }

    .shipment-details-row dd {
        color: #1f2937;
        font-size: 13px;
        font-weight: 500;
        margin: 0;
        overflow-wrap: anywhere;
        white-space: pre-line;
    }

    .shipment-status-line {
        align-items: center;
        display: flex;
        gap: 7px;
        min-width: 270px;
    }

    .shipment-status-track {
        background: #edf0f3;
        border-radius: 999px;
        flex: 0 0 82px;
        height: 7px;
        overflow: hidden;
    }

    .shipment-status-fill {
        background: #0783dc;
        border-radius: inherit;
        display: block;
        height: 100%;
    }

    .shipment-status-fill.is-success {
        background: #16834b;
    }

    .shipment-status-fill.is-error {
        background: #dc3545;
    }

    .inpost-empty {
        color: #6b7280;
        font-size: 13px;
        padding: 18px 12px;
    }

    .inpost-table-footer {
        align-items: center;
        display: flex;
        gap: 10px;
        justify-content: space-between;
        padding: 10px 12px;
    }

    .inpost-bulk-actions {
        display: flex;
        gap: 6px;
    }

    .inpost-account-table td {
        line-height: 1.45;
        vertical-align: top;
    }

    .inpost-account-details {
        display: grid;
        gap: 2px 24px;
        grid-template-columns: repeat(2, minmax(220px, 1fr));
    }

    .inpost-template-table {
        min-width: 720px;
    }

    .inpost-template-details {
        display: grid;
        gap: 2px 24px;
        grid-template-columns: repeat(2, minmax(150px, 240px));
    }

    .courier-template-dimensions {
        display: flex;
        flex-wrap: wrap;
        gap: 5px;
    }

    .courier-template-dimension-field {
        flex: 0 0 70px;
        min-width: 0;
        width: 70px;
    }

    .courier-template-name-field .form-control {
        width: 295px;
        max-width: 100%;
    }

    .inpost-modal .courier-template-dimension-field .form-label {
        font-size: 10px;
        white-space: nowrap;
    }

    .account-action {
        align-items: center;
        border: 1px solid #d5dde6;
        border-radius: 50%;
        color: #536174;
        display: inline-flex;
        height: 30px;
        justify-content: center;
        text-decoration: none;
        width: 30px;
    }

    .account-state {
        align-items: center;
        display: inline-flex;
        gap: 6px;
    }

    .account-state-dot {
        background: #98a2b3;
        border-radius: 50%;
        height: 8px;
        width: 8px;
    }

    .account-state-dot.is-active {
        background: #1f9d55;
    }

    .inpost-modal .modal-dialog {
        max-width: 980px;
    }

    .inpost-modal .modal-dialog.courier-template-modal-dialog {
        max-width: 360px;
        width: calc(100% - 2rem);
    }

    .inpost-modal .nav-tabs .nav-link {
        color: #526071;
        font-size: 13px;
    }

    .inpost-modal .nav-tabs .nav-link.active {
        color: #087fd1;
        font-weight: 600;
    }

    .inpost-modal .form-label {
        color: #4b5563;
        font-size: 12px;
        font-weight: 500;
        margin-bottom: 4px;
    }

    .inpost-modal .form-control,
    .inpost-modal .form-select {
        font-size: 13px;
    }

    .inpost-modal .was-validated .form-control:valid:not(.is-invalid),
    .inpost-modal .was-validated .form-select:valid:not(.is-invalid) {
        background-image: none;
        border-color: #dee2e6;
    }

    .inpost-modal-help {
        color: #6b7280;
        font-size: 11px;
        margin-top: 4px;
    }

    @media (max-width: 1200px) {
        .inpost-filter-grid {
            grid-template-columns: repeat(3, minmax(160px, 1fr));
        }
    }

    @media (max-width: 768px) {
        .inpost-page {
            margin: -1rem;
            padding: 12px;
        }

        .inpost-filter-grid,
        .inpost-account-details {
            grid-template-columns: 1fr;
        }

        .inpost-date-pair {
            grid-column: span 1;
        }

        .inpost-table-footer {
            align-items: flex-start;
            flex-direction: column;
        }
    }
</style>
