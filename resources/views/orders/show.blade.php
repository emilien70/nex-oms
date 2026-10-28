@extends('layouts.app')

@section('title', 'Zamowienie ' . $order->id . ' - NEX-OMS')

@php
    $dateValue = fn ($value) => $value ? $value->format('Y-m-d\TH:i') : null;
    $displayDate = fn ($value) => $value ? $value->format('Y-m-d H:i') : '-';
    $shippingAddress = $order->shippingAddressData();
    $billingAddress = $order->billingAddressData();
    $headerCustomerName = $shippingAddress?->name ?: $order->customer_login;
    $formatAddressLine = fn ($address) => $address ? \App\Support\AddressLineFormatter::formatAddressLine($address->street, $address->building_number, $address->apartment_number) : null;
    $countryCatalog = app(\App\Support\CountryCatalog::class);
    $shippingCountryCode = $countryCatalog->normalize(old('shipping_country_code', $shippingAddress?->country_code));
    $billingCountryCode = $countryCatalog->normalize(old('billing_country_code', $billingAddress?->country_code));
    $moneyValue = fn ($value) => number_format((float) $value, 2, ',', ' ');
    $paidAmount = (float) $order->paid_amount;
    $totalGross = (float) $order->total_gross;
    $isFullyPaid = $totalGross > 0 && abs($paidAmount - $totalGross) < 0.005;
    $paidBadgeClass = $paidAmount <= 0 ? 'bg-secondary' : ($isFullyPaid ? 'bg-success' : 'bg-danger');
    $managementDateParts = fn ($value) => $value ? ['date' => $value->format('Y-m-d'), 'time' => $value->format('H:i')] : null;
    $statusChangedAt = $order->status_changed_at ?? $order->created_at;
    $prestashopOrderLink = $order->prestashopOrderLinks->first();
    $allegroOrderLink = $order->allegroOrderLink;
    $allegroTransactionId = $allegroOrderLink?->external_order_id ?: $order->external_id;
    $allegroTransactionUrl = $order->source === 'allegro'
        ? \Modules\Integrations\Allegro\Support\AllegroOrderUrl::make($allegroOrderLink?->environment ?? 'production', $allegroTransactionId)
        : null;
    $selectedShipmentProvider = old('shipment_provider');
    $starColors = [
        '' => ['label' => 'Usu&#324; kolor gwiazdki', 'class' => 'star-empty', 'icon' => '&#9734;'],
        'orange' => ['label' => 'Ustaw pomara&#324;czow&#261; gwiazdk&#281;', 'class' => 'star-orange', 'icon' => '&#9733;'],
        'navy' => ['label' => 'Ustaw granatow&#261; gwiazdk&#281;', 'class' => 'star-navy', 'icon' => '&#9733;'],
        'green' => ['label' => 'Ustaw zielon&#261; gwiazdk&#281;', 'class' => 'star-green', 'icon' => '&#9733;'],
        'blue' => ['label' => 'Ustaw niebiesk&#261; gwiazdk&#281;', 'class' => 'star-blue', 'icon' => '&#9733;'],
        'red' => ['label' => 'Ustaw czerwon&#261; gwiazdk&#281;', 'class' => 'star-red', 'icon' => '&#9733;'],
    ];
    $currentStar = $order->star_color ?? '';
    $starClass = match ($currentStar) {
        'orange' => 'star-orange',
        'navy' => 'star-navy',
        'green' => 'star-green',
        'blue' => 'star-blue',
        'red' => 'star-red',
        default => 'star-empty',
    };
@endphp

@section('content')
    <style>
        .order-page {
            --order-detail-line-height: 18.5px;
            background: #f4f6f8;
            margin: -1.5rem;
            min-height: calc(100vh - 0px);
            padding: 24px;
        }

        .order-topbar {
            align-items: center;
            background: #f5f6f8;
            border-bottom: 1px solid #dfe3e8;
            display: flex;
            gap: 16px;
            justify-content: space-between;
            margin-bottom: 0;
            min-height: 64px;
            padding: 0 20px 8px;
        }

        .order-heading {
            align-items: center;
            display: flex;
            gap: 10px;
            min-width: 0;
        }

        .order-heading-text {
            align-items: baseline;
            column-gap: 26px;
            display: flex;
            flex-wrap: wrap;
            min-width: 0;
            row-gap: 2px;
        }

        .order-heading > .dropdown {
            flex: 0 0 22px;
        }

        .star-button {
            background: transparent;
            border: 0;
            font-size: 19px;
            line-height: 1;
            padding: 0;
        }

        .order-star {
            align-items: center;
            display: inline-flex;
            font-size: 20px;
            height: 26px;
            justify-content: center;
            line-height: 1;
            width: 22px;
        }

        .order-star.star-empty {
            color: #4e565f;
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

        .order-title,
        .order-customer {
            color: #4e565f;
            font-size: 20px;
            font-weight: 300;
            line-height: 26px;
            overflow-wrap: anywhere;
        }

        .order-title {
            margin: 0;
        }

        .order-navigation {
            align-items: center;
            display: flex;
            flex: 0 0 auto;
            gap: 8px;
        }

        .order-navigation-arrow {
            align-items: center;
            background: #ffffff;
            border: 1px solid #9ca3af;
            border-radius: 50%;
            color: #475569;
            display: inline-flex;
            flex: 0 0 40px;
            font-size: 16px;
            height: 40px;
            justify-content: center;
            padding: 0;
            text-decoration: none;
            width: 40px;
        }

        .order-navigation-arrow:hover,
        .order-navigation-arrow:focus-visible {
            background: #f8fafc;
            border-color: #64748b;
            color: #111827;
            outline: 0;
        }

        .order-navigation-arrow:disabled {
            background: transparent;
            border-color: #d5dbe3;
            color: #c1c7d0;
            cursor: default;
        }

        .order-list-back {
            align-items: center;
            background: #ffffff;
            border: 1px solid #0d6efd;
            border-radius: 999px;
            color: #0d6efd;
            display: inline-flex;
            font-size: 13px;
            font-weight: 600;
            gap: 8px;
            height: 40px;
            justify-content: center;
            padding: 0 18px;
            text-decoration: none;
            white-space: nowrap;
        }

        .order-list-back:hover,
        .order-list-back:focus-visible {
            background: #eff6ff;
            color: #0a58ca;
            outline: 0;
        }

        @media (max-width: 575.98px) {
            .order-topbar {
                align-items: flex-start;
                flex-direction: column;
                padding: 8px 0 12px;
            }

            .order-heading {
                align-items: flex-start;
            }

            .order-heading-text {
                column-gap: 16px;
            }

            .order-navigation {
                flex-wrap: wrap;
                width: 100%;
            }

            .order-list-back {
                flex: 1 0 100%;
                order: 2;
            }
        }

        .nex-card {
            background: #ffffff;
            border: 1px solid #e5e7eb;
            border-radius: 12px;
            box-shadow: 0 1px 3px rgba(15, 23, 42, .08);
            margin-bottom: 16px;
            overflow: hidden;
        }

        .nex-card-header {
            align-items: center;
            background: #ffffff;
            border-bottom: 1px solid #eef0f3;
            display: flex;
            justify-content: space-between;
            gap: 12px;
            padding: 12px 16px;
        }

        .nex-card-title {
            color: #111827;
            font-size: 15px;
            font-weight: 600;
            margin: 0;
        }

        .card-header-actions {
            align-items: center;
            display: flex;
            gap: 8px;
        }

        .card-header-actions form {
            margin: 0;
        }

        .icon-action-button {
            align-items: center;
            border-color: #cbd5e1;
            color: #1f2937;
            display: inline-flex;
            font-size: 13px;
            height: 32px;
            justify-content: center;
            line-height: 1;
            padding: 0;
            width: 34px;
        }

        .icon-action-button:hover {
            background: #f8fafc;
            border-color: #94a3b8;
            color: #0d6efd;
        }

        .nex-card-body {
            padding: 12px 14px;
        }

        .billing-invoice-request {
            align-items: center;
            background: #fef5eb;
            border-bottom: 1px solid #f7d1a5;
            border-top: 1px solid #f7d1a5;
            color: #c15c37;
            display: flex;
            font-size: 13px;
            font-weight: 700;
            gap: 5px;
            line-height: 17px;
            margin-top: -1px;
            padding: 12px 14px;
        }

        .billing-invoice-request .bi {
            flex: 0 0 auto;
            font-size: 12px;
        }

        .nex-field-grid {
            display: grid;
            gap: 14px 18px;
            grid-template-columns: repeat(6, minmax(0, 1fr));
        }

        .nex-field {
            min-width: 0;
        }

        .nex-label {
            color: #6b7280;
            font-size: 11px;
            letter-spacing: .03em;
            margin-bottom: 3px;
            text-transform: uppercase;
        }

        .nex-value {
            align-items: center;
            color: #111827;
            display: flex;
            font-size: 13px;
            font-weight: 500;
            justify-content: space-between;
            min-height: 24px;
            min-width: 0;
        }

        .nex-empty {
            color: #9ca3af;
        }

        .nex-muted-box {
            background: #f8fafc;
            border: 1px solid #e5e7eb;
            border-radius: 10px;
            padding: 12px;
        }

        .nex-muted-box.is-empty {
            border-style: dashed;
            border-color: #cbd5e1;
            padding: 14px;
        }

        .nex-sn-box {
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
            font-size: 13px;
            white-space: pre-line;
        }

        .management-placeholder {
            align-items: center;
            color: #6b7280;
            display: flex;
            font-size: 13px;
            min-height: 96px;
        }

        .management-status-form {
            align-items: center;
            display: grid;
            gap: 2px 8px;
            grid-template-columns: 25% 150px max-content;
            justify-content: stretch;
            line-height: var(--order-detail-line-height);
        }

        .management-status-fields,
        .management-sales-document-actions {
            display: contents;
        }

        .management-document-section-gap {
            grid-column: 1 / -1;
            height: 8px;
        }

        .management-status-section-gap {
            grid-column: 1 / -1;
            height: calc(8px - 4px);
        }

        .management-status-label,
        .management-invoice-label,
        .management-meta-label {
            color: #7a838f;
            font-size: 13px;
            white-space: nowrap;
        }

        .management-invoice-label,
        .management-meta-label {
            grid-column: 1;
            margin-top: 0;
        }

        .management-meta-value {
            color: #111827;
            font-size: 13px;
            grid-column: 2 / 4;
            margin-top: 0;
            min-height: var(--order-detail-line-height);
        }

        .management-meta-label.is-status-date,
        .management-meta-value.is-status-date {
            margin-top: 0;
        }

        .allegro-transaction-link {
            color: #7a838f;
            cursor: pointer;
            font-weight: 700;
            text-decoration: none;
        }

        .management-status-form.is-allegro {
            container-type: inline-size;
        }

        .allegro-transaction-details {
            display: grid;
            gap: 0 8px;
            grid-column: 1 / -1;
            grid-template-columns: minmax(110px, 25%) minmax(0, 1fr);
            min-width: 0;
            width: 100cqw;
        }

        .allegro-transaction-details .management-meta-value {
            grid-column: 2;
        }

        @container (max-width: 340px) {
            .allegro-transaction-details {
                grid-template-columns: minmax(0, 1fr);
            }

            .allegro-transaction-details .management-meta-value {
                grid-column: 1;
                margin-top: 2px;
            }
        }

        .allegro-transaction-link:hover,
        .allegro-transaction-link:focus-visible {
            color: #7a838f;
        }

        .management-allegro-invoice-row {
            display: grid;
            gap: 0 8px;
            grid-column: 1 / -1;
            grid-template-columns: minmax(110px, 25%) minmax(0, 1fr);
            min-width: 0;
            width: 100cqw;
        }

        .management-allegro-invoice-row .management-meta-value {
            grid-column: 2;
        }

        @container (max-width: 260px) {
            .management-allegro-invoice-row {
                grid-template-columns: minmax(0, 1fr);
            }

            .management-allegro-invoice-row .management-meta-value {
                grid-column: 1;
            }
        }

        .management-allegro-invoice-row > .text-danger,
        .management-allegro-invoice-row > .text-warning-emphasis {
            grid-column: 1 / -1;
        }

        .management-allegro-invoice-value {
            align-items: baseline;
            display: flex;
            flex-wrap: wrap;
            gap: 0 4px;
        }

        .management-allegro-invoice-form {
            align-items: baseline;
            display: inline-flex;
            margin: 0;
        }

        .management-allegro-invoice-send {
            background: transparent;
            border: 0;
            color: #7a838f;
            cursor: pointer;
            font: inherit;
            font-weight: 700;
            padding: 0;
        }

        .management-portal-link {
            grid-column: 1 / -1;
            min-width: 0;
            margin-top: 18px;
            font-size: 14px;
            line-height: 20px;
        }

        .management-portal-label {
            color: #4b5563;
            font-weight: 500;
            margin-bottom: 2px;
        }

        .management-portal-help {
            border: 0;
            background: transparent;
            padding: 0 2px;
            color: inherit;
            font-size: 13px;
        }

        .management-portal-url {
            color: #0075c9;
            font-weight: 400;
            text-decoration: none;
            overflow-wrap: anywhere;
        }

        .management-portal-url:hover {
            text-decoration: underline;
        }

        .management-portal-unavailable {
            color: #7a838f;
            overflow-wrap: anywhere;
        }

        .customer-portal-tooltip .tooltip-inner {
            max-width: 290px;
            font-size: 13px;
            line-height: 18px;
        }

        .management-meta-time {
            margin-left: 14px;
        }

        .management-invoice-button {
            font-size: 12px;
            font-weight: 700;
            grid-column: 2;
            margin-top: 0;
            min-height: 30px;
            width: 150px;
        }

        .management-invoice-button > .btn:not(:disabled):hover,
        .management-invoice-button > .btn:not(:disabled):focus-visible {
            background-color: #0d6efd;
            border-color: #0d6efd;
            color: #fff;
        }

        .management-proforma-button > .btn:not(:disabled):hover,
        .management-proforma-button > .btn:not(:disabled):focus-visible {
            background-color: #eef7ff;
            border-color: #cfd5dc;
            color: #4e565f;
        }

        .management-issued-invoice-actions {
            align-items: center;
            display: flex;
            gap: 8px;
            grid-column: 2 / 4;
            margin-top: 0;
        }

        .management-correction-label,
        .management-issued-correction-actions {
            margin-top: 0;
        }

        .management-issued-invoice-group {
            box-shadow: none;
        }

        .management-issued-invoice-print-form {
            display: contents;
        }

        .management-issued-invoice-actions .btn {
            align-items: center;
            background: #fff;
            border-color: #cfd5dc;
            color: #4e565f;
            display: inline-flex;
            font-size: 12px;
            height: 30px;
            justify-content: center;
            line-height: 1;
            padding: 0 8px;
        }

        .management-issued-invoice-actions .btn:hover,
        .management-issued-invoice-actions .btn:focus-visible {
            background: #f8fafc;
            border-color: #aeb7c2;
            color: #1f2937;
        }

        .management-issued-invoice-number {
            gap: 4px;
            min-width: max-content;
        }

        .management-issued-invoice-actions .management-issued-invoice-number:hover,
        .management-issued-invoice-actions .management-issued-invoice-number:focus-visible,
        .management-issued-invoice-actions .management-issued-invoice-print:hover,
        .management-issued-invoice-actions .management-issued-invoice-print:focus-visible {
            background-color: #0d6efd;
            border-color: #0d6efd;
            color: #fff;
        }

        .management-issued-invoice-icon {
            flex: 0 0 26px;
            padding: 0 !important;
            width: 26px;
        }

        .management-issued-invoice-icon i {
            font-size: 13px;
            line-height: 1;
        }

        .management-issued-invoice-actions .management-issued-invoice-edit:hover,
        .management-issued-invoice-actions .management-issued-invoice-edit:focus-visible {
            background-color: #eef7ff;
            border-color: #cfd5dc;
            color: #0d6efd;
        }

        .management-issued-invoice-actions .btn:disabled {
            background: #fff;
            border-color: #cfd5dc;
            color: #98a2ad;
            opacity: 1;
        }

        .management-issued-invoice-actions .management-issued-invoice-delete {
            color: #dc3545;
        }

        .management-issued-invoice-actions .management-issued-invoice-delete:hover {
            background-color: #dc3545;
            border-color: #dc3545;
            color: #fff;
        }

        .management-issued-invoice-attachment {
            border-radius: 4px !important;
        }

        .management-issued-invoice-ksef-form {
            display: inline-flex;
            margin: 0;
        }

        .management-issued-invoice-actions .management-issued-invoice-ksef,
        .management-issued-invoice-actions .management-issued-invoice-ksef:disabled {
            background-color: rgba(220, 53, 69, 0.05);
            border-color: rgba(220, 53, 69, 0.2);
            border-radius: 4px;
            color: #4e565f;
            flex: 0 0 auto;
            opacity: 1;
            padding: 0 8px;
            width: auto;
        }

        .management-issued-invoice-actions button.management-issued-invoice-ksef:not(:disabled):hover,
        .management-issued-invoice-actions button.management-issued-invoice-ksef:not(:disabled):focus-visible {
            background-color: rgba(220, 53, 69, 0.05);
            border-color: rgba(220, 53, 69, 0.35);
            color: #4e565f;
        }

        .management-issued-invoice-ksef-reference {
            align-items: center;
            background-color: rgba(220, 53, 69, 0.8) !important;
            border-color: rgba(220, 53, 69, 0.8) !important;
            color: #fff !important;
            display: inline-flex;
            font-size: 12px;
            line-height: 1;
            min-height: 30px;
        }

        .management-issued-invoice-ksef-download {
            cursor: pointer;
            text-decoration: none;
        }

        .management-issued-invoice-ksef-download:hover,
        .management-issued-invoice-ksef-download:focus-visible {
            background-color: rgba(220, 53, 69, 0.9) !important;
            border-color: rgba(220, 53, 69, 0.9) !important;
            color: #fff !important;
            text-decoration: none;
        }

        .management-issued-invoice-ksef-download[aria-busy="true"] {
            cursor: wait;
            opacity: 0.75;
            pointer-events: none;
        }

        .invoice-ksef-status-tooltip {
            --bs-tooltip-bg: #4d5257;
            --bs-tooltip-opacity: 1;
        }

        .invoice-ksef-status-tooltip .tooltip-inner {
            font-size: 11px;
            line-height: 1.35;
            max-width: 220px;
            padding: 8px 10px;
        }

        .invoice-ksef-confirm-dialog {
            margin-top: 12px;
            max-width: 650px;
        }

        .invoice-ksef-confirm-content {
            border: 0;
            border-radius: 8px;
        }

        .invoice-ksef-confirm-body {
            padding: 20px 24px 18px;
            text-align: center;
        }

        .invoice-ksef-confirm-icon {
            color: #e57905;
            font-size: 42px;
            line-height: 1;
        }

        .invoice-ksef-confirm-question {
            color: #20252b;
            font-size: 16px;
            font-weight: 600;
            margin: 28px 0 30px;
        }

        .invoice-ksef-confirm-actions {
            display: flex;
            gap: 7px;
            justify-content: center;
        }

        .invoice-ksef-confirm-actions .btn {
            border-radius: 20px;
            min-width: 64px;
            padding: 9px 18px;
        }

        .invoice-ksef-confirm-accept {
            background: #f57c00;
            border-color: #f57c00;
            color: #fff;
        }

        .invoice-ksef-confirm-accept:hover,
        .invoice-ksef-confirm-accept:focus {
            background: #d96d00;
            border-color: #d96d00;
            color: #fff;
        }

        .management-issued-proforma-actions {
            grid-column: 3;
            justify-self: start;
        }

        .management-issued-proforma-actions .management-document-action {
            display: inline-flex;
        }

        .management-issued-proforma-actions .management-document-action .btn {
            border-bottom-right-radius: 0;
            border-top-right-radius: 0;
            min-width: max-content;
        }

        .management-document-action .btn,
        .management-document-dropdown > .btn {
            min-height: 30px;
            width: 100%;
        }

        .management-document-dropdown .dropdown-menu {
            min-width: 220px;
        }

        .management-sales-document-error {
            font-size: 12px;
            grid-column: 1 / 4;
            margin: 6px 0 0;
            padding: 7px 9px;
        }

        .management-proforma-button {
            font-size: 12px;
            font-weight: 700;
            grid-column: 3;
            justify-self: start;
            margin-top: 0;
            min-height: 30px;
        }

        .management-status-control {
            position: relative;
            width: 150px;
        }

        .management-status-dropdown {
            width: 100%;
        }

        .management-status-toggle {
            align-items: center;
            background: #ffffff;
            border-color: #cbd5e1;
            border-radius: 4px;
            color: #111827;
            display: flex;
            font-size: 13px;
            gap: 8px;
            justify-content: flex-start;
            min-height: 30px;
            padding: 4px 28px 4px 9px;
            position: relative;
            text-align: left;
            width: 100%;
        }

        .management-status-toggle::after {
            position: absolute;
            right: 10px;
            top: 50%;
            transform: translateY(-50%);
        }

        .management-status-menu {
            font-size: 13px;
            min-width: 180px;
            padding: 4px;
        }

        .management-status-option {
            align-items: center;
            border-radius: 4px;
            display: flex;
            gap: 8px;
            padding: 6px 8px;
        }

        .management-status-option.active,
        .management-status-option:active {
            background: #eff6ff;
            color: #111827;
        }

        .management-status-dot {
            border-radius: 4px;
            display: inline-block;
            flex: 0 0 auto;
            height: 14px;
            width: 14px;
        }

        .management-status-submit {
            border-radius: 4px;
            font-size: 13px;
            justify-self: start;
            min-height: 30px;
            opacity: 0.6;
            padding: 4px 12px;
            width: auto;
        }

        .nex-edit-box {
            background: #f8fafc;
            border: 1px solid #e5e7eb;
            border-radius: 10px;
            margin-top: 12px;
            padding: 14px;
        }

        .nex-edit-box .form-label {
            color: #6b7280;
            font-size: 12px;
            margin-bottom: 4px;
        }

        .nex-address-grid {
            display: grid;
            gap: 2px;
        }

        .nex-table {
            font-size: 13px;
        }

        .nex-table thead th {
            background: #f8fafc;
            border-bottom: 1px solid #e5e7eb;
            font-weight: 600;
            padding: 10px 12px;
        }

        .nex-table tbody td {
            border-bottom: 1px solid #eef0f3;
            padding: 10px 12px;
        }

        .nex-table tbody tr:last-child td {
            border-bottom: 0;
        }

        .products-card {
            border-radius: 3px;
            box-shadow: 0 1px 3px rgba(15, 23, 42, .06);
            margin-bottom: 20px;
            overflow: visible;
        }

        .products-card .nex-card-body {
            padding: 0;
        }

        .products-card .nex-card-body,
        .products-table-wrapper {
            overflow: visible;
        }

        .products-toolbar {
            align-items: center;
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            justify-content: space-between;
            margin-bottom: 24px;
            min-height: 40px;
        }

        .products-table {
            --bs-table-color: #4e565f;
            font-size: 12px;
            font-weight: 400;
            line-height: 15px;
            table-layout: auto;
            width: 100%;
        }

        .products-table thead th {
            background: #ffffff;
            border-bottom-color: #cdd2db;
            color: #192027;
            font-size: 11px;
            font-weight: 400;
            height: 66px;
            line-height: 14px;
            padding: 0 10px 12px;
            vertical-align: bottom;
            white-space: nowrap;
        }

        .products-table tbody td {
            border-bottom-color: #cdd2db;
            height: 60px;
            padding: 7px 10px;
            vertical-align: middle;
        }

        .products-table .product-photo-column {
            text-align: center;
            width: 80px;
        }

        .products-table thead .bi-camera {
            font-size: 15px;
        }

        .products-table .product-main-column {
            overflow-wrap: anywhere;
            padding-left: 4px;
            width: 100%;
        }

        .products-table .product-id-column {
            min-width: 96px;
            padding-left: 4px;
            padding-right: 12px;
            white-space: nowrap;
            width: 1%;
        }

        .products-table tbody .product-id-column {
            padding-left: 10px;
        }

        .products-table tbody .product-main-column {
            padding-left: 9px;
        }

        .products-table .product-metric {
            padding-left: 5px;
            padding-right: 5px;
            text-align: center;
            white-space: nowrap;
            width: 1%;
        }

        .products-table .product-quantity,
        .products-table .product-price {
            color: #192027;
        }

        .products-table .product-date-column {
            padding-left: 10px;
            padding-right: 38px;
            text-align: left;
        }

        .products-table .product-actions-column {
            padding-left: 8px;
            white-space: nowrap;
            width: 1%;
        }

        .product-date-stack {
            line-height: 15px;
        }

        .product-thumb {
            align-items: center;
            background: #ffffff;
            border: 1px solid #cdd2db;
            border-radius: 2px;
            color: #64748b;
            display: inline-flex;
            font-size: 16px;
            height: 44px;
            justify-content: center;
            object-fit: contain;
            vertical-align: middle;
            width: 60px;
        }

        .product-thumbnail-wrap {
            display: inline-flex;
            outline: none;
            position: relative;
            vertical-align: middle;
        }

        .product-thumbnail-wrap:focus-visible .product-thumb {
            box-shadow: 0 0 0 3px rgba(13, 110, 253, .2);
        }

        .product-image-preview {
            align-items: center;
            background: #ffffff;
            border: 1px solid #d1d5db;
            border-radius: 8px;
            box-shadow: 0 8px 24px rgba(15, 23, 42, .18);
            display: none;
            height: 190px;
            justify-content: center;
            left: calc(100% + 10px);
            padding: 10px;
            position: absolute;
            top: 50%;
            transform: translateY(-50%);
            width: 240px;
            z-index: 1080;
        }

        .product-image-preview::before {
            background: #ffffff;
            border-bottom: 1px solid #d1d5db;
            border-left: 1px solid #d1d5db;
            content: '';
            height: 12px;
            left: -7px;
            position: absolute;
            top: calc(50% - 6px);
            transform: rotate(45deg);
            width: 12px;
        }

        .product-image-preview img {
            height: 100%;
            object-fit: contain;
            width: 100%;
        }

        .product-thumbnail-wrap:hover .product-image-preview,
        .product-thumbnail-wrap:focus .product-image-preview {
            display: flex;
        }

        .product-id-link {
            color: #0864b1;
            font-weight: 400;
            text-decoration: none;
        }

        .product-id-link:hover,
        .product-id-link:focus-visible {
            color: #0864b1;
            text-decoration: underline;
        }

        .product-name {
            color: #4e565f;
            font-size: 12px;
            font-weight: 400;
            line-height: 15px;
        }

        .product-actions {
            position: relative;
        }

        .product-actions .dropdown {
            display: inline-block;
        }

        .product-actions .product-actions-toggle {
            align-items: center;
            background: #ffffff;
            border: 1px solid #cdd2db;
            border-radius: 999px;
            color: #192027;
            display: inline-flex;
            font-size: 14px;
            height: 34px;
            justify-content: center;
            padding: 0;
            width: 34px;
        }

        .product-actions .product-actions-toggle:hover,
        .product-actions .product-actions-toggle:focus-visible {
            background: #f5f7fb;
            border-color: #94a3b8;
        }

        .product-actions .dropdown-menu {
            background: #ffffff;
            border: 1px solid #e5e7eb;
            border-radius: 10px;
            box-shadow: 0 10px 24px rgba(15, 23, 42, .12);
            position: absolute;
            z-index: 1055;
        }

        .product-add-group {
            background: #fafafb;
            border: 1px solid #cdd2db;
            border-radius: 20px;
            display: inline-flex;
            max-width: 100%;
            position: relative;
            width: 282px;
        }

        .product-add-button,
        .product-add-options,
        .product-operations-button {
            align-items: center;
            background: transparent;
            border: 0;
            color: #2d353d;
            display: inline-flex;
            font-size: 13px;
            font-weight: 400;
            height: 38px;
            justify-content: center;
            line-height: 18px;
            white-space: nowrap;
        }

        .product-add-button {
            border-radius: 20px 0 0 20px;
            flex: 1 1 auto;
            gap: 6px;
            min-width: 0;
            padding: 0 18px;
        }

        .product-add-button .bi-plus-lg {
            font-size: 12px;
        }

        .product-add-button span {
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .product-add-options {
            border-left: 1px solid #cdd2db;
            border-radius: 0 20px 20px 0;
            flex: 0 0 38px;
            padding: 0;
            width: 38px;
        }

        .product-add-options .bi-chevron-down,
        .product-operations-button .bi-chevron-down {
            font-size: 9px;
        }

        .product-operations {
            margin-left: auto;
            max-width: 100%;
        }

        .product-operations-button {
            background: #fafafb;
            border: 1px solid #cdd2db;
            border-radius: 20px;
            color: #4e565f;
            gap: 6px;
            height: 40px;
            max-width: 100%;
            padding: 0 18px;
            width: 214px;
        }

        .product-add-button:hover,
        .product-add-options:hover,
        .product-operations-button:hover {
            background: #f0f2f5;
            color: #2d353d;
        }

        .product-add-button:focus-visible,
        .product-add-options:focus-visible,
        .product-operations-button:focus-visible {
            box-shadow: inset 0 0 0 2px #86b7fe;
        }

        .product-add-panel,
        .product-inline-form {
            background: #ffffff;
            border: 1px solid #e5e7eb;
            border-radius: 12px;
            margin-top: 16px;
            padding: 20px;
        }

        .product-inline-form {
            background: #f8fafc;
            margin-top: 0;
            padding: 14px;
        }

        .product-add-panel {
            margin: 0 0 24px;
        }

        .catalog-product-dialog {
            max-width: min(1500px, calc(100vw - 32px));
        }

        .catalog-product-modal-form {
            min-height: 0;
        }

        .catalog-product-modal-body {
            display: grid;
            grid-template-columns: minmax(0, 1.65fr) minmax(330px, .75fr);
            min-height: 560px;
            padding: 0;
        }

        .catalog-product-browser,
        .catalog-product-summary {
            min-height: 0;
            padding: 18px;
        }

        .catalog-product-browser {
            border-right: 1px solid #dfe5ec;
        }

        .catalog-product-search {
            display: grid;
            gap: 8px;
            grid-template-columns: minmax(0, 1fr) auto auto;
            margin-bottom: 14px;
        }

        .catalog-product-results {
            border: 1px solid #dfe5ec;
            border-radius: 6px;
            min-height: 420px;
            overflow: hidden;
            position: relative;
        }

        .catalog-product-results.is-loading::after {
            align-items: center;
            background: rgba(255, 255, 255, .82);
            content: "Wczytywanie...";
            display: flex;
            inset: 0;
            justify-content: center;
            position: absolute;
            z-index: 2;
        }

        .catalog-product-results-header,
        .catalog-product-result {
            align-items: center;
            display: grid;
            gap: 12px;
            grid-template-columns: minmax(0, 1fr) 92px 122px 82px;
        }

        .catalog-product-results-header {
            background: #f4f6f8;
            border-bottom: 1px solid #dfe5ec;
            color: #52606d;
            font-size: 11px;
            font-weight: 700;
            padding: 9px 12px;
            text-transform: uppercase;
        }

        .catalog-product-results-list {
            max-height: 470px;
            overflow-y: auto;
        }

        .catalog-product-result {
            border-bottom: 1px solid #e7ebf0;
            min-height: 58px;
            padding: 7px 12px;
        }

        .catalog-product-result.is-selected {
            background: #f2f7ff;
        }

        .catalog-product-result-main {
            align-items: center;
            display: flex;
            gap: 10px;
            min-width: 0;
        }

        .catalog-product-result-image,
        .catalog-selected-image {
            background: #f4f6f8;
            border: 1px solid #dfe5ec;
            border-radius: 4px;
            height: 42px;
            object-fit: contain;
            width: 42px;
        }

        .catalog-product-result-placeholder,
        .catalog-selected-placeholder {
            align-items: center;
            color: #7a8795;
            display: inline-flex;
            flex: 0 0 42px;
            justify-content: center;
        }

        .catalog-product-result-copy {
            display: grid;
            min-width: 0;
        }

        .catalog-product-result-copy strong {
            font-size: 13px;
            overflow-wrap: anywhere;
        }

        .catalog-product-result-copy span,
        .catalog-selected-catalog-price {
            color: #6b7280;
            font-size: 12px;
        }

        .catalog-product-result-stock,
        .catalog-product-result-price {
            font-size: 13px;
            text-align: right;
        }

        .catalog-product-result-stock.is-negative,
        .catalog-selected-stock-warning {
            color: #b42318;
        }

        .catalog-product-add {
            white-space: nowrap;
        }

        .catalog-product-results-empty {
            color: #6b7280;
            padding: 48px 16px;
            text-align: center;
        }

        .catalog-product-pagination {
            align-items: center;
            background: #f8fafc;
            display: flex;
            gap: 10px;
            justify-content: flex-end;
            padding: 9px 12px;
        }

        .catalog-product-pagination.is-single-page {
            color: #6b7280;
            font-size: 12px;
        }

        .catalog-product-summary {
            background: #fbfcfd;
            display: flex;
            flex-direction: column;
        }

        .catalog-product-summary h3 {
            font-size: 15px;
            margin: 0 0 14px;
        }

        .catalog-selected-items {
            display: grid;
            gap: 10px;
            max-height: 425px;
            overflow-y: auto;
        }

        .catalog-selected-empty {
            border: 1px dashed #cfd7e1;
            border-radius: 6px;
            color: #6b7280;
            padding: 28px 16px;
            text-align: center;
        }

        .catalog-selected-item {
            background: #fff;
            border: 1px solid #dfe5ec;
            border-radius: 6px;
            padding: 10px;
        }

        .catalog-selected-head {
            align-items: start;
            display: grid;
            gap: 8px;
            grid-template-columns: 42px minmax(0, 1fr) 32px;
        }

        .catalog-selected-copy {
            display: grid;
            gap: 2px;
            min-width: 0;
        }

        .catalog-selected-copy strong {
            font-size: 13px;
            overflow-wrap: anywhere;
        }

        .catalog-selected-fields {
            display: grid;
            gap: 8px;
            grid-template-columns: 92px minmax(130px, 1fr);
            margin-top: 10px;
        }

        .catalog-selected-fields label {
            color: #52606d;
            font-size: 11px;
            margin-bottom: 3px;
        }

        .catalog-selected-price-wrap {
            align-items: center;
            display: grid;
            gap: 6px;
            grid-template-columns: minmax(0, 1fr) auto auto;
        }

        .catalog-selected-stock-warning {
            display: block;
            font-size: 11px;
            margin-top: 6px;
        }

        .catalog-product-summary-total {
            align-items: baseline;
            border-top: 1px solid #dfe5ec;
            display: flex;
            justify-content: space-between;
            margin-top: auto;
            padding-top: 16px;
        }

        .catalog-product-summary-total strong {
            font-size: 18px;
        }

        .catalog-product-summary-total-controls {
            align-items: center;
            display: flex;
            gap: 8px;
        }

        .catalog-product-summary-total-controls .form-select {
            min-width: 88px;
            width: auto;
        }

        @media (max-width: 991.98px) {
            .catalog-product-dialog {
                margin: 8px auto;
                max-width: calc(100vw - 16px);
            }

            .catalog-product-modal-body {
                display: block;
            }

            .catalog-product-browser {
                border-bottom: 1px solid #dfe5ec;
                border-right: 0;
            }

            .catalog-product-results-list,
            .catalog-selected-items {
                max-height: 360px;
            }
        }

        @media (max-width: 575.98px) {
            .catalog-product-search {
                grid-template-columns: 1fr 1fr;
            }

            .catalog-product-search .form-control {
                grid-column: 1 / -1;
            }

            .catalog-product-results-header {
                display: none;
            }

            .catalog-product-result {
                gap: 8px;
                grid-template-columns: minmax(0, 1fr) auto;
            }

            .catalog-product-result-stock,
            .catalog-product-result-price {
                text-align: left;
            }

            .catalog-product-result .catalog-product-add {
                grid-column: 2;
                grid-row: 1 / span 3;
            }
        }

        .order-info-panel {
            font-size: 13px;
        }

        .order-info-paid {
            align-items: center;
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
        }

        .order-info-paid .badge {
            font-size: 13px;
            padding: 6px 10px;
        }

        .order-info-section {
            border-top: 1px solid #eef0f3;
            margin-top: 8px;
            padding-top: 8px;
        }

        .inline-field-row,
        .order-info-row {
            align-items: baseline;
            display: grid;
            gap: 10px;
            grid-template-columns: 126px minmax(0, 1fr);
            min-height: var(--order-detail-line-height);
            padding: 0 6px;
            position: relative;
        }

        .order-info-row + .order-info-row {
            margin-top: 2px;
        }

        .inline-field-row .nex-value,
        .order-info-row .nex-value {
            line-height: var(--order-detail-line-height);
            min-height: var(--order-detail-line-height);
        }

        .inline-field-row .nex-label,
        .order-info-row .nex-label {
            align-self: center;
            line-height: var(--order-detail-line-height);
            margin-bottom: 0;
            min-width: 0;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .inline-field-row:hover,
        .order-info-row:hover {
            cursor: pointer;
        }

        .inline-edit-value {
            background: transparent;
            border: 0;
            color: inherit;
            cursor: pointer;
            display: inline-block;
            font: inherit;
            font-weight: 400;
            line-height: inherit;
            margin-left: -24px;
            max-width: calc(100% + 24px);
            min-height: var(--order-detail-line-height);
            min-width: 0;
            overflow-wrap: anywhere;
            padding: 0 0 0 24px;
            position: relative;
            text-align: left;
            vertical-align: baseline;
            white-space: normal;
        }

        .inline-edit-value:hover,
        .inline-edit-value:focus-visible,
        .inline-field-row:not(.inline-split-row)[data-edit-field]:hover .inline-edit-value {
            color: #0077da;
        }

        .inline-edit-value:hover .inline-edit-text,
        .inline-edit-value:focus-visible .inline-edit-text,
        .inline-field-row:not(.inline-split-row)[data-edit-field]:hover .inline-edit-text {
            color: inherit;
            text-decoration: underline;
        }

        .inline-field-row .inline-postal-city {
            flex-wrap: wrap;
            gap: 0 8px;
            justify-content: flex-start;
        }

        .inline-postal-city .inline-edit-value + .inline-edit-value {
            margin-left: 0;
            margin-right: -24px;
            max-width: 100%;
            padding: 0 24px 0 0;
        }

        .inline-postal-city .inline-edit-value + .inline-edit-value .inline-pencil {
            left: auto;
            right: 0;
        }

        .inline-section-edit-button {
            display: none;
        }

        .inline-section .inline-section-edit,
        .paid-amount-edit {
            display: none;
        }

        .inline-section.is-editing .inline-section-view {
            display: none;
        }

        .inline-section.is-editing .inline-section-edit {
            display: block;
        }

        .inline-edit-trigger {
            cursor: pointer;
            position: relative;
        }

        .inline-edit-trigger:hover {
            background: transparent;
        }

        .inline-pencil {
            align-items: center;
            background: #ffffff;
            border: 1px solid currentColor;
            border-radius: 50%;
            color: inherit;
            display: inline-flex;
            font-size: 10px;
            height: 18px;
            justify-content: center;
            left: 0;
            line-height: 1;
            opacity: 0;
            position: absolute;
            top: calc(var(--order-detail-line-height) / 2);
            transform: translateY(-50%);
            transition: opacity .15s ease;
            visibility: hidden;
            width: 18px;
        }

        .inline-edit-value:hover .inline-pencil,
        .inline-edit-value:focus-visible .inline-pencil,
        .inline-field-row:not(.inline-split-row)[data-edit-field]:hover .inline-pencil {
            opacity: 1;
            visibility: visible;
        }

        .inline-section-edit {
            background: transparent;
        }

        .ajax-update-loading-host {
            position: relative !important;
        }

        .ajax-update-loading-overlay {
            align-items: center;
            background: #2694cf;
            border-radius: 5px;
            box-shadow: 0 2px 7px rgba(15, 23, 42, .2);
            display: flex;
            height: 52px;
            justify-content: center;
            left: 50%;
            max-width: calc(100% - 12px);
            pointer-events: none;
            position: absolute;
            top: 50%;
            transform: translate(-50%, -50%);
            width: 200px;
            z-index: 1100;
        }

        .ajax-update-loading-dots {
            align-items: center;
            display: inline-flex;
            gap: 12px;
        }

        .ajax-update-loading-dot {
            animation: ajax-update-dot 1.05s ease-in-out infinite;
            background: #ffffff;
            border-radius: 50%;
            height: 8px;
            opacity: .35;
            transform: scale(.78);
            width: 8px;
        }

        .ajax-update-loading-dot:nth-child(2) {
            animation-delay: .16s;
        }

        .ajax-update-loading-dot:nth-child(3) {
            animation-delay: .32s;
        }

        @keyframes ajax-update-dot {
            0%, 60%, 100% {
                opacity: .35;
                transform: scale(.78);
            }

            30% {
                opacity: 1;
                transform: scale(1);
            }
        }

        @media (prefers-reduced-motion: reduce) {
            .ajax-update-loading-dot {
                animation: none;
                opacity: 1;
                transform: none;
            }
        }

        .inline-section .nex-label,
        .inline-section-edit .form-label {
            letter-spacing: 0;
            text-transform: none;
        }

        .inline-section .nex-label {
            color: #7a838f;
            font-size: 12px;
        }

        .inline-section-edit .row.g-2 {
            display: grid;
            gap: 4px;
            margin: 0;
        }

        .inline-section.is-editing .nex-card-header {
            border-bottom: 0;
            padding: 14px 12px 12px;
        }

        .inline-section.is-editing .nex-card-title {
            color: #192027;
            font-size: 16px;
            font-weight: 400;
            line-height: 22px;
        }

        .inline-section.is-editing .nex-card-header > :not(.nex-card-title) {
            display: none !important;
        }

        .inline-section.is-editing .nex-card-body {
            padding: 12px;
        }

        .inline-section-edit .row.g-2 > [class*="col-"] {
            align-items: center;
            display: grid;
            gap: 10px;
            grid-template-columns: 115px minmax(0, 1fr);
            margin-top: 0;
            max-width: none;
            min-height: 27px;
            padding: 0;
            width: 100%;
        }

        .inline-section-edit .form-label {
            color: #4e565f;
            font-size: 12px;
            letter-spacing: 0;
            line-height: 18px;
            margin: 0;
            min-width: 0;
            overflow: hidden;
            text-overflow: ellipsis;
            text-transform: none;
            white-space: nowrap;
        }

        .inline-section-edit .form-control,
        .inline-section-edit .form-select {
            background-color: #ffffff;
            border: 1px solid #cdd2db;
            border-radius: 3px;
            color: #4e565f;
            font-size: 13px;
            height: 27px;
            line-height: 19px;
            min-height: 27px;
            padding: 3px 12px;
        }

        .inline-section-edit .form-label::after {
            content: ':';
        }

        .inline-section-edit .form-select {
            padding-right: 30px;
        }

        .inline-section-edit textarea.form-control {
            min-height: 64px;
            resize: vertical;
        }

        .inline-section-edit .form-control:focus,
        .inline-section-edit .form-select:focus {
            border-color: #0d6efd;
            box-shadow: 0 0 0 2px rgba(13, 110, 253, .12);
        }

        .billing-gus-control {
            display: flex;
            gap: 6px;
        }

        .billing-gus-control .form-control {
            min-width: 0;
        }

        .billing-gus-button {
            background: #e5e7eb;
            border-color: #cbd5e1;
            color: #374151;
            flex: 0 0 auto;
            font-size: 11px;
            font-weight: 700;
            line-height: 1;
            min-height: 26px;
            padding: 4px 8px;
        }

        .billing-gus-message {
            font-size: 11px;
            margin-top: 4px;
            min-height: 14px;
        }

        .billing-gus-message:empty {
            display: none;
        }

        .billing-gus-results {
            align-items: center;
            display: grid;
            gap: 6px;
            grid-template-columns: minmax(0, 1fr) auto;
            margin-top: 6px;
        }

        .billing-gus-results[hidden] {
            display: none;
        }

        .inline-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 9px;
            justify-content: flex-start;
            margin-top: 24px;
            padding: 0 0 3px 3px;
        }

        .inline-actions .btn {
            align-items: center;
            border-radius: 20px;
            display: inline-flex;
            font-size: 13px;
            height: 40px;
            justify-content: center;
            line-height: 18px;
            min-width: 80px;
            padding: 0 20px;
        }

        .inline-actions .inline-save {
            background: #0077da;
            border: 1px solid #0077da;
            color: #ffffff;
            font-weight: 600;
        }

        .inline-actions .inline-save:hover {
            background: #0068bf;
            border-color: #0068bf;
        }

        .inline-actions .inline-cancel {
            background: #fafafb;
            border: 1px solid #cdd2db;
            color: #4e565f;
            font-weight: 400;
        }

        .inline-actions .inline-cancel:hover {
            background: #f0f2f5;
            border-color: #94a3b8;
        }

        .paid-amount-row {
            align-items: center;
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
            min-height: 28px;
            padding: 0 6px 2px;
        }

        .paid-amount-row .badge {
            border-radius: 4px;
            cursor: pointer;
            font-size: 12px;
            padding: 4px 7px;
        }

        .mini-icon-button {
            align-items: center;
            background: #ffffff;
            border: 1px solid #d8dee7;
            border-radius: 4px;
            color: #475569;
            display: inline-flex;
            font-size: 12px;
            height: 24px;
            justify-content: center;
            line-height: 1;
            padding: 0 7px;
            text-decoration: none;
        }

        .mini-icon-button:hover {
            background: #eff6ff;
            border-color: #93c5fd;
            color: #0d6efd;
        }

        .paid-amount-action-group {
            display: inline-flex;
            margin-left: auto;
        }

        .paid-amount-action-group > form {
            display: flex;
            margin: 0;
        }

        .paid-amount-action-button {
            align-items: center;
            background: #ffffff;
            border: 1px solid #cfd5dc;
            border-radius: 0;
            color: #4b5563;
            display: inline-flex;
            font-size: 13px;
            height: 32px;
            justify-content: center;
            line-height: 1;
            margin-left: -1px;
            padding: 0;
            width: 32px;
        }

        .paid-amount-action-group > form:first-child .paid-amount-action-button {
            border-radius: 4px 0 0 4px;
            margin-left: 0;
        }

        .paid-amount-action-group > .paid-amount-action-button:last-child {
            border-radius: 0 4px 4px 0;
        }

        .paid-amount-action-button:hover:not(:disabled),
        .paid-amount-action-button:focus-visible:not(:disabled) {
            background: #f8fafc;
            border-color: #94a3b8;
            color: #0d6efd;
            position: relative;
            z-index: 1;
        }

        .paid-amount-action-button:disabled {
            background: #ffffff;
            color: #4b5563;
            cursor: not-allowed;
            opacity: 1;
        }

        .paid-amount-action-button.is-paynow-action {
            color: #0d6efd;
        }

        .paid-amount-action-button.is-paynow-action .spinner-border {
            height: 13px;
            width: 13px;
        }

        .paynow-link-tooltip {
            --bs-tooltip-bg: rgba(31, 41, 55, .94);
            --bs-tooltip-max-width: 270px;
        }

        .paynow-link-tooltip .tooltip-inner {
            font-size: 12px;
            line-height: 1.45;
            padding: 6px 10px;
        }

        .paid-amount-edit.is-editing {
            display: flex;
            padding: 0 6px 4px;
        }

        .paid-amount-input {
            max-width: 120px;
            min-height: 26px;
            padding: 3px 7px;
        }

        .placeholder-card {
            color: #64748b;
            font-size: 13px;
            min-height: 132px;
        }

        .shipments-card,
        .shipments-card .nex-card-body {
            overflow: visible;
        }

        .shipments-card .nex-card-header {
            border-bottom: 0;
            min-height: 54px;
            padding: 15px 18px 8px;
        }

        .shipments-card .nex-card-body {
            padding: 8px 10px 12px;
        }

        .shipments-card-title {
            align-items: center;
            display: inline-flex;
            font-size: 18px;
            gap: 12px;
        }

        .shipments-card-title::before {
            background: #0d8de1;
            border-radius: 50%;
            content: '';
            height: 10px;
            width: 10px;
        }

        .shipments-empty-copy {
            color: #475569;
            font-size: 13px;
            margin: 2px 2px 16px;
        }

        [data-shipment-ajax-notice] {
            margin: 2px 2px 10px;
        }

        .courier-tabs {
            align-items: center;
            display: flex;
            flex-wrap: wrap;
            gap: 5px;
            margin: 0 2px;
        }

        .courier-tab {
            background: #fff;
            border: 1px solid #0d8de1;
            border-radius: 4px;
            color: #0877c9;
            font-size: 12px;
            line-height: 1;
            min-height: 29px;
            padding: 6px 13px;
            white-space: nowrap;
        }

        .courier-tab:hover:not(:disabled),
        .courier-tab.is-active {
            background: #087dcc;
            border-color: #087dcc;
            color: #fff;
        }

        .courier-tab:disabled {
            background: #fff;
            color: #0877c9;
            cursor: not-allowed;
            opacity: 1;
        }

        .shipments-form-wrap {
            border-top: 1px solid #dce3eb;
            margin: 14px -10px 0;
            padding: 16px 10px 0;
        }

        [data-courier-form-host].ajax-update-loading-host {
            min-height: 84px;
        }

        .shipments-form-panel {
            background: #fff;
            border: 0;
            margin: 0;
            padding: 0 4px 4px;
        }

        .shipment-form-row {
            align-items: center;
            display: grid;
            gap: 12px;
            grid-template-columns: 160px minmax(0, 590px);
            margin-bottom: 10px;
        }

        .shipment-form-row.is-top {
            align-items: start;
        }

        .shipment-form-label {
            color: #475569;
            font-size: 12px;
            margin: 0;
            text-align: right;
        }

        .shipment-size-options {
            display: grid;
            gap: 7px;
        }

        .shipment-size-option {
            align-items: center;
            color: #475569;
            display: flex;
            font-size: 12px;
            gap: 7px;
        }

        .shipment-size-option input {
            height: 18px;
            margin: 0;
            width: 18px;
        }

        .shipment-side-panel {
            background: #fbfcfe;
            border: 1px solid #d8dee7;
            border-radius: 7px;
            min-height: 104px;
            padding: 18px;
        }

        .shipment-side-panel label {
            color: #475569;
            display: block;
            font-size: 12px;
            margin-bottom: 6px;
        }

        .courier-parcels-panel {
            background: #fbfcfe;
            border: 1px solid #d8dee7;
            border-radius: 7px;
            padding: 14px;
        }

        .courier-parcels-header {
            align-items: center;
            display: flex;
            justify-content: space-between;
            margin-bottom: 10px;
        }

        .courier-parcels-title {
            color: #334155;
            font-size: 12px;
            font-weight: 600;
            margin: 0;
        }

        .courier-parcel-row {
            align-items: end;
            border-bottom: 1px solid #e2e8f0;
            display: grid;
            gap: 8px;
            grid-template-columns: repeat(4, minmax(65px, 1fr)) 76px 34px;
            padding: 10px 0;
        }

        .courier-parcel-row.is-dpd {
            grid-template-columns: repeat(4, minmax(65px, 1fr)) minmax(100px, 132px) 34px;
        }

        .courier-parcel-row:first-child {
            padding-top: 0;
        }

        .courier-parcel-row:last-child {
            border-bottom: 0;
        }

        .courier-parcel-field label {
            color: #64748b;
            display: block;
            font-size: 12px;
            margin-bottom: 3px;
        }

        .courier-parcel-field .form-control {
            font-size: 12px;
            min-height: 31px;
        }

        .courier-parcel-nonstandard {
            align-items: center;
            display: flex;
            flex-direction: column;
            min-width: 0;
        }

        .courier-parcel-nonstandard > span,
        .courier-parcel-template-field label {
            color: #64748b;
            display: block;
            font-size: 12px;
            margin-bottom: 3px;
            text-align: center;
            white-space: nowrap;
        }

        .courier-parcel-nonstandard-control {
            align-items: center;
            cursor: pointer;
            display: flex;
            justify-content: center;
            margin: 0;
            min-height: 31px;
        }

        .courier-parcel-template-field .form-select {
            font-size: 12px;
            min-height: 31px;
            padding-bottom: 3px;
            padding-top: 3px;
        }

        .courier-parcel-remove {
            align-items: center;
            border-radius: 50%;
            display: inline-flex;
            height: 30px;
            justify-content: center;
            padding: 0;
            width: 30px;
        }

        .courier-volume-note {
            color: #64748b;
            font-size: 10px;
            grid-column: 1 / -1;
            margin-top: -2px;
        }

        .shipment-count-input {
            max-width: 70px;
            width: 70px;
        }

        .shipment-recipient-summary {
            color: #64748b;
            font-size: 12px;
            margin-top: 14px;
        }

        .shipment-submit {
            border-radius: 18px;
            font-size: 12px;
            font-weight: 600;
            padding: 7px 16px;
        }

        .shipments-table {
            font-size: 12px;
            margin: 8px 0 14px;
            min-width: 900px;
        }

        .shipments-table-wrap {
            overflow: visible;
        }

        .shipments-table thead th {
            background: #fff;
            border-bottom: 1px solid #cfd7e2;
            color: #111827;
            font-size: 10px;
            font-weight: 700;
            padding: 8px 6px;
            text-transform: uppercase;
            white-space: nowrap;
        }

        .shipments-table tbody td {
            border-bottom: 1px solid #e2e8f0;
            color: #475569;
            padding: 9px 6px;
            vertical-align: middle;
        }

        .shipments-table tbody tr:last-child td {
            border-bottom: 0;
        }

        .shipments-table .shipment-status-line {
            align-items: center;
            display: flex;
            gap: 7px;
            min-width: 210px;
        }

        .shipments-table .shipment-status-track {
            background: #edf0f3;
            border-radius: 999px;
            flex: 0 0 72px;
            height: 7px;
            overflow: hidden;
        }

        .shipments-table .shipment-status-fill {
            background: #0783dc;
            border-radius: inherit;
            display: block;
            height: 100%;
        }

        .shipments-table .shipment-status-fill.is-success {
            background: #16834b;
        }

        .shipments-table .shipment-status-fill.is-error {
            background: #dc3545;
        }

        .shipment-provider {
            align-items: center;
            color: #0877c9;
            display: inline-flex;
            gap: 8px;
            text-decoration: none;
        }

        a.shipment-provider:hover {
            color: #005f9f;
            text-decoration: underline;
        }

        .shipment-provider-check {
            align-items: center;
            border: 1px solid #15945f;
            border-radius: 2px;
            color: #15945f;
            display: inline-flex;
            font-size: 8px;
            height: 11px;
            justify-content: center;
            width: 11px;
        }

        .shipment-tracking-number {
            color: #0074c8;
            font-weight: 500;
            text-decoration: none;
        }

        a.shipment-tracking-number:hover {
            color: #005f9f;
            text-decoration: underline;
        }

        .shipment-actions {
            align-items: center;
            display: flex;
            gap: 5px;
            justify-content: flex-end;
            white-space: nowrap;
        }

        .shipment-label-group {
            align-items: stretch;
            display: inline-flex;
        }

        .shipment-label-group .btn {
            border-color: #d3dae4;
            color: #334155;
            font-size: 9px;
            font-weight: 700;
            min-height: 28px;
            text-transform: uppercase;
        }

        .shipment-label-group > .btn {
            border-radius: 4px 0 0 4px;
        }

        .shipment-label-group .nex-printer-print-dropdown {
            margin-left: -1px;
        }

        .shipment-label-group .shipment-label-print-button {
            border-radius: 0 4px 4px 0;
            min-width: 30px;
            padding-left: 8px;
            padding-right: 8px;
        }

        .shipment-label-group .nex-printer-print-menu {
            min-width: 280px;
        }

        .shipment-menu-button {
            align-items: center;
            background: #fff;
            border: 1px solid #d3dae4;
            border-radius: 50%;
            color: #475569;
            display: inline-flex;
            font-size: 18px;
            height: 34px;
            justify-content: center;
            line-height: 1;
            padding: 0 0 4px;
            width: 34px;
        }

        .shipment-actions .dropdown-menu {
            font-size: 12px;
            min-width: 170px;
            z-index: 1060;
        }

        .status-icon {
            align-items: center;
            border-radius: 4px;
            display: inline-flex;
            font-size: 11px;
            font-weight: 700;
            height: 24px;
            justify-content: center;
            width: 24px;
        }

        .nex-timeline-item {
            border-bottom: 1px solid #eef0f3;
            padding: 10px 0;
        }

        .nex-timeline-item:last-child {
            border-bottom: 0;
        }

        .order-communication-grid {
            align-items: start;
            display: grid;
            gap: 16px;
            grid-template-columns: minmax(0, 2fr) minmax(300px, 1fr);
        }

        .order-email-card,
        .order-additional-card {
            min-width: 0;
        }

        .order-buyer-comment { background: #ccebf6; border-top: 3px solid #3190c9; color: #2d353d; margin: 6px 0 20px; font-size: 13px; }
        .order-buyer-comment-header { display: grid; grid-template-columns: 170px 210px minmax(0, 1fr); align-items: center; gap: 10px; padding: 24px 6px 16px; border-bottom: 1px solid #c1dce6; }
        .order-buyer-comment-header time { font-style: italic; }
        .order-buyer-comment-header > * { min-width: 0; overflow-wrap: anywhere; }
        .order-buyer-comment-label { justify-self: start; background: #3190c9; color: #fff; border-radius: 2px; padding: 3px 5px; font-size: 11px; font-weight: 600; }
        .order-buyer-comment-body { white-space: pre-wrap; overflow-wrap: anywhere; padding: 15px; line-height: 22px; }
        @media (max-width: 650px) { .order-buyer-comment-header { grid-template-columns: minmax(0, 1fr) minmax(0, 1fr); padding: 14px 8px; } .order-buyer-comment-label { grid-column: 1 / -1; } }

        .order-email-card-body {
            padding: 16px;
        }

        .order-email-compose {
            background: #f8fafc;
            border: 1px solid #e5e7eb;
            border-radius: 6px;
            display: grid;
            gap: 10px;
            margin-bottom: 16px;
            padding: 14px;
        }

        .order-email-form-row {
            align-items: center;
            display: grid;
            gap: 12px;
            grid-template-columns: 136px minmax(0, 1fr);
        }

        .order-email-type-options {
            align-items: center;
            display: flex;
            gap: 22px;
        }

        .order-email-editor-shell {
            background: #ffffff;
            border: 1px solid #cfd6df;
            border-radius: 5px;
            overflow: hidden;
        }

        .order-email-toolbar {
            align-items: center;
            border-bottom: 1px solid #cfd6df;
            display: flex;
            flex-wrap: wrap;
            gap: 3px;
            min-height: 39px;
            padding: 4px;
        }

        .email-editor-button,
        .email-editor-select,
        .email-editor-color {
            align-items: center;
            background: #ffffff;
            border: 1px solid #d5dbe3;
            border-radius: 4px;
            color: #334155;
            display: inline-flex;
            height: 29px;
            justify-content: center;
        }

        .email-editor-button {
            padding: 0;
            width: 30px;
        }

        .email-editor-button:hover,
        .email-editor-button:focus-visible {
            background: #eff6ff;
            border-color: #93c5fd;
            color: #0d6efd;
        }

        .email-editor-button.is-active {
            background: #dbeafe;
            border-color: #60a5fa;
        }

        .email-editor-select {
            font-size: 12px;
            padding: 0 22px 0 7px;
            width: 78px;
        }

        .email-editor-size {
            width: 68px;
        }

        .email-editor-color {
            cursor: pointer;
            gap: 2px;
            padding: 0 5px;
        }

        .email-editor-color input {
            border: 0;
            height: 18px;
            padding: 0;
            width: 18px;
        }

        .order-email-editor,
        .order-email-source {
            border: 0;
            color: #111827;
            display: block;
            font-family: Arial, sans-serif;
            font-size: 14px;
            line-height: 1.55;
            min-height: 330px;
            outline: 0;
            overflow: auto;
            padding: 12px;
            resize: vertical;
            width: 100%;
        }

        .order-email-editor.is-plain-text {
            white-space: pre-wrap;
        }

        .order-email-editor:empty::before {
            color: #94a3b8;
            content: 'Treść wiadomości';
        }

        .order-email-source {
            font-family: Consolas, monospace;
        }

        .order-email-attachment-preview {
            color: #475569;
            display: flex;
            flex-wrap: wrap;
            font-size: 12px;
            gap: 8px;
        }

        .order-email-attachment-preview span {
            background: #ffffff;
            border: 1px solid #d9e0e8;
            border-radius: 4px;
            padding: 4px 8px;
        }

        .order-email-empty {
            color: #64748b;
            font-size: 13px;
            margin-bottom: 14px;
        }

        .order-email-compose-toggle {
            align-items: center;
            border-radius: 20px;
            display: inline-flex;
            margin-top: 14px;
            padding: 7px 14px;
        }

        .order-email-messages:empty {
            display: none;
        }

        .purchase-readonly [data-edit-field]:not([data-edit-field="notes"]) { pointer-events: none; }
        .purchase-readonly [data-edit-field]:not([data-edit-field="notes"]) .inline-pencil { display: none; }
        .purchase-readonly .inline-edit-trigger:not([data-edit-section="order-info"]) { cursor: default; }

        .order-email-message {
            border-bottom: 1px solid #dfe4ea;
            padding: 14px 0 18px;
        }

        .order-email-message:first-child {
            padding-top: 0;
        }

        .order-email-message:last-child {
            border-bottom: 0;
        }

        .order-email-message-meta {
            align-items: center;
            color: #475569;
            display: flex;
            flex-wrap: wrap;
            font-size: 12px;
            gap: 10px 18px;
            margin-bottom: 10px;
        }

        .order-email-channel {
            background: #1f2937;
            border-radius: 3px;
            color: #ffffff;
            font-size: 11px;
            padding: 2px 5px;
        }

        .order-email-status {
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
            padding: 3px 8px;
        }

        .order-email-status.is-sent {
            background: #dcfce7;
            color: #166534;
        }

        .order-email-status.is-failed,
        .order-email-status.is-unknown {
            background: #fee2e2;
            color: #991b1b;
        }

        .order-email-status.is-pending {
            background: #e0f2fe;
            color: #075985;
        }

        .order-email-message-heading {
            border-bottom: 1px solid #eef0f3;
            font-size: 13px;
            padding: 8px 10px;
        }

        .order-email-message-body {
            color: #334155;
            font-size: 13px;
            line-height: 1.55;
            overflow-wrap: anywhere;
            padding: 14px 10px;
        }

        .order-email-message-body img {
            height: auto;
            max-width: 100%;
        }

        .order-email-message-attachments {
            border-top: 1px solid #eef0f3;
            display: grid;
            font-size: 12px;
            gap: 5px;
            padding: 10px;
        }

        .order-email-message-attachments a {
            align-items: center;
            display: inline-flex;
            gap: 4px;
            width: fit-content;
        }

        .order-information-accordion .accordion-item {
            border: 1px solid #d6dde6;
            border-radius: 5px;
            overflow: hidden;
        }

        .order-information-accordion .accordion-button {
            background: #ffffff;
            box-shadow: none;
            color: #1f2937;
            font-size: 13px;
            font-weight: 600;
            padding: 10px 12px;
        }

        .order-information-accordion .accordion-body {
            border-top: 1px solid #e5e7eb;
            padding: 8px 12px;
        }

        .order-information-accordion .order-automation-item {
            border: 0;
            border-radius: 0;
        }

        .order-information-accordion .order-automation-toggle {
            background: #fcfcfd;
            border: 1px solid #cfd6e2;
            border-radius: 3px;
            font-weight: 400;
        }

        .order-automation-toggle::after { display: none; }
        .order-automation-toggle:not(.collapsed) .bi-chevron-down { transform: rotate(180deg); }

        .order-information-accordion .order-automation-item .accordion-body {
            border: 0;
            padding: 8px 0;
        }

        .order-automation-history {
            color: #4b5563;
            font-size: 13px;
            line-height: 20px;
        }

        .order-automation-history-heading {
            border-bottom: 1px solid #cfd6e2;
            padding: 3px 0 5px;
            text-align: center;
        }

        .order-automation-run { margin-top: 5px; }

        .order-automation-run-heading {
            display: grid;
            gap: 4px 12px;
            grid-template-columns: minmax(0, 1.2fr) minmax(0, 1fr);
            margin: 0 4px 7px;
            overflow-wrap: anywhere;
        }

        .order-automation-run-heading strong { font-weight: 600; }

        .order-automation-steps {
            border-collapse: separate;
            border-spacing: 0;
            color: #17212c;
            font-size: 11px;
            line-height: 14px;
            margin: 0 0 8px;
            table-layout: fixed;
            width: 100%;
        }

        .order-automation-status-column { width: 26px; }
        .order-automation-action-column { width: 37%; }

        .order-automation-steps td {
            border-bottom: 1px solid #cfd6e2;
            border-right: 1px solid #cfd6e2;
            padding: 3px 5px;
            overflow-wrap: anywhere;
            text-align: center;
            vertical-align: middle;
        }

        .order-automation-steps tr:first-child td { border-top: 1px solid #cfd6e2; }
        .order-automation-step-action { background: #ededed; }

        .order-automation-steps td.order-automation-step-status {
            background: #edf0f3;
            border-color: #d6dde6;
            color: #596674;
            font-size: 13px;
            padding: 3px;
        }

        .order-automation-steps td.order-automation-step-status.is-completed {
            background: #157347;
            border-color: #157347;
            color: #ffffff;
        }

        .order-automation-steps td.order-automation-step-status.is-failed {
            background: #c63838;
            border-color: #c63838;
            color: #ffffff;
        }

        .order-automation-steps td.order-automation-step-status.is-running {
            background: #0077da;
            border-color: #0077da;
            color: #ffffff;
        }

        .order-automation-steps td.order-automation-step-status.is-waiting,
        .order-automation-steps td.order-automation-step-status.is-uncertain {
            background: #f58a00;
            border-color: #f58a00;
            color: #ffffff;
        }

        .order-automation-step-error { color: #b42318; overflow-wrap: anywhere; }
        .order-automation-empty { color: #6b7280; padding: 8px 4px; }

        .order-automation-pagination {
            align-items: center;
            display: flex;
            gap: 10px;
            justify-content: center;
            padding: 8px 0 2px;
        }

        .order-automation-pagination button {
            align-items: center;
            background: #ffffff;
            border: 1px solid #cfd6e2;
            border-radius: 4px;
            color: #344054;
            display: inline-flex;
            height: 28px;
            justify-content: center;
            width: 28px;
        }

        .order-automation-pagination button:disabled { opacity: 0.4; }
        .order-automation-pagination button:not(:disabled):hover { background: #f1f7fe; }

        .allegro-order-details,
        .allegro-order-fees {
            color: #4b6580;
            font-size: 13px;
            font-weight: 400;
            line-height: 20px;
            overflow-wrap: anywhere;
        }

        .allegro-order-details a {
            color: #0077da;
        }

        .allegro-inline-link {
            background: transparent;
            border: 0;
            color: #0077da;
            font: inherit;
            padding: 0;
            text-decoration: underline;
        }

        .allegro-buyer-message {
            white-space: pre-wrap;
        }

        .allegro-delivery-windows,
        .allegro-order-fees {
            border-collapse: collapse;
            width: 100%;
        }

        .allegro-delivery-windows td {
            font-size: 12px;
            line-height: 15px;
            padding: 6px 5px;
            vertical-align: top;
        }

        .allegro-delivery-windows tr + tr {
            border-top: 1px solid #cfd6e2;
        }

        .allegro-delivery-windows td:first-child {
            width: 24%;
        }

        .allegro-delivery-windows td:last-child {
            text-align: right;
        }

        .allegro-detail-badge {
            border-radius: 2px;
            color: #ffffff;
            display: inline-block;
            font-size: 11px;
            font-weight: 600;
            line-height: 20px;
            padding: 0 5px;
            white-space: nowrap;
        }

        .allegro-detail-badge.is-dispatch {
            background: #f58a00;
        }

        .allegro-detail-badge.is-delivery {
            background: #137642;
        }

        .allegro-detail-group {
            margin-top: 20px;
        }

        .allegro-order-fees th,
        .allegro-order-fees td {
            font-weight: 400;
            padding: 5px;
            text-align: left;
        }

        .allegro-order-fees th {
            border-bottom: 1px solid #cfd6e2;
        }

        @media (max-width: 991.98px) {
            .order-communication-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 575.98px) {
            .order-email-form-row {
                align-items: stretch;
                gap: 5px;
                grid-template-columns: 1fr;
            }

            .order-email-editor,
            .order-email-source {
                min-height: 280px;
            }
        }
        @media (min-width: 992px) {
            .courier-parcel-row {
                column-gap: 5px;
                grid-template-columns: repeat(4, minmax(52px, 70px)) minmax(64px, 76px) minmax(100px, 132px) 30px;
                justify-content: start;
            }

            .courier-parcel-row.is-dpd {
                grid-template-columns: repeat(4, minmax(52px, 70px)) minmax(100px, 132px) 30px;
            }

            .courier-parcel-field {
                max-width: 70px;
                min-width: 0;
                width: 100%;
            }
        }

        @media (max-width: 991.98px) {
            .products-table-wrapper {
                overflow: auto;
            }

            .products-table {
                min-width: 800px;
            }

            .shipments-table-wrap {
                overflow-x: auto;
                padding-bottom: 4px;
            }

            .shipment-form-row {
                grid-template-columns: 125px minmax(0, 1fr);
            }

            .shipment-form-label {
                text-align: left;
            }

            .courier-parcel-row {
                grid-template-columns: repeat(2, minmax(90px, 1fr)) 76px 34px;
            }

            .courier-parcel-row.is-dpd {
                grid-template-columns: repeat(2, minmax(90px, 1fr)) minmax(100px, 1fr) 34px;
            }
            .nex-field-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }

        @media (max-width: 575.98px) {
            .shipment-form-row {
                gap: 4px;
                grid-template-columns: 1fr;
            }

            .courier-parcel-row {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }

            .courier-parcel-row.is-dpd {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }

            .courier-parcel-nonstandard,
            .courier-parcel-remove {
                justify-self: start;
            }

            .order-page {
                margin: -1rem;
                padding: 16px;
            }

            .nex-field-grid {
                grid-template-columns: 1fr;
            }

            .inline-field-row,
            .order-info-row,
            .inline-section-edit .row.g-2 > [class*="col-"] {
                grid-template-columns: 1fr;
                gap: 2px;
            }

            .management-status-form {
                grid-template-columns: 1fr;
                justify-content: stretch;
            }

            .management-status-control {
                width: 100%;
            }

            .management-invoice-label,
            .management-meta-label,
            .management-meta-value,
            .management-invoice-button,
            .management-issued-invoice-actions,
            .management-proforma-button {
                grid-column: 1;
            }

            .management-invoice-button,
            .management-proforma-button {
                width: 100%;
            }

            .management-issued-invoice-actions {
                flex-wrap: nowrap;
                width: max-content;
            }
        }
    </style>

    <div class="order-page{{ $order->purchaseIsComplete() ? '' : ' purchase-readonly' }}" data-order-id="{{ $order->id }}" data-order-state-url="{{ route('orders.state', $order) }}">
    <div class="order-topbar">
        <div class="order-heading">
            <div class="dropdown">
                <button class="star-button order-star {{ $starClass }}" type="button" data-bs-toggle="dropdown" data-bs-boundary="viewport" aria-expanded="false" aria-label="Wybierz kolor oznaczenia zamowienia">
                    <i class="bi {{ $currentStar ? 'bi-star-fill' : 'bi-star' }}" data-order-star-icon aria-hidden="true"></i>
                </button>
                <div class="dropdown-menu star-picker">
                    <div class="d-flex gap-1">
                        @foreach ($starColors as $color => $config)
                            <form method="POST" action="{{ route('orders.star-color.update', $order) }}" data-order-ajax-form>
                                @csrf
                                @method('PATCH')
                                <input type="hidden" name="star_color" value="{{ $color }}">
                                <button type="submit" class="star-color-button {{ $config['class'] }} {{ $currentStar === $color ? 'is-active' : '' }}" aria-label="{!! $config['label'] !!}" title="{!! $config['label'] !!}">{!! $config['icon'] !!}</button>
                            </form>
                        @endforeach
                    </div>
                </div>
            </div>
            <div class="order-heading-text">
                <h1 class="order-title">Zam&oacute;wienie {{ $order->id }}</h1>
                <span class="order-customer {{ $headerCustomerName ? '' : 'd-none' }}" data-order-header-customer>{{ $headerCustomerName }}</span>
            </div>
        </div>
        <nav class="order-navigation" aria-label="Nawigacja mi&#281;dzy zam&oacute;wieniami">
            @if ($previousOrderUrl)
                <a class="order-navigation-arrow" href="{{ $previousOrderUrl }}" data-order-navigation="previous" data-page-navigation-loading aria-label="Poprzednie zam&oacute;wienie" title="Poprzednie zam&oacute;wienie"><i class="bi bi-chevron-left" aria-hidden="true"></i></a>
            @else
                <button class="order-navigation-arrow" type="button" data-order-navigation-disabled="previous" aria-label="Brak poprzedniego zam&oacute;wienia" title="Brak poprzedniego zam&oacute;wienia" disabled><i class="bi bi-chevron-left" aria-hidden="true"></i></button>
            @endif

            @if ($nextOrderUrl)
                <a class="order-navigation-arrow" href="{{ $nextOrderUrl }}" data-order-navigation="next" data-page-navigation-loading aria-label="Nast&#281;pne zam&oacute;wienie" title="Nast&#281;pne zam&oacute;wienie"><i class="bi bi-chevron-right" aria-hidden="true"></i></a>
            @else
                <button class="order-navigation-arrow" type="button" data-order-navigation-disabled="next" aria-label="Brak nast&#281;pnego zam&oacute;wienia" title="Brak nast&#281;pnego zam&oacute;wienia" disabled><i class="bi bi-chevron-right" aria-hidden="true"></i></button>
            @endif

            <a class="order-list-back" href="{{ $ordersListUrl }}" data-page-navigation-loading><i class="bi bi-arrow-return-left" aria-hidden="true"></i><span>Powr&oacute;t do listy zam&oacute;wie&#324;</span></a>
        </nav>
    </div>

    @if ($allegroOrderLink && ! $allegroOrderLink->isReady())
        <div class="alert {{ in_array($allegroOrderLink->import_state, ['cancelled', 'superseded', 'review'], true) ? 'alert-warning' : 'alert-info' }}" role="status" data-purchase-readiness>
            @if ($allegroOrderLink->import_state === 'superseded')
                Zakup został połączony w Allegro.
                @if ($allegroOrderLink->replacement?->order_id)<a href="{{ route('orders.show', $allegroOrderLink->replacement->order_id) }}">Otwórz zamówienie #{{ $allegroOrderLink->replacement->order_id }}</a>@endif
            @elseif ($allegroOrderLink->import_state === 'cancelled')
                Zamówienie zostało anulowane w Allegro. {{ $allegroOrderLink->processing_error }}
            @elseif ($allegroOrderLink->processing_error)
                Zamówienie wymaga sprawdzenia: {{ $allegroOrderLink->processing_error }}
            @else
                Zakup niepełny. Oczekujemy na zakończenie wyboru dostawy i płatności w Allegro. Dane dostawy i kwoty nie są jeszcze ostateczne.
            @endif
        </div>
    @endif

    <div class="nex-card products-card">
        <div class="nex-card-body">
            <div class="products-table-wrapper">
                <table class="table nex-table products-table align-middle mb-0" aria-label="Produkty w zam&oacute;wieniu">
                    <thead>
                        <tr>
                            <th class="product-photo-column" scope="col">
                                <i class="bi bi-camera" aria-hidden="true"></i>
                                <span class="visually-hidden">Zdjęcie</span>
                            </th>
                            <th class="product-id-column" scope="col">ID PROD.</th>
                            <th class="product-main-column" scope="col">NAZWA PRODUKTU</th>
                            <th class="product-metric" scope="col">ILO&#346;&#262;</th>
                            <th class="product-metric" scope="col">CENA</th>
                            <th class="product-metric" scope="col">VAT</th>
                            <th class="product-metric" scope="col">WAGA</th>
                            <th class="product-metric product-date-column" scope="col">DATA</th>
                            <th class="text-end product-actions-column" scope="col">AKCJE</th>
                        </tr>
                    </thead>
                    <tbody data-products-table-body>
                        @include('orders.partials.product-rows', ['order' => $order])
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="products-toolbar" role="group" aria-label="Dodawanie i operacje na produktach">
        <div class="dropdown product-add-group">
            <button class="btn product-add-button" type="button" data-bs-toggle="modal" data-bs-target="#catalogProductModal">
                <i class="bi bi-plus-lg" aria-hidden="true"></i>
                <span>Dodaj produkty do zam&oacute;wienia...</span>
            </button>
            <button class="btn product-add-options" type="button" data-bs-toggle="dropdown" aria-expanded="false" aria-label="Wi&#281;cej opcji dodawania produkt&oacute;w">
                <i class="bi bi-chevron-down" aria-hidden="true"></i>
            </button>
            <ul class="dropdown-menu">
                <li>
                    <button class="dropdown-item" type="button" data-bs-toggle="modal" data-bs-target="#catalogProductModal">
                        Dodaj produkty z magazynu...
                    </button>
                </li>
                <li>
                    <button class="dropdown-item" type="button" data-bs-toggle="collapse" data-bs-target="#productAddPanel">
                        Dodaj produkt ręcznie...
                    </button>
                </li>
            </ul>
        </div>

        <div class="dropdown product-operations">
            <button class="btn product-operations-button" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                <i class="bi bi-wrench" aria-hidden="true"></i>
                <span>Operacje na produktach</span>
                <i class="bi bi-chevron-down" aria-hidden="true"></i>
            </button>
            <ul class="dropdown-menu dropdown-menu-end">
                <li><span class="dropdown-item text-secondary">Przelicz warto&#347;ci</span></li>
                <li><span class="dropdown-item text-secondary">Od&#347;wie&#380; sekcj&#281;</span></li>
            </ul>
        </div>
    </div>

    <div class="collapse product-add-panel" id="productAddPanel">
        <h3 class="h6 mb-3">Dodaj produkt ręcznie</h3>
        <form method="POST" action="{{ route('orders.products.store', $order) }}" data-order-ajax-form>
            @csrf
            <div class="row g-2">
                <div class="col-12 col-lg-4">
                    <label class="form-label">Nazwa produktu</label>
                    <input type="text" name="product_name" class="form-control form-control-sm" value="{{ old('product_name') }}" required>
                </div>
                <div class="col-6 col-lg-2">
                    <label class="form-label">Ilo&#347;&#263;</label>
                    <input type="number" min="1" name="quantity" class="form-control form-control-sm" value="{{ old('quantity', 1) }}" required>
                </div>
                <div class="col-6 col-lg-2">
                    <label class="form-label">Cena jednostkowa</label>
                    <input type="number" step="0.01" min="0" name="unit_price_gross" class="form-control form-control-sm" value="{{ old('unit_price_gross', 0) }}" required>
                </div>
                <div class="col-6 col-lg-1">
                    <label class="form-label">Waluta</label>
                    @php
                        $newProductCurrency = strtoupper((string) old('currency', $order->currency));
                        $newProductCurrencyKnown = array_key_exists($newProductCurrency, $currencyOptions);
                        if (! $newProductCurrencyKnown && $order->items->isEmpty() && (string) $order->total_gross === '0.00') {
                            $newProductCurrency = 'PLN';
                            $newProductCurrencyKnown = true;
                        }
                    @endphp
                    <select name="currency" class="form-select form-select-sm" required>
                        @if (! $newProductCurrencyKnown && $newProductCurrency !== '')
                            <option value="{{ $newProductCurrency }}" selected disabled class="text-secondary">
                                {{ $newProductCurrency }}
                            </option>
                        @endif
                        @foreach ($currencyOptions as $currencyCode)
                            <option value="{{ $currencyCode }}" @selected($newProductCurrency === $currencyCode)>
                                {{ $currencyCode }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="col-6 col-lg-1">
                    <label class="form-label">VAT (%)</label>
                    <input type="number" step="1" min="0" max="100" name="vat_rate" class="form-control form-control-sm" value="{{ old('vat_rate', 23) }}">
                </div>
                <div class="col-6 col-lg-1">
                    <label class="form-label">Waga</label>
                    <input type="number" step="0.001" min="0" name="weight" class="form-control form-control-sm" value="{{ old('weight', 1) }}">
                </div>
            </div>
            <div class="d-flex justify-content-end gap-2 mt-3">
                <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-toggle="collapse" data-bs-target="#productAddPanel">Anuluj</button>
                <button type="submit" class="btn btn-sm btn-primary">Zapisz</button>
            </div>
        </form>
    </div>

    <div
        class="modal fade"
        id="catalogProductModal"
        tabindex="-1"
        aria-labelledby="catalogProductModalLabel"
        aria-hidden="true"
        data-catalog-product-modal
        data-products-url="{{ route('orders.catalog-products.index', $order) }}"
    >
        <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable catalog-product-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h2 class="modal-title fs-5" id="catalogProductModalLabel">Dodaj produkty z magazynu</h2>
                    <button class="btn-close" type="button" data-bs-dismiss="modal" aria-label="Zamknij"></button>
                </div>
                <form
                    class="catalog-product-modal-form"
                    method="POST"
                    action="{{ route('orders.catalog-products.store', $order) }}"
                    data-order-ajax-form
                    data-catalog-products-form
                >
                    @csrf
                    <div class="modal-body catalog-product-modal-body">
                        <section class="catalog-product-browser" aria-labelledby="catalogProductBrowserTitle">
                            <h3 class="h6 mb-3" id="catalogProductBrowserTitle">Produkty</h3>
                            <div class="catalog-product-search">
                                <input
                                    class="form-control form-control-sm"
                                    type="search"
                                    placeholder="Nazwa, ID lub EAN"
                                    aria-label="Szukaj produktu"
                                    data-catalog-search
                                >
                                <button class="btn btn-sm btn-primary" type="button" data-catalog-search-submit>
                                    <i class="bi bi-search" aria-hidden="true"></i>
                                    Szukaj
                                </button>
                                <button class="btn btn-sm btn-outline-secondary" type="button" data-catalog-search-clear>
                                    Wyczyść
                                </button>
                            </div>
                            <div class="catalog-product-results" data-catalog-results>
                                <div class="catalog-product-results-header" aria-hidden="true">
                                    <span>Produkt</span>
                                    <span class="text-end">Stan</span>
                                    <span class="text-end">Cena</span>
                                    <span></span>
                                </div>
                                <div class="catalog-product-results-list" data-catalog-results-list></div>
                            </div>
                        </section>
                        <section class="catalog-product-summary" aria-labelledby="catalogProductSummaryTitle">
                            <h3 id="catalogProductSummaryTitle">Wybrane produkty</h3>
                            <div class="catalog-selected-empty" data-catalog-selected-empty>
                                Nie wybrano jeszcze żadnego produktu.
                            </div>
                            <div class="catalog-selected-items" data-catalog-selected-items></div>
                            <div class="catalog-product-summary-total">
                                <span>W sumie</span>
                                <div class="catalog-product-summary-total-controls">
                                    <strong data-catalog-total>0,00</strong>
                                    @php
                                        $catalogOrderCurrency = strtoupper((string) $order->currency);
                                        $catalogOrderCurrencyKnown = array_key_exists($catalogOrderCurrency, $currencyOptions);
                                    @endphp
                                    <select
                                        class="form-select form-select-sm"
                                        name="currency"
                                        required
                                        aria-label="Waluta dodawanych produktów"
                                        data-catalog-currency-select
                                        data-default-currency="{{ $catalogOrderCurrency }}"
                                    >
                                        @if (! $catalogOrderCurrencyKnown && $catalogOrderCurrency !== '')
                                            <option value="{{ $catalogOrderCurrency }}" selected disabled>
                                                {{ $catalogOrderCurrency }}
                                            </option>
                                        @endif
                                        @foreach ($currencyOptions as $currencyCode)
                                            <option value="{{ $currencyCode }}" @selected($catalogOrderCurrency === $currencyCode)>
                                                {{ $currencyCode }}
                                            </option>
                                        @endforeach
                                    </select>
                                </div>
                            </div>
                        </section>
                    </div>
                    <div class="modal-footer">
                        <div class="text-danger small me-auto" data-catalog-form-error hidden></div>
                        <button class="btn btn-success" type="submit" data-catalog-submit disabled>
                            <i class="bi bi-plus-lg" aria-hidden="true"></i>
                            Dodaj produkty do zamówienia
                        </button>
                        <button class="btn btn-outline-secondary" type="button" data-bs-dismiss="modal">Zamknij</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <template data-catalog-selected-template>
        <article class="catalog-selected-item" data-catalog-selected-item>
            <div class="catalog-selected-head">
                <span class="catalog-selected-image catalog-selected-placeholder" data-catalog-selected-image-wrap aria-hidden="true">
                    <i class="bi bi-image"></i>
                </span>
                <div class="catalog-selected-copy">
                    <strong data-catalog-selected-name></strong>
                    <span class="catalog-selected-catalog-price" data-catalog-selected-catalog-price></span>
                </div>
                <button class="btn btn-sm btn-outline-danger" type="button" data-catalog-remove title="Usuń z wybranych">
                    <i class="bi bi-trash" aria-hidden="true"></i>
                    <span class="visually-hidden">Usuń z wybranych</span>
                </button>
            </div>
            <div class="catalog-selected-fields">
                <div>
                    <label data-catalog-quantity-label>Ilość</label>
                    <input class="form-control form-control-sm" type="number" min="1" step="1" required data-catalog-quantity>
                </div>
                <div>
                    <label data-catalog-price-label>Cena brutto za szt.</label>
                    <div class="catalog-selected-price-wrap">
                        <input class="form-control form-control-sm" type="text" inputmode="decimal" required data-catalog-price>
                        <span data-catalog-currency></span>
                        <button class="btn btn-sm btn-outline-secondary" type="button" data-catalog-reset-price title="Przywróć cenę katalogową">
                            <i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i>
                            <span class="visually-hidden">Przywróć cenę katalogową</span>
                        </button>
                    </div>
                </div>
            </div>
            <span class="catalog-selected-stock-warning" data-catalog-stock-warning hidden></span>
        </article>
    </template>
    <div class="row g-3 mb-3">
        <div class="col-12 col-xl-4">
            <div class="nex-card h-100 inline-section" data-inline-section="order-info">
                <div class="nex-card-header">
                    <h2 class="nex-card-title">Informacje o zam&oacute;wieniu</h2>
                    <div class="d-flex align-items-center gap-2">
                        <button class="btn btn-sm btn-light border inline-section-edit-button" type="button" data-edit-section="order-info">Edytuj</button>
                        @if (! $order->trashed())
                            <div class="dropdown">
                                <button class="btn btn-sm btn-light border dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                                    Akcje
                                </button>
                                <ul class="dropdown-menu dropdown-menu-end">
                                    <li><a class="dropdown-item" href="{{ route('returns.create', ['order_id' => $order->id]) }}">Stwórz zwrot</a></li>
                                    <li>
                                        <form method="POST" action="{{ route('orders.destroy', $order) }}" onsubmit="return confirm('Przeniesc zamowienie do kosza?')">
                                            @csrf
                                            @method('DELETE')
                                            <button class="dropdown-item text-danger" type="submit">Usu&#324; zam&oacute;wienie</button>
                                        </form>
                                    </li>
                                    <li>
                                        <form method="POST" action="{{ route('orders.create-for-customer', $order) }}">
                                            @csrf
                                            <button class="dropdown-item" type="submit">Utw&oacute;rz nowe zam&oacute;wienie dla klienta</button>
                                        </form>
                                    </li>
                                    <li>
                                        <form method="POST" action="{{ route('orders.duplicate', $order) }}">
                                            @csrf
                                            <button class="dropdown-item" type="submit">Stw&oacute;rz kopi&#281; zam&oacute;wienia</button>
                                        </form>
                                    </li>
                                </ul>
                            </div>
                        @endif
                    </div>
                </div>
                <div class="nex-card-body">
                    <div class="order-info-panel inline-section-view" data-order-info-view>
                        @include('orders.partials.order-info-view', ['order' => $order, 'sourceOptions' => $sourceOptions])
                    </div>
                    <form method="POST" action="{{ route('orders.sections.order-info', $order) }}" class="inline-section-edit" data-order-ajax-form data-refresh-courier-defaults>
                        @csrf
                        @method('PATCH')
                        <div class="row g-2">
                            <div class="col-12"><label class="form-label">Klient (login)</label><input type="text" name="customer_login" class="form-control form-control-sm" value="{{ old('customer_login', $order->customer_login) }}"></div>
                            <div class="col-12"><label class="form-label">E-mail</label><input type="email" name="customer_email" class="form-control form-control-sm" value="{{ old('customer_email', $order->customer_email) }}"></div>
                            <div class="col-12"><label class="form-label">Telefon</label><input type="tel" name="customer_phone" class="form-control form-control-sm" value="{{ old('customer_phone', $order->customer_phone) }}" placeholder="+48 501 294 368"></div>
                            <div class="col-12">
                                <label class="form-label">&#377;r&oacute;d&#322;o</label>
                                <select name="source" class="form-select form-select-sm">
                                    @foreach ($sourceOptions as $source => $label)
                                        @if ($source === 'manual')
                                            <optgroup label="Pozostałe" data-other-source-group>
                                                <option value="{{ $source }}" @selected(old('source', $order->source) === $source)>{{ $label }}</option>
                                            </optgroup>
                                        @elseif ($source === 'allegro' && $allegroDisplayName !== '')
                                            <optgroup label="{{ $label }}" data-allegro-source-group>
                                                <option value="{{ $source }}" @selected(old('source', $order->source) === $source)>{{ $allegroDisplayName }}</option>
                                            </optgroup>
                                        @elseif ($source === 'prestashop' && $prestashopDisplayName !== '')
                                            <optgroup label="{{ $label }}" data-prestashop-source-group>
                                                <option value="{{ $source }}" @selected(old('source', $order->source) === $source)>{{ $prestashopDisplayName }}</option>
                                            </optgroup>
                                        @else
                                            <option value="{{ $source }}" @selected(old('source', $order->source) === $source)>{{ $label }}</option>
                                        @endif
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-12"><label class="form-label">Spos&oacute;b wysy&#322;ki</label><input type="text" name="shipping_method" class="form-control form-control-sm" value="{{ old('shipping_method', $order->shipping_method) }}"></div>
                            <div class="col-12"><label class="form-label">Pobranie</label><select name="cash_on_delivery" class="form-select form-select-sm"><option value="0" @selected(! old('cash_on_delivery', $order->cash_on_delivery))>Nie</option><option value="1" @selected((bool) old('cash_on_delivery', $order->cash_on_delivery))>Tak</option></select></div>
                            <div class="col-12"><label class="form-label">Koszt wysy&#322;ki</label><input type="number" step="0.01" min="0" name="delivery_cost_gross" class="form-control form-control-sm" value="{{ old('delivery_cost_gross', $order->delivery_cost_gross) }}"></div>
                            <div class="col-12"><label class="form-label">Spos&oacute;b p&#322;atno&#347;ci</label><input type="text" name="payment_method" class="form-control form-control-sm" value="{{ old('payment_method', $order->payment_method) }}"></div>
                            <div class="col-12"><label class="form-label">Uwagi</label><textarea name="notes" class="form-control form-control-sm" rows="2">{{ old('notes', $order->notes) }}</textarea></div>
                        </div>
                        <div class="inline-actions"><button type="submit" class="btn inline-save">Zapisz</button><button type="button" class="btn inline-cancel">Anuluj</button></div>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-12 col-xl-4">
            <div class="nex-card h-100">
                <div class="nex-card-header">
                    <h2 class="nex-card-title">Zarz&#261;dzanie</h2>
                </div>
                <div class="nex-card-body">
                    @if (! $order->trashed())
                        <div class="management-status-form {{ $order->source === 'allegro' ? 'is-allegro' : '' }}">
                        <form method="POST" action="{{ route('orders.status.update', $order) }}" class="management-status-fields" data-management-status-form>
                            @csrf
                            @method('PATCH')
                            <label class="management-status-label" for="managementStatus">Status:</label>
                            <div class="management-status-control">
                                <input id="managementStatus" type="hidden" name="status" value="{{ $order->status }}" data-management-status-input>
                                <div class="dropdown management-status-dropdown">
                                    <button class="btn btn-sm btn-light border dropdown-toggle management-status-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false" data-management-status-toggle>
                                        <span class="management-status-dot" data-management-status-dot style="background: {{ $statusSettings[$order->status]['color'] ?? '#64748b' }}"></span>
                                        <span data-management-status-label>{{ $statuses[$order->status] ?? $order->status }}</span>
                                    </button>
                                    <ul class="dropdown-menu management-status-menu">
                                    @foreach ($statuses as $status => $label)
                                        @php
                                            $managementStatusColor = $statusSettings[$status]['color'] ?? '#64748b';
                                        @endphp
                                        <li>
                                            <button class="dropdown-item management-status-option {{ $order->status === $status ? 'active' : '' }}" type="button" data-management-status-option data-status="{{ $status }}" data-status-label="{{ $label }}" data-status-color="{{ $managementStatusColor }}">
                                                <span class="management-status-dot" style="background: {{ $managementStatusColor }}"></span>
                                                <span>{{ $label }}</span>
                                            </button>
                                        </li>
                                    @endforeach
                                    </ul>
                                </div>
                            </div>
                            <button class="btn btn-sm btn-primary management-status-submit" type="submit" data-management-status-submit>Zapisz</button>
                        </form>
                            <div class="management-status-section-gap" aria-hidden="true"></div>
                            @include('orders.partials.sales-document-actions', [
                                'order' => $order,
                                'salesDocumentActions' => $salesDocumentActions,
                            ])
                            @if ($order->source === 'prestashop')
                                <div class="management-meta-label">Numer w sklepie:</div>
                                <div class="management-meta-value">
                                    {{ $order->external_id ?: '...' }}
                                    @if ($prestashopOrderLink?->account?->name)
                                        ({{ $prestashopOrderLink->account->name }})
                                    @endif
                                </div>
                            @elseif ($order->source === 'allegro')
                                <div class="allegro-transaction-details">
                                <div class="management-meta-label">Numer transakcji:</div>
                                <div class="management-meta-value" style="overflow-wrap: anywhere; min-width: 0">
                                    @if ($allegroTransactionUrl)
                                        <a href="{{ $allegroTransactionUrl }}" class="allegro-transaction-link" data-allegro-transaction="{{ $allegroTransactionId }}" target="_blank" rel="noopener noreferrer" title="Kopiuj numer transakcji">{{ $allegroTransactionId }}</a>
                                    @else
                                        {{ $allegroTransactionId ?: '...' }}
                                    @endif
                                </div>
                                </div>
                            @endif
                            <div class="management-meta-label">Data z&#322;o&#380;enia:</div>
                            <div class="management-meta-value">
                                @if ($createdAtParts = $managementDateParts($allegroOrderLink ? ($order->purchased_at ?? $order->created_at) : $order->created_at))
                                    <span>{{ $createdAtParts['date'] }}</span><span class="management-meta-time">{{ $createdAtParts['time'] }}</span>
                                @else
                                    ...
                                @endif
                            </div>
                            <div class="management-meta-label is-status-date">Data w statusie:</div>
                            <div class="management-meta-value is-status-date" data-management-status-date>
                                @if ($statusChangedAtParts = $managementDateParts($statusChangedAt))
                                    <span data-management-status-date-value>{{ $statusChangedAtParts['date'] }}</span><span class="management-meta-time" data-management-status-time-value>{{ $statusChangedAtParts['time'] }}</span>
                                @else
                                    <span data-management-status-date-value>...</span><span class="management-meta-time" data-management-status-time-value></span>
                                @endif
                            </div>
                            @include('customer-portal.order-link', ['order' => $order])
                        </div>
                    @else
                        <div class="management-placeholder">Zam&oacute;wienie jest w koszu.</div>
                    @endif
                </div>
            </div>
        </div>

        <div class="col-12 col-xl-4">
            <div class="nex-card h-100">
                <div class="nex-card-header"><h2 class="nex-card-title">Szybkie akcje</h2></div>
                <div class="nex-card-body placeholder-card d-flex align-items-center justify-content-center text-center">
                    Obs&#322;uga wysy&#322;ek zostanie dodana p&oacute;&#378;niej.
                </div>
            </div>
        </div>
    </div>

    <div class="row g-3 mb-3">
        <div class="col-12 col-xl-4">
            <div class="nex-card h-100 inline-section" data-inline-section="shipping">
                <div class="nex-card-header">
                    <h2 class="nex-card-title">Adres dostawy</h2>
                    <div class="card-header-actions">
                        <button class="btn btn-sm btn-light border icon-action-button" type="button" data-copy-shipping-to-billing title="Skopiuj adres do danych faktury" aria-label="Skopiuj adres do danych faktury">&#10697;</button>
                        <button class="btn btn-sm btn-light border icon-action-button" type="button" data-edit-section="shipping" title="Edytuj adres dostawy" aria-label="Edytuj adres dostawy"><i class="bi bi-pencil" aria-hidden="true"></i></button>
                    </div>
                </div>
                <div class="nex-card-body">
                    <div class="inline-section-view inline-edit-trigger" data-edit-section="shipping">@include('orders.partials.address', [
                        'address' => $shippingAddress,
                        'showTaxId' => false,
                        'showCountry' => true,
                        'countryName' => $countryCatalog->name($shippingAddress?->country_code),
                        'showProvince' => false,
                        'showPhone' => false,
                        'showEmail' => false,
                        'editFields' => [
                            'Imię i Nazwisko' => 'shipping_name',
                            'Firma' => 'shipping_company_name',
                            'Adres' => 'shipping_address_line',
                            'Kod i miasto' => 'shipping_postal_code',
                            'Miasto' => 'shipping_city',
                            'Kraj' => 'shipping_country_code',
                        ],
                    ])</div>
                    <form method="POST" action="{{ route('orders.sections.shipping-address', $order) }}" class="inline-section-edit" data-order-ajax-form data-refresh-courier-defaults>
                        @csrf
                        @method('PATCH')
                        <div class="row g-2">
                            <div class="col-12"><label class="form-label">Imi&#281; i nazwisko</label><input type="text" name="shipping_name" class="form-control form-control-sm" value="{{ old('shipping_name', $shippingAddress?->name) }}"></div>
                            <div class="col-12"><label class="form-label">Firma</label><input type="text" name="shipping_company_name" class="form-control form-control-sm" value="{{ old('shipping_company_name', $shippingAddress?->company_name) }}"></div>
                            <div class="col-12"><label class="form-label">Adres</label><input type="text" name="shipping_address_line" class="form-control form-control-sm" value="{{ old('shipping_address_line', $formatAddressLine($shippingAddress)) }}"></div>
                            <div class="col-12"><label class="form-label">Kod pocztowy</label><input type="text" name="shipping_postal_code" class="form-control form-control-sm" value="{{ old('shipping_postal_code', $shippingAddress?->postal_code) }}"></div>
                            <div class="col-12"><label class="form-label">Miasto</label><input type="text" name="shipping_city" class="form-control form-control-sm" value="{{ old('shipping_city', $shippingAddress?->city) }}"></div>
                            <div class="col-12">
                                <label class="form-label">Kraj</label>
                                <select name="shipping_country_code" class="form-select form-select-sm @error('shipping_country_code') is-invalid @enderror" required>
                                    <option value="">&mdash; Wybierz kraj &mdash;</option>
                                    @foreach ($countries as $code => $name)
                                        <option value="{{ $code }}" @selected($shippingCountryCode === $code)>{{ $name }}</option>
                                    @endforeach
                                </select>
                                @error('shipping_country_code')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>
                        </div>
                        <div class="inline-actions"><button type="submit" class="btn inline-save">Zapisz</button><button type="button" class="btn inline-cancel">Anuluj</button></div>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-12 col-xl-4">
            <div class="nex-card h-100 inline-section" data-inline-section="billing">
                <div class="nex-card-header">
                    <h2 class="nex-card-title">Dane do faktury</h2>
                    <div class="card-header-actions">
                        <button class="btn btn-sm btn-light border icon-action-button" type="button" data-copy-billing-to-shipping title="Skopiuj dane faktury do adresu dostawy" aria-label="Skopiuj dane faktury do adresu dostawy">&#10697;</button>
                        <button class="btn btn-sm btn-light border icon-action-button" type="button" data-edit-section="billing" title="Edytuj dane do faktury" aria-label="Edytuj dane do faktury"><i class="bi bi-pencil" aria-hidden="true"></i></button>
                    </div>
                </div>
                @if ($order->source === 'allegro' && $allegroOrderLink?->invoice_required)
                    <div class="billing-invoice-request" data-invoice-request-notice role="note">
                        <i class="bi bi-file-earmark-text" aria-hidden="true"></i>
                        <span>Klient prosi o fakturę</span>
                    </div>
                @endif
                <div class="nex-card-body">
                    <div class="inline-section-view inline-edit-trigger" data-edit-section="billing">
                        @include('orders.partials.address', [
                            'address' => $billingAddress,
                            'showTaxId' => true,
                            'showCountry' => true,
                            'showProvince' => false,
                            'showPhone' => false,
                            'showEmail' => false,
                            'taxIdLast' => true,
                            'countryName' => $countryCatalog->name($billingAddress?->country_code),
                            'editFields' => [
                                'Imię i Nazwisko' => 'billing_name',
                                'Firma' => 'billing_company_name',
                                'Adres' => 'billing_address_line',
                                'Kod i miasto' => 'billing_postal_code',
                                'Miasto' => 'billing_city',
                                'Kraj' => 'billing_country_code',
                                'NIP' => 'billing_tax_id',
                            ],
                        ])
                    </div>
                    <form method="POST" action="{{ route('orders.sections.billing-address', $order) }}" class="inline-section-edit" data-billing-form data-gus-lookup-url="{{ route('gus.company-by-nip') }}" data-order-ajax-form>
                        @csrf
                        @method('PATCH')
                        <input type="hidden" name="billing_province" value="{{ old('billing_province', $billingAddress?->province) }}">
                        <div class="row g-2">
                            <div class="col-12"><label class="form-label">Imi&#281; i nazwisko</label><input type="text" name="billing_name" class="form-control form-control-sm" value="{{ old('billing_name', $billingAddress?->name) }}"></div>
                            <div class="col-12"><label class="form-label">Firma</label><input type="text" name="billing_company_name" class="form-control form-control-sm" value="{{ old('billing_company_name', $billingAddress?->company_name) }}"></div>
                            <div class="col-12"><label class="form-label">Adres</label><input type="text" name="billing_address_line" class="form-control form-control-sm" value="{{ old('billing_address_line', $formatAddressLine($billingAddress)) }}"></div>
                            <div class="col-12"><label class="form-label">Kod pocztowy</label><input type="text" name="billing_postal_code" class="form-control form-control-sm" value="{{ old('billing_postal_code', $billingAddress?->postal_code) }}"></div>
                            <div class="col-12"><label class="form-label">Miasto</label><input type="text" name="billing_city" class="form-control form-control-sm" value="{{ old('billing_city', $billingAddress?->city) }}"></div>
                            <div class="col-12">
                                <label class="form-label">Kraj</label>
                                <select name="billing_country_code" class="form-select form-select-sm @error('billing_country_code') is-invalid @enderror" required>
                                    <option value="">&mdash; Wybierz kraj &mdash;</option>
                                    @foreach ($countries as $code => $name)
                                        <option value="{{ $code }}" @selected($billingCountryCode === $code)>{{ $name }}</option>
                                    @endforeach
                                </select>
                                @error('billing_country_code')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>
                            <div class="col-12">
                                <label class="form-label">NIP</label>
                                <div>
                                    <div class="billing-gus-control">
                                        <input type="text" name="billing_tax_id" class="form-control form-control-sm" value="{{ old('billing_tax_id', $billingAddress?->tax_id) }}" data-gus-nip-input>
                                        <button type="button" class="btn btn-sm billing-gus-button" data-gus-lookup-button aria-label="Pobierz dane z GUS po NIP" title="Pobierz dane z GUS po NIP">GUS</button>
                                    </div>
                                    <div class="billing-gus-message text-danger" data-gus-message aria-live="polite"></div>
                                    <div class="billing-gus-results" data-gus-results hidden>
                                        <select class="form-select form-select-sm" data-gus-results-select aria-label="Wybierz dane firmy z GUS"></select>
                                        <button type="button" class="btn btn-sm btn-light border" data-gus-result-use>Wybierz</button>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="inline-actions"><button type="submit" class="btn inline-save">Zapisz</button><button type="button" class="btn inline-cancel">Anuluj</button></div>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-12 col-xl-4">
            <div class="nex-card h-100 inline-section" data-inline-section="pickup">
                <div class="nex-card-header">
                    <h2 class="nex-card-title">Odbi&oacute;r w punkcie</h2>
                    <div class="card-header-actions">
                        <button class="btn btn-sm btn-light border icon-action-button" type="button" data-edit-section="pickup" title="Edytuj odbi&oacute;r w punkcie" aria-label="Edytuj odbi&oacute;r w punkcie"><i class="bi bi-pencil" aria-hidden="true"></i></button>
                    </div>
                </div>
                <div class="nex-card-body">
                    <div class="inline-section-view inline-edit-trigger" data-edit-section="pickup" data-pickup-view>
                        @include('orders.partials.pickup-view', ['order' => $order])
                    </div>
                    <form method="POST" action="{{ route('orders.pickup-point.update', $order) }}" class="inline-section-edit" data-order-ajax-form data-refresh-courier-defaults>
                        @csrf
                        @method('PATCH')
                        <div class="row g-2">
                            <div class="col-12"><label class="form-label">Nazwa</label><input type="text" name="pickup_point_name" class="form-control form-control-sm" value="{{ old('pickup_point_name', $order->pickup_point_name) }}"></div>
                            <div class="col-12"><label class="form-label">ID</label><input type="text" name="pickup_point_id" class="form-control form-control-sm" value="{{ old('pickup_point_id', $order->pickup_point_id) }}"></div>
                            <div class="col-12"><label class="form-label">Adres</label><input type="text" name="pickup_point_address" class="form-control form-control-sm" value="{{ old('pickup_point_address', $order->pickup_point_address) }}"></div>
                            <div class="col-6"><label class="form-label">Kod</label><input type="text" name="pickup_point_postal_code" class="form-control form-control-sm" value="{{ old('pickup_point_postal_code', $order->pickup_point_postal_code) }}"></div>
                            <div class="col-6"><label class="form-label">Miasto</label><input type="text" name="pickup_point_city" class="form-control form-control-sm" value="{{ old('pickup_point_city', $order->pickup_point_city) }}"></div>
                        </div>
                        <div class="inline-actions"><button type="submit" class="btn inline-save">Zapisz</button><button type="button" class="btn inline-cancel">Anuluj</button></div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    @php
        $shipmentFormHasErrors = $errors->hasAny(['shipment', 'parcel_template', 'target_point_id', 'sending_method', 'content_description', 'cod_amount', 'insurance_amount', 'additional_services', 'service', 'parcels']);
        $initialShipmentProvider = $shipmentFormHasErrors ? $selectedShipmentProvider : null;
    @endphp
    @if ($order->returns->isNotEmpty())
        <div class="nex-card mb-3">
            <div class="nex-card-header"><h2 class="nex-card-title">Zwroty</h2></div>
            <div class="nex-card-body d-flex flex-wrap gap-3">
                @foreach ($order->returns as $orderReturn)
                    <a href="{{ route('returns.show', $orderReturn) }}">Zwrot {{ $orderReturn->id }}@if($orderReturn->reference_number) ({{ $orderReturn->reference_number }})@endif &middot; {{ $orderReturn->localStatusLabel() }}</a>
                @endforeach
            </div>
        </div>
    @endif
    <div class="nex-card shipments-card">
        <div class="nex-card-header">
            <h2 class="nex-card-title shipments-card-title">Przesy&#322;ki</h2>
        </div>
        <div class="nex-card-body">
            @if ($order->trashed())
                <div class="shipments-empty-copy">Przesy&#322;ki nie s&#261; dost&#281;pne dla zam&oacute;wienia w koszu.</div>
            @else
                <div class="alert alert-info py-2 px-3 small d-none" role="status" data-shipment-ajax-notice></div>
                <div class="shipments-empty-copy {{ $order->shipments->isNotEmpty() ? 'd-none' : '' }}" data-shipments-empty>Nie nadano &#380;adnych paczek - skorzystaj z przycisk&oacute;w poni&#380;ej, aby wygenerowa&#263; przesy&#322;k&#281;.</div>
                <div class="shipments-table-wrap {{ $order->shipments->isEmpty() ? 'd-none' : '' }}" data-shipments-table-wrap>
                    <table class="table shipments-table">
                        <thead>
                            <tr>
                                <th>Data utworzenia</th>
                                <th>Przewo&#378;nik</th>
                                <th>Nazwa konta</th>
                                <th>Numer nadawczy</th>
                                <th>Status</th>
                                <th class="text-end"></th>
                            </tr>
                        </thead>
                        <tbody data-shipments-table-body>
                            @foreach ($order->shipments as $shipment)
                                @include('orders.partials.shipment-row', ['shipment' => $shipment])
                            @endforeach
                        </tbody>
                    </table>
                </div>

                @if ($activeCourierAccounts->isNotEmpty())
                    <div class="courier-tabs" aria-label="Aktywne integracje kurierskie">
                        @foreach ($activeCourierAccounts as $courierAccount)
                            @if ($courierAccount->provider === \Modules\Shipments\Models\CourierAccount::PROVIDER_INPOST_LOCKERS)
                                <button
                                    class="courier-tab"
                                    type="button"
                                    data-courier-form-button
                                    data-inpost-courier-button
                                    data-courier-provider="{{ $courierAccount->provider }}"
                                    data-courier-form-url="{{ route('orders.shipments.form', ['order' => $order, 'provider' => $courierAccount->provider]) }}"
                                    aria-expanded="false"
                                    aria-controls="courierShipmentFormHost"
                                >{{ $courierAccount->name }}</button>
                            @elseif (in_array($courierAccount->provider, [
                                \Modules\Shipments\Models\CourierAccount::PROVIDER_INPOST_COURIER,
                                \Modules\Shipments\Models\CourierAccount::PROVIDER_DPD,
                                \Modules\Shipments\Models\CourierAccount::PROVIDER_ALLEGRO_SHIPPING,
                            ], true))
                                <button
                                    class="courier-tab"
                                    type="button"
                                    data-courier-form-button
                                    data-courier-provider="{{ $courierAccount->provider }}"
                                    data-courier-form-url="{{ route('orders.shipments.form', ['order' => $order, 'provider' => $courierAccount->provider]) }}"
                                    aria-expanded="false"
                                    aria-controls="courierShipmentFormHost"
                                >{{ $courierAccount->name }}</button>
                            @endif
                        @endforeach
                    </div>
                @endif

                @if ($activeCourierAccounts->isEmpty())
                    <div class="shipment-recipient-summary mx-2">Skonfiguruj i aktywuj Integracj&#281; Kuriersk&#261;, aby nadawa&#263; przesy&#322;ki.</div>
                @endif

                <div
                    id="courierShipmentFormHost"
                    class="d-none"
                    data-courier-form-host
                    data-initial-courier-provider="{{ $initialShipmentProvider }}"
                    aria-live="polite"
                ></div>

            @endif
        </div>
    </div>

    <div class="modal fade" id="cancelShipmentModal" tabindex="-1" aria-labelledby="cancelShipmentModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h2 class="modal-title fs-5" id="cancelShipmentModalLabel">Anulowanie przesy&#322;ki</h2>
                    <button class="btn-close" type="button" data-bs-dismiss="modal" aria-label="Zamknij"></button>
                </div>
                <div class="modal-body">
                    <div data-cancel-shipment-api-message>
                        <p class="mb-2">Czy na pewno chcesz anulowa&#263; przesy&#322;k&#281; <strong data-cancel-shipment-number></strong>?</p>
                        <p class="mb-0 text-secondary">Operacja zostanie przekazana do integracji kurierskiej, przez kt&oacute;r&#261; przesy&#322;ka zosta&#322;a nadana.</p>
                    </div>
                    <div class="d-none" data-cancel-shipment-local-message>
                        <p class="mb-2">Ta integracja kurierska nie oferuje usuwania paczek w systemie kuriera (poprzez API).</p>
                        <p class="mb-0 text-secondary">Czy mimo to chcesz trwale usun&#261;&#263; przesy&#322;k&#281; <strong data-cancel-shipment-local-number></strong> z NEX-OMS? Paczka nadal mo&#380;e istnie&#263; w systemie kuriera.</p>
                    </div>
                </div>
                <div class="modal-footer">
                    <button class="btn btn-sm btn-light border" type="button" data-bs-dismiss="modal">Anuluj</button>
                    <form method="POST" action="" data-cancel-shipment-form data-order-ajax-form>
                        @csrf
                        <input type="hidden" name="local_only" value="0" data-cancel-shipment-local-only>
                        <button class="btn btn-sm btn-primary" type="submit">Potwierd&#378;</button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="orderKsefSendConfirmationModal" tabindex="-1" aria-labelledby="orderKsefSendConfirmationQuestion" aria-hidden="true" data-order-ksef-send-modal>
        <div class="modal-dialog invoice-ksef-confirm-dialog">
            <div class="modal-content invoice-ksef-confirm-content">
                <div class="modal-body invoice-ksef-confirm-body">
                    <i class="bi bi-exclamation-triangle invoice-ksef-confirm-icon" aria-hidden="true"></i>
                    <h2 class="invoice-ksef-confirm-question" id="orderKsefSendConfirmationQuestion" data-order-ksef-send-question>Czy przekaza&#263; faktur&#281; do KSeF 2.0?</h2>
                    <div class="invoice-ksef-confirm-actions">
                        <button class="btn invoice-ksef-confirm-accept" type="button" data-order-ksef-send-confirm>Tak</button>
                        <button class="btn btn-outline-secondary" type="button" data-bs-dismiss="modal">Anuluj</button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    @include('orders.partials.sales-document-delete-modal')

    <div class="order-communication-grid">
        <x-order-email-exchange :order="$order" />
        @include('components.order-additional-information', ['order' => $order])
    </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const deferredFormFields = new WeakMap();
            let deferredOrderInfoHtml = null;
            const paymentLinkClickTimers = new WeakMap();
            const allegroTransactionClickTimers = new WeakMap();
            const paymentLinkRequests = new Map();
            const pageCsrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';
            const refreshAllegroInformation = async (panel) => {
                if (!panel || panel.dataset.loading === '1') return;
                const error = panel.querySelector('[data-allegro-information-error]');
                const body = panel.querySelector('[data-allegro-information-body]');
                panel.dataset.loading = '1';
                body.setAttribute('aria-busy', 'true');
                error.textContent = '';
                error.hidden = true;
                try {
                    const response = await fetch(panel.dataset.refreshUrl, {
                        method: 'POST',
                        credentials: 'same-origin',
                        headers: {'Accept': 'application/json', 'X-CSRF-TOKEN': pageCsrfToken},
                    });
                    const payload = await response.json();
                    if (!response.ok || typeof payload.html !== 'string') {
                        throw new Error(payload.message || 'Nie udało się pobrać danych Allegro.');
                    }
                    body.innerHTML = payload.html;
                    panel.dataset.informationLoaded = '1';
                } catch (exception) {
                    error.textContent = exception.message || 'Nie udało się pobrać danych Allegro.';
                    error.hidden = false;
                } finally {
                    delete panel.dataset.loading;
                    body.removeAttribute('aria-busy');
                }
            };
            document.querySelectorAll('[data-allegro-information-panel]').forEach((panel) => {
                panel.addEventListener('show.bs.collapse', (event) => {
                    if (event.target !== panel) return;
                    if (panel.dataset.refreshOnOpen === '1' || panel.dataset.informationLoaded !== '1') {
                        return refreshAllegroInformation(panel);
                    }
                });
            });
            const catalogProductModal = document.querySelector('[data-catalog-product-modal]');
            if (document.querySelector('[data-purchase-readiness]')) {
                document.querySelectorAll('.products-toolbar button, [data-products-table-body] button, [data-courier-form-url], button[data-paid-edit-open], button[data-payment-link-action], .paid-amount-action-button, button[data-edit-section="shipping"], button[data-edit-section="billing"], button[data-edit-section="pickup"]').forEach(button => {
                    button.disabled = true;
                    button.title = 'Zakup Allegro nie jest gotowy do realizacji.';
                });
            }
            const catalogProductForm = catalogProductModal?.querySelector('[data-catalog-products-form]');
            const catalogResults = catalogProductModal?.querySelector('[data-catalog-results]');
            const catalogResultsList = catalogProductModal?.querySelector('[data-catalog-results-list]');
            const catalogSearch = catalogProductModal?.querySelector('[data-catalog-search]');
            const catalogSelectedItems = catalogProductModal?.querySelector('[data-catalog-selected-items]');
            const catalogSelectedEmpty = catalogProductModal?.querySelector('[data-catalog-selected-empty]');
            const catalogSelectedTemplate = document.querySelector('[data-catalog-selected-template]');
            const catalogTotal = catalogProductModal?.querySelector('[data-catalog-total]');
            const catalogCurrencySelect = catalogProductModal?.querySelector('[data-catalog-currency-select]');
            const catalogSubmit = catalogProductModal?.querySelector('[data-catalog-submit]');
            const catalogFormError = catalogProductModal?.querySelector('[data-catalog-form-error]');
            const catalogSelections = new Map();
            const catalogDefaultCurrency = catalogCurrencySelect?.dataset.defaultCurrency
                || catalogCurrencySelect?.value
                || 'PLN';
            let catalogProductsRequest = null;

            const normalizeCatalogPrice = (value) => String(value || '')
                .trim()
                .replace(/[\s\u00a0]+/g, '')
                .replace(',', '.');

            const catalogPriceToCents = (value) => {
                const normalized = normalizeCatalogPrice(value);
                if (!/^\d+(?:\.\d{1,2})?$/.test(normalized)) {
                    return null;
                }

                const [whole, fraction = ''] = normalized.split('.');
                return (BigInt(whole) * 100n) + BigInt(fraction.padEnd(2, '0'));
            };

            const formatCatalogCents = (value) => {
                const whole = value / 100n;
                const fraction = String(value % 100n).padStart(2, '0');
                return `${whole.toLocaleString('pl-PL')},${fraction}`;
            };

            const updateCatalogResultSelection = () => {
                catalogResultsList?.querySelectorAll('[data-catalog-result]').forEach((row) => {
                    const selected = catalogSelections.has(String(row.dataset.productId || ''));
                    const button = row.querySelector('[data-catalog-add]');
                    row.classList.toggle('is-selected', selected);

                    if (button) {
                        button.classList.toggle('btn-outline-primary', !selected);
                        button.classList.toggle('btn-primary', selected);
                        button.title = selected ? 'Dodaj kolejną sztukę' : 'Dodaj produkt';
                        button.innerHTML = '<i class="bi bi-plus-lg" aria-hidden="true"></i> Dodaj';
                    }
                });
            };

            const updateCatalogSelectionState = () => {
                let totalCents = 0n;
                const selectedCurrencyOption = catalogCurrencySelect?.selectedOptions?.[0];
                const currency = selectedCurrencyOption && !selectedCurrencyOption.disabled
                    ? String(catalogCurrencySelect.value || '')
                    : '';
                let valid = catalogSelections.size > 0 && currency !== '';

                catalogSelections.forEach((selection) => {
                    const priceCents = catalogPriceToCents(selection.price);
                    const quantity = Number.parseInt(String(selection.quantity), 10);

                    if (priceCents === null || !Number.isSafeInteger(quantity) || quantity < 1) {
                        valid = false;
                        return;
                    }

                    totalCents += priceCents * BigInt(quantity);
                });

                if (catalogTotal) {
                    catalogTotal.textContent = valid
                        ? formatCatalogCents(totalCents)
                        : (catalogSelections.size ? 'Sprawdź dane' : '0,00');
                }

                if (catalogSubmit) {
                    catalogSubmit.disabled = !valid;
                }

                catalogSelectedEmpty?.toggleAttribute('hidden', catalogSelections.size > 0);
                updateCatalogResultSelection();
            };

            const updateCatalogStockWarning = (item, selection) => {
                const warning = item.querySelector('[data-catalog-stock-warning]');
                const quantity = Number.parseInt(String(selection.quantity), 10);
                const exceedsStock = Number.isSafeInteger(quantity) && quantity > selection.stock;

                if (!warning) {
                    return;
                }

                warning.hidden = !exceedsStock;
                warning.textContent = exceedsStock
                    ? `Wybrana ilość przekracza stan magazynowy (${selection.stock} szt.).`
                    : '';
            };

            const renderCatalogSelections = () => {
                if (!catalogSelectedItems || !catalogSelectedTemplate) {
                    return;
                }

                catalogSelectedItems.innerHTML = '';

                Array.from(catalogSelections.values()).forEach((selection, index) => {
                    const fragment = catalogSelectedTemplate.content.cloneNode(true);
                    const item = fragment.querySelector('[data-catalog-selected-item]');
                    const imageWrap = fragment.querySelector('[data-catalog-selected-image-wrap]');
                    const quantityInput = fragment.querySelector('[data-catalog-quantity]');
                    const priceInput = fragment.querySelector('[data-catalog-price]');
                    const productId = document.createElement('input');

                    item.dataset.productId = selection.id;
                    fragment.querySelector('[data-catalog-selected-name]').textContent = selection.name;
                    fragment.querySelector('[data-catalog-selected-catalog-price]').textContent = `Cena katalogowa: ${String(selection.catalogPrice).replace('.', ',')} ${selection.currency}`;
                    fragment.querySelector('[data-catalog-currency]').textContent = catalogCurrencySelect?.value || '';

                    if (selection.image) {
                        const image = document.createElement('img');
                        image.className = 'catalog-selected-image';
                        image.src = selection.image;
                        image.alt = '';
                        imageWrap.replaceWith(image);
                    }

                    productId.type = 'hidden';
                    productId.name = `items[${index}][product_id]`;
                    productId.value = selection.id;
                    item.appendChild(productId);

                    quantityInput.name = `items[${index}][quantity]`;
                    quantityInput.value = selection.quantity;
                    priceInput.name = `items[${index}][unit_price_gross]`;
                    priceInput.value = String(selection.price).replace('.', ',');

                    quantityInput.addEventListener('input', () => {
                        selection.quantity = quantityInput.value;
                        updateCatalogStockWarning(item, selection);
                        updateCatalogSelectionState();
                    });
                    priceInput.addEventListener('input', () => {
                        selection.price = priceInput.value;
                        updateCatalogSelectionState();
                    });
                    item.querySelector('[data-catalog-reset-price]').addEventListener('click', () => {
                        selection.price = selection.catalogPrice;
                        priceInput.value = String(selection.catalogPrice).replace('.', ',');
                        updateCatalogSelectionState();
                        priceInput.focus();
                    });
                    item.querySelector('[data-catalog-remove]').addEventListener('click', () => {
                        catalogSelections.delete(selection.id);
                        renderCatalogSelections();
                    });

                    updateCatalogStockWarning(item, selection);
                    catalogSelectedItems.appendChild(fragment);
                });

                updateCatalogSelectionState();
            };

            const addCatalogSelection = (button) => {
                const id = String(button.dataset.productId || '');
                if (!id) {
                    return;
                }

                const existing = catalogSelections.get(id);
                if (existing) {
                    existing.quantity = String((Number.parseInt(String(existing.quantity), 10) || 0) + 1);
                    renderCatalogSelections();
                    return;
                }

                catalogFormError?.toggleAttribute('hidden', true);
                catalogSelections.set(id, {
                    id,
                    name: String(button.dataset.productName || ''),
                    catalogPrice: String(button.dataset.productPrice || '0.00'),
                    price: String(button.dataset.productPrice || '0.00'),
                    currency: String(button.dataset.productCurrency || ''),
                    stock: Number.parseInt(String(button.dataset.productStock || '0'), 10) || 0,
                    image: String(button.dataset.productImage || ''),
                    quantity: '1',
                });
                renderCatalogSelections();
            };

            const loadCatalogProducts = async (page = 1) => {
                const baseUrl = catalogProductModal?.dataset.productsUrl;
                if (!baseUrl || !catalogResultsList) {
                    return;
                }

                catalogProductsRequest?.abort();
                catalogProductsRequest = new AbortController();
                const url = new URL(baseUrl, window.location.origin);
                const search = catalogSearch?.value.trim() || '';
                if (search) {
                    url.searchParams.set('q', search);
                }
                url.searchParams.set('page', String(page));
                catalogResults?.classList.add('is-loading');
                catalogFormError?.toggleAttribute('hidden', true);

                try {
                    const response = await fetch(url.toString(), {
                        headers: {
                            Accept: 'application/json',
                            'X-Requested-With': 'XMLHttpRequest',
                        },
                        cache: 'no-store',
                        signal: catalogProductsRequest.signal,
                    });
                    const payload = await response.json().catch(() => ({}));

                    if (!response.ok || typeof payload.html !== 'string') {
                        throw new Error(payload.message || 'Nie udało się wczytać produktów.');
                    }

                    catalogResultsList.innerHTML = payload.html;
                    updateCatalogResultSelection();
                } catch (error) {
                    if (error.name === 'AbortError') {
                        return;
                    }

                    catalogResultsList.innerHTML = '<div class="catalog-product-results-empty">Nie udało się wczytać produktów.</div>';
                } finally {
                    catalogResults?.classList.remove('is-loading');
                }
            };

            catalogProductModal?.addEventListener('shown.bs.modal', () => {
                catalogSelections.clear();
                if (catalogCurrencySelect) {
                    catalogCurrencySelect.value = catalogDefaultCurrency;
                }
                if (catalogSearch) {
                    catalogSearch.value = '';
                }
                if (catalogFormError) {
                    catalogFormError.hidden = true;
                    catalogFormError.textContent = '';
                }
                renderCatalogSelections();
                loadCatalogProducts();
                catalogSearch?.focus();
            });

            catalogProductModal?.addEventListener('hidden.bs.modal', () => {
                catalogProductsRequest?.abort();
                catalogSelections.clear();
                renderCatalogSelections();
            });

            catalogCurrencySelect?.addEventListener('change', () => {
                catalogSelectedItems?.querySelectorAll('[data-catalog-currency]').forEach((element) => {
                    element.textContent = catalogCurrencySelect.value;
                });
                catalogFormError?.toggleAttribute('hidden', true);
                updateCatalogSelectionState();
            });

            catalogProductModal?.addEventListener('click', (event) => {
                const addButton = event.target.closest('[data-catalog-add]');
                if (addButton) {
                    addCatalogSelection(addButton);
                    return;
                }

                const pageButton = event.target.closest('[data-catalog-page]');
                if (pageButton && !pageButton.disabled) {
                    loadCatalogProducts(Number.parseInt(pageButton.dataset.catalogPage || '1', 10));
                }
            });

            catalogProductModal?.querySelector('[data-catalog-search-submit]')?.addEventListener('click', () => loadCatalogProducts());
            catalogProductModal?.querySelector('[data-catalog-search-clear]')?.addEventListener('click', () => {
                if (catalogSearch) {
                    catalogSearch.value = '';
                }
                loadCatalogProducts();
                catalogSearch?.focus();
            });
            catalogSearch?.addEventListener('keydown', (event) => {
                if (event.key === 'Enter') {
                    event.preventDefault();
                    loadCatalogProducts();
                }
            });

            const syncSalesDocumentNumberWidths = (root = document) => {
                const container = root.matches?.('[data-sales-document-actions]')
                    ? root
                    : root.querySelector?.('[data-sales-document-actions]');
                const numberButtons = Array.from(container?.querySelectorAll('[data-sales-document-number]') || []);

                numberButtons.forEach((button) => button.style.removeProperty('min-width'));

                if (numberButtons.length < 2) {
                    return;
                }

                const widestButton = Math.ceil(Math.max(...numberButtons.map((button) => button.getBoundingClientRect().width)));

                numberButtons.forEach((button) => {
                    button.style.minWidth = `${widestButton}px`;
                });
            };

            const initializeOrderKsefTooltips = (root = document) => {
                if (typeof bootstrap === 'undefined') {
                    return;
                }

                const tooltipElements = root.matches?.('[data-order-ksef-tooltip]')
                    ? [root]
                    : Array.from(root.querySelectorAll?.('[data-order-ksef-tooltip]') || []);

                tooltipElements.forEach((element) => {
                    bootstrap.Tooltip.getOrCreateInstance(element, {
                        container: 'body',
                        customClass: 'invoice-ksef-status-tooltip',
                    });
                });
            };

            syncSalesDocumentNumberWidths();
            initializeOrderKsefTooltips();
            document.querySelectorAll('[data-customer-portal-help]').forEach(element => {
                window.bootstrap?.Tooltip.getOrCreateInstance(element, {container: 'body', customClass: 'customer-portal-tooltip'});
            });
            window.addEventListener('load', () => syncSalesDocumentNumberWidths());
            document.fonts?.ready.then(() => syncSalesDocumentNumberWidths());

            const ksefSendModalElement = document.querySelector('[data-order-ksef-send-modal]');
            const ksefSendConfirmButton = document.querySelector('[data-order-ksef-send-confirm]');
            const ksefSendQuestion = document.querySelector('[data-order-ksef-send-question]');
            const ksefDefaultSendQuestion = ksefSendQuestion?.textContent || '';
            const salesDocumentDeleteModalElement = document.querySelector('[data-sales-document-delete-modal]');
            const salesDocumentDeleteForm = salesDocumentDeleteModalElement?.querySelector('[data-sales-document-delete-form]');
            const salesDocumentDeleteTitle = salesDocumentDeleteModalElement?.querySelector('[data-sales-document-delete-title]');
            const salesDocumentDeleteQuestion = salesDocumentDeleteModalElement?.querySelector('[data-sales-document-delete-question]');
            const salesDocumentDeleteError = salesDocumentDeleteModalElement?.querySelector('[data-sales-document-delete-error]');
            const salesDocumentDeleteLockVersion = salesDocumentDeleteModalElement?.querySelector('[data-sales-document-delete-lock-version]');
            let pendingKsefSendForm = null;
            let ksefSendInteractionActive = false;
            let salesDocumentDeleteInteractionActive = false;
            let salesDocumentMutationActive = false;
            let deferredSalesDocumentHtml = null;

            const salesDocumentInteractionActive = () => (
                ksefSendInteractionActive
                || salesDocumentDeleteInteractionActive
                || salesDocumentMutationActive
            );

            const documentDeleteLabels = {
                invoice: {
                    title: 'Usuwanie Faktury VAT',
                    name: 'fakturę',
                },
                proforma: {
                    title: 'Usuwanie Pro formy',
                    name: 'Pro formę',
                },
                correction: {
                    title: 'Usuwanie Korekty',
                    name: 'Korektę',
                },
            };

            const clearSalesDocumentDeleteError = () => {
                if (!salesDocumentDeleteError) {
                    return;
                }

                salesDocumentDeleteError.textContent = '';
                salesDocumentDeleteError.hidden = true;
            };

            document.addEventListener('click', (event) => {
                const trigger = event.target.closest('[data-sales-document-delete-trigger]');

                if (!trigger || !salesDocumentDeleteForm) {
                    return;
                }

                const labels = documentDeleteLabels[trigger.dataset.documentType] || {
                    title: 'Usuwanie dokumentu sprzedaży',
                    name: 'dokument',
                };
                const documentNumber = trigger.dataset.documentNumber || '';

                salesDocumentDeleteForm.action = trigger.dataset.deleteUrl || '';
                salesDocumentDeleteForm.dataset.documentId = trigger.dataset.documentId || '';
                salesDocumentDeleteForm.dataset.documentType = trigger.dataset.documentType || '';
                salesDocumentDeleteLockVersion.value = trigger.dataset.expectedLockVersion || '';
                salesDocumentDeleteTitle.textContent = labels.title;
                salesDocumentDeleteQuestion.textContent = `Czy na pewno chcesz usunąć ${labels.name} „${documentNumber}”? Tej operacji nie można cofnąć.`;
                clearSalesDocumentDeleteError();
            });

            const waitForModalHidden = (modal) => new Promise((resolve) => {
                if (!modal || typeof bootstrap === 'undefined' || !modal.classList.contains('show')) {
                    resolve();
                    return;
                }

                modal.addEventListener('hidden.bs.modal', resolve, { once: true });
                bootstrap.Modal.getOrCreateInstance(modal).hide();
            });

            document.addEventListener('submit', (event) => {
                const form = event.target.closest('[data-order-ksef-send-form]');

                if (!form) {
                    return;
                }

                event.preventDefault();
                if (form.dataset.ksefSubmitting === '1' || ksefSendConfirmButton?.disabled) {
                    return;
                }
                pendingKsefSendForm = form;
                if (ksefSendQuestion) {
                    ksefSendQuestion.textContent = form.dataset.ksefConfirmQuestion || ksefDefaultSendQuestion;
                }
                if (ksefSendModalElement && typeof bootstrap !== 'undefined') {
                    bootstrap.Modal.getOrCreateInstance(ksefSendModalElement).show();
                }
            });

            ksefSendModalElement?.addEventListener('show.bs.modal', () => {
                ksefSendInteractionActive = true;
            });

            ksefSendConfirmButton?.addEventListener('click', () => {
                if (!pendingKsefSendForm) {
                    return;
                }

                const form = pendingKsefSendForm;
                pendingKsefSendForm = null;
                ksefSendConfirmButton.disabled = true;
                form.dataset.ksefSubmitting = '1';
                form.querySelectorAll('button[type="submit"]').forEach((button) => { button.disabled = true; });
                HTMLFormElement.prototype.submit.call(form);
            });

            ksefSendModalElement?.addEventListener('hidden.bs.modal', () => {
                ksefSendInteractionActive = false;
                pendingKsefSendForm = null;
                ksefSendConfirmButton.disabled = false;
                flushDeferredSalesDocumentRefresh();
            });

            salesDocumentDeleteModalElement?.addEventListener('show.bs.modal', () => {
                salesDocumentDeleteInteractionActive = true;
            });

            salesDocumentDeleteModalElement?.addEventListener('hidden.bs.modal', () => {
                salesDocumentDeleteInteractionActive = false;
                clearSalesDocumentDeleteError();
                flushDeferredSalesDocumentRefresh();
            });

            let ksefPdfGeneratorPromise = null;

            const loadKsefPdfGenerator = (source) => {
                const loaded = globalThis['ksef-fe-invoice-converter'];

                if (typeof loaded?.generateInvoice === 'function') {
                    return Promise.resolve(loaded);
                }

                if (!ksefPdfGeneratorPromise) {
                    ksefPdfGeneratorPromise = new Promise((resolve, reject) => {
                        const script = document.createElement('script');
                        script.src = source;
                        script.async = true;
                        script.dataset.ksefPdfGenerator = 'true';
                        script.addEventListener('load', () => {
                            const generator = globalThis['ksef-fe-invoice-converter'];

                            if (typeof generator?.generateInvoice === 'function') {
                                resolve(generator);
                                return;
                            }

                            reject(new Error('Nie udało się uruchomić generatora PDF KSeF.'));
                        });
                        script.addEventListener('error', () => {
                            ksefPdfGeneratorPromise = null;
                            reject(new Error('Nie udało się załadować generatora PDF KSeF.'));
                        });
                        document.head.append(script);
                    });
                }

                return ksefPdfGeneratorPromise;
            };

            const ksefInvoiceDownloadError = async (response, fallbackMessage) => {
                try {
                    const data = await response.json();

                    if (typeof data?.message === 'string' && data.message.trim() !== '') {
                        return data.message;
                    }
                } catch (error) {
                    // The controlled endpoint may fail before producing a JSON response.
                }

                return fallbackMessage;
            };

            document.addEventListener('click', async (event) => {
                const trigger = event.target.closest('[data-order-ksef-invoice-pdf]');

                if (!trigger) {
                    return;
                }

                event.preventDefault();

                if (trigger.getAttribute('aria-busy') === 'true') {
                    return;
                }

                trigger.setAttribute('aria-busy', 'true');
                const openPdf = trigger.dataset.ksefPdfOpen === '1';
                const pdfErrorMessage = openPdf ? 'Nie udało się otworzyć PDF Korekty z KSeF.' : 'Nie udało się pobrać Faktury z KSeF.';
                let previewWindow = null;

                try {
                    if (openPdf) {
                        previewWindow = window.open('about:blank', '_blank');
                        if (!previewWindow) {
                            throw new Error('Zezwól przeglądarce na otwieranie nowej karty, aby zobaczyć PDF Korekty.');
                        }
                        previewWindow.opener = null;
                    }
                    const [generator, response] = await Promise.all([
                        loadKsefPdfGenerator(trigger.dataset.ksefPdfGeneratorSrc),
                        fetch(trigger.dataset.ksefInvoiceSourceUrl, {
                            headers: {
                                'Accept': 'application/xml',
                            },
                        }),
                    ]);

                    if (!response.ok) {
                        throw new Error(await ksefInvoiceDownloadError(response, pdfErrorMessage));
                    }

                    const xml = await response.text();
                    const invoiceFile = new File([xml], 'invoice.xml', {
                        type: 'application/xml',
                    });
                    const additionalData = {
                        nrKSeF: trigger.dataset.ksefNumber,
                        isMobile: false,
                    };

                    if (trigger.dataset.ksefAcquisitionDate) {
                        additionalData.acDate = trigger.dataset.ksefAcquisitionDate;
                    }

                    if (trigger.dataset.ksefVerificationUrl) {
                        additionalData.qrCode = trigger.dataset.ksefVerificationUrl;
                    }

                    const pdf = await generator.generateInvoice(invoiceFile, additionalData, 'blob');
                    const url = URL.createObjectURL(pdf);
                    if (previewWindow) {
                        previewWindow.location.replace(url);
                        const releasePreviewUrl = () => {
                            if (previewWindow.closed) {
                                URL.revokeObjectURL(url);
                            } else {
                                window.setTimeout(releasePreviewUrl, 1000);
                            }
                        };
                        releasePreviewUrl();
                    } else {
                        const download = document.createElement('a');
                        download.href = url;
                        download.download = trigger.dataset.ksefPdfFilename || 'Faktura_KSeF.pdf';
                        document.body.append(download);
                        download.click();
                        download.remove();
                        window.setTimeout(() => URL.revokeObjectURL(url), 1000);
                    }
                } catch (error) {
                    previewWindow?.close();
                    alert(error instanceof Error && error.message
                        ? error.message
                        : pdfErrorMessage);
                } finally {
                    trigger.removeAttribute('aria-busy');
                }
            });

            const closeSection = (section) => {
                if (!section) {
                    return;
                }
                const form = section.querySelector('form.inline-section-edit');
                if (form) {
                    form.reset();
                }
                section.classList.remove('is-editing');
                const deferredFields = form && deferredFormFields.get(form);
                if (deferredFields) {
                    syncEditableFields(deferredFields, form);
                }
            };

            const openSection = (sectionName, fieldName = null) => {
                if (document.querySelector('[data-purchase-readiness]') && (sectionName !== 'order-info' || (fieldName && fieldName !== 'notes'))) return;
                const section = document.querySelector(`[data-inline-section="${sectionName}"]`);
                if (!section) {
                    return;
                }
                section.classList.add('is-editing');
                const form = section.querySelector('form.inline-section-edit');
                if (document.querySelector('[data-purchase-readiness]') && form) {
                    form.querySelectorAll('input:not([type="hidden"]), select, textarea').forEach(field => { field.disabled = field.name !== 'notes'; });
                }
                const requestedField = fieldName && form ? form.elements.namedItem(fieldName) : null;
                const firstField = form?.querySelector('input:not([type="hidden"]):not(:disabled), select:not(:disabled), textarea:not(:disabled)');
                const focusTarget = requestedField instanceof HTMLElement ? requestedField : firstField;
                if (focusTarget) {
                    window.requestAnimationFrame(() => {
                        focusTarget.focus();

                        if (focusTarget instanceof HTMLInputElement || focusTarget instanceof HTMLTextAreaElement) {
                            focusTarget.select();
                        }
                    });
                }
            };

            document.addEventListener('click', (event) => {
                const editTrigger = event.target.closest('[data-edit-section]');
                if (editTrigger) {
                    event.preventDefault();
                    const fieldTrigger = event.target.closest('[data-edit-field]');
                    openSection(editTrigger.dataset.editSection, fieldTrigger?.dataset.editField);
                    return;
                }

                const cancelButton = event.target.closest('.inline-cancel');
                if (cancelButton) {
                    closeSection(cancelButton.closest('.inline-section'));
                }
            });

            const billingForm = document.querySelector('[data-billing-form]');
            const shippingSection = document.querySelector('[data-inline-section="shipping"]');
            const shippingForm = shippingSection?.querySelector('form.inline-section-edit');
            const copyShippingToBillingButton = document.querySelector('[data-copy-shipping-to-billing]');
            const copyBillingToShippingButton = document.querySelector('[data-copy-billing-to-shipping]');
            const gusLookupButton = document.querySelector('[data-gus-lookup-button]');
            const gusNipInput = document.querySelector('[data-gus-nip-input]');
            const gusMessage = document.querySelector('[data-gus-message]');
            const gusResults = document.querySelector('[data-gus-results]');
            const gusResultsSelect = document.querySelector('[data-gus-results-select]');
            const gusResultUseButton = document.querySelector('[data-gus-result-use]');
            let gusCompanies = [];

            const setGusMessage = (message, isError = true) => {
                if (!gusMessage) {
                    return;
                }
                gusMessage.textContent = message || '';
                gusMessage.classList.toggle('text-danger', isError);
                gusMessage.classList.toggle('text-success', !isError);
            };

            const formatGusAddressLine = (company) => {
                const street = String(company.street || '').trim();
                const buildingNumber = String(company.buildingNumber || '').trim();
                const apartmentNumber = String(company.apartmentNumber || '').trim();
                const base = `${street} ${buildingNumber}`.trim();

                if (!base) {
                    return '';
                }

                return apartmentNumber ? `${base}/${apartmentNumber}` : base;
            };

            const hideGusResults = () => {
                gusCompanies = [];

                if (gusResults) {
                    gusResults.hidden = true;
                }

                if (gusResultsSelect) {
                    gusResultsSelect.replaceChildren();
                }
            };

            const getFormValue = (form, name) => {
                return form?.querySelector(`[name="${name}"]`)?.value || '';
            };

            const setFormValue = (form, name, value) => {
                const input = form?.querySelector(`[name="${name}"]`);

                if (!input) {
                    return;
                }

                input.value = String(value || '').trim();
            };

            const applyGusCompany = (company) => {
                setFormValue(billingForm, 'billing_name', '');
                setFormValue(billingForm, 'billing_company_name', company.name);
                setFormValue(billingForm, 'billing_tax_id', company.nip || gusNipInput?.value);
                setFormValue(billingForm, 'billing_address_line', formatGusAddressLine(company));
                setFormValue(billingForm, 'billing_postal_code', company.postalCode);
                setFormValue(billingForm, 'billing_city', company.city);
                setFormValue(billingForm, 'billing_province', company.province);
                setFormValue(billingForm, 'billing_country_code', company.countryCode || 'PL');
                hideGusResults();
                setGusMessage('Dane z GUS uzupełniły formularz. Kliknij Zapisz, aby je zapisać.', false);
            };

            const gusCompanyLabel = (company) => {
                const address = [formatGusAddressLine(company), company.postalCode, company.city]
                    .filter(Boolean)
                    .join(', ');
                const regon = company.regon ? `REGON ${company.regon}` : '';
                const ended = company.endedAt ? `zakończona ${company.endedAt}` : '';
                const details = [regon, address, ended].filter(Boolean).join(' · ');

                return details ? `${company.name} — ${details}` : company.name;
            };

            const showGusResults = (companies) => {
                if (!gusResults || !gusResultsSelect) {
                    return;
                }

                gusCompanies = companies;
                gusResultsSelect.replaceChildren(...companies.map((company, index) => new Option(gusCompanyLabel(company), String(index))));
                gusResults.hidden = false;
            };

            if (copyShippingToBillingButton && billingForm && shippingForm) {
                copyShippingToBillingButton.addEventListener('click', () => {
                    openSection('billing');

                    setFormValue(billingForm, 'billing_name', getFormValue(shippingForm, 'shipping_name'));
                    setFormValue(billingForm, 'billing_company_name', getFormValue(shippingForm, 'shipping_company_name'));
                    setFormValue(billingForm, 'billing_address_line', getFormValue(shippingForm, 'shipping_address_line'));
                    setFormValue(billingForm, 'billing_postal_code', getFormValue(shippingForm, 'shipping_postal_code'));
                    setFormValue(billingForm, 'billing_city', getFormValue(shippingForm, 'shipping_city'));
                    setFormValue(billingForm, 'billing_country_code', getFormValue(shippingForm, 'shipping_country_code'));
                    setFormValue(billingForm, 'billing_tax_id', '');
                    hideGusResults();
                    setGusMessage('');
                });
            }

            if (copyBillingToShippingButton && billingForm && shippingForm) {
                copyBillingToShippingButton.addEventListener('click', () => {
                    openSection('shipping');

                    setFormValue(shippingForm, 'shipping_name', getFormValue(billingForm, 'billing_name'));
                    setFormValue(shippingForm, 'shipping_company_name', getFormValue(billingForm, 'billing_company_name'));
                    setFormValue(shippingForm, 'shipping_address_line', getFormValue(billingForm, 'billing_address_line'));
                    setFormValue(shippingForm, 'shipping_postal_code', getFormValue(billingForm, 'billing_postal_code'));
                    setFormValue(shippingForm, 'shipping_city', getFormValue(billingForm, 'billing_city'));
                    setFormValue(shippingForm, 'shipping_country_code', getFormValue(billingForm, 'billing_country_code'));
                });
            }

            if (billingForm && gusLookupButton && gusNipInput) {
                gusNipInput.addEventListener('input', () => {
                    hideGusResults();
                    setGusMessage('');
                });

                gusResultUseButton?.addEventListener('click', () => {
                    const company = gusCompanies[Number(gusResultsSelect?.value || 0)];

                    if (company) {
                        applyGusCompany(company);
                    }
                });

                gusLookupButton.addEventListener('click', async () => {
                    const nip = gusNipInput.value.replace(/[\s-]+/g, '').replace(/\D+/g, '');

                    hideGusResults();
                    setGusMessage('');

                    if (nip.length !== 10) {
                        setGusMessage('NIP musi mieć 10 cyfr.');
                        gusNipInput.focus();
                        return;
                    }

                    const originalButtonText = gusLookupButton.textContent;
                    gusLookupButton.disabled = true;
                    gusLookupButton.textContent = '...';

                    try {
                        const url = new URL(billingForm.dataset.gusLookupUrl, window.location.origin);
                        const csrfToken = billingForm.querySelector('input[name="_token"]')?.value || '';

                        const response = await fetch(url.toString(), {
                            method: 'POST',
                            headers: {
                                'Accept': 'application/json',
                                'Content-Type': 'application/json',
                                'X-CSRF-TOKEN': csrfToken,
                            },
                            body: JSON.stringify({ nip }),
                        });
                        const payload = await response.json().catch(() => ({}));

                        if (!response.ok) {
                            throw new Error(payload.message || 'Nie udało się pobrać danych z GUS.');
                        }

                        const companies = Array.isArray(payload.companies) ? payload.companies : [];

                        if (companies.length === 1) {
                            applyGusCompany(companies[0]);
                        } else if (companies.length > 1) {
                            showGusResults(companies);
                            setGusMessage('GUS zwrócił kilka wpisów. Wybierz właściwe dane firmy.', false);
                        } else {
                            throw new Error('Nie znaleziono firmy dla podanego NIP.');
                        }
                    } catch (error) {
                        setGusMessage(error.message || 'Nie udało się pobrać danych z GUS.');
                    } finally {
                        gusLookupButton.disabled = false;
                        gusLookupButton.textContent = originalButtonText;
                    }
                });
            }

            const openPaidEdit = () => {
                if (document.querySelector('[data-purchase-readiness]')) return;
                const paidView = document.querySelector('[data-paid-view]');
                const paidEdit = document.querySelector('[data-paid-edit]');
                const paidInput = document.querySelector('[data-paid-input]');

                if (!paidEdit || !paidView || !paidInput) {
                    return;
                }
                paidView.style.display = 'none';
                paidEdit.classList.add('is-editing');
                paidInput.dataset.originalValue = paidInput.value;
                paidInput.focus();
                paidInput.select();
            };

            const closePaidEdit = () => {
                const paidView = document.querySelector('[data-paid-view]');
                const paidEdit = document.querySelector('[data-paid-edit]');
                const paidInput = document.querySelector('[data-paid-input]');

                if (!paidEdit || !paidView || !paidInput) {
                    return;
                }
                paidInput.value = paidInput.dataset.originalValue || paidInput.value;
                paidEdit.classList.remove('is-editing');
                paidView.style.display = '';
                const deferredFields = deferredFormFields.get(paidEdit);
                if (deferredFields) {
                    syncEditableFields(deferredFields, paidEdit);
                }
                if (deferredOrderInfoHtml !== null) {
                    const html = deferredOrderInfoHtml;
                    deferredOrderInfoHtml = null;
                    applyOrderFragments({ fragments: { 'order-info': html } });
                }
            };

            document.addEventListener('click', (event) => {
                if (event.target.closest('[data-paid-edit-open]')) {
                    openPaidEdit();
                    return;
                }

                if (event.target.closest('[data-paid-cancel]')) {
                    closePaidEdit();
                    return;
                }

                const presetButton = event.target.closest('[data-paid-set]');
                if (presetButton) {
                    const paidEdit = document.querySelector('[data-paid-edit]');
                    const paidInput = document.querySelector('[data-paid-input]');
                    if (paidInput && paidEdit) {
                        paidInput.value = Number(presetButton.dataset.paidSet).toFixed(2);
                        paidEdit.requestSubmit();
                    }
                }
            });

            document.addEventListener('input', (event) => {
                if (event.target.matches('[data-paid-input]')) {
                    event.target.value = event.target.value.replace(',', '.');
                }
            });

            document.addEventListener('keydown', (event) => {
                if (event.target.matches('[data-paid-input]') && event.key === 'Escape') {
                    event.preventDefault();
                    closePaidEdit();
                }
            });

            const disposePaymentLinkTooltips = (root = document) => {
                root.querySelectorAll('[data-payment-link-action], [data-payment-id-warning]').forEach((button) => {
                    window.bootstrap?.Tooltip.getInstance(button)?.dispose();
                });
            };

            const initializePaymentLinkTooltips = (root = document) => {
                if (!window.bootstrap?.Tooltip) {
                    return;
                }

                root.querySelectorAll('[data-payment-link-action], [data-payment-id-warning]').forEach((button) => {
                    window.bootstrap.Tooltip.getOrCreateInstance(button);
                });
            };

            const paymentLinkErrorMessage = (payload) => {
                const messages = Object.values(payload?.errors || {}).flat();

                return messages[0] || payload?.message || 'Nie udało się wygenerować linku płatności PayNow.';
            };

            const requestPaymentLink = async (button) => {
                const endpoint = button.dataset.paymentLinkUrl;
                if (!endpoint) {
                    throw new Error('Brak adresu generowania linku płatności PayNow.');
                }

                if (paymentLinkRequests.has(endpoint)) {
                    return paymentLinkRequests.get(endpoint);
                }

                const request = (async () => {
                    const previousHtml = button.innerHTML;
                    window.bootstrap?.Tooltip.getInstance(button)?.hide();
                    button.disabled = true;
                    button.setAttribute('aria-busy', 'true');
                    button.innerHTML = '<span class="spinner-border spinner-border-sm" aria-hidden="true"></span>';

                    try {
                        const response = await fetch(endpoint, {
                            method: 'POST',
                            headers: {
                                Accept: 'application/json',
                                'Content-Type': 'application/json',
                                'X-CSRF-TOKEN': pageCsrfToken,
                                'X-Requested-With': 'XMLHttpRequest',
                            },
                            body: '{}',
                        });
                        const payload = await response.json().catch(() => ({}));

                        if (!response.ok || typeof payload.url !== 'string' || payload.url === '') {
                            throw new Error(paymentLinkErrorMessage(payload));
                        }

                        return payload;
                    } finally {
                        button.disabled = false;
                        button.removeAttribute('aria-busy');
                        button.innerHTML = previousHtml;
                        paymentLinkRequests.delete(endpoint);
                    }
                })();

                paymentLinkRequests.set(endpoint, request);

                return request;
            };

            const copyToClipboard = async (text) => {
                if (navigator.clipboard?.writeText && window.isSecureContext) {
                    await navigator.clipboard.writeText(text);
                    return;
                }

                const input = document.createElement('textarea');
                input.value = text;
                input.setAttribute('readonly', 'readonly');
                input.style.position = 'fixed';
                input.style.opacity = '0';
                document.body.appendChild(input);
                input.select();

                const copied = document.execCommand('copy');
                input.remove();

                if (!copied) {
                    throw new Error('Nie udało się skopiować tekstu do schowka.');
                }
            };

            document.addEventListener('click', (event) => {
                const link = event.target.closest('[data-allegro-transaction]');
                if (!link || event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) {
                    return;
                }

                event.preventDefault();
                const currentTimer = allegroTransactionClickTimers.get(link);
                if (currentTimer) {
                    window.clearTimeout(currentTimer);
                    allegroTransactionClickTimers.delete(link);
                }
                if (event.detail > 1) {
                    return;
                }

                const timer = window.setTimeout(async () => {
                    allegroTransactionClickTimers.delete(link);
                    try {
                        await copyToClipboard(link.dataset.allegroTransaction);
                    } catch (error) {
                        const message = 'Nie udało się skopiować numeru transakcji do schowka. Sprawdź uprawnienia przeglądarki.';
                        if (typeof window.nexOmsShowError === 'function') {
                            window.nexOmsShowError(message);
                        } else {
                            window.alert(message);
                        }
                    }
                }, 300);
                allegroTransactionClickTimers.set(link, timer);
            });

            document.addEventListener('dblclick', (event) => {
                const link = event.target.closest('[data-allegro-transaction]');
                if (!link || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) {
                    return;
                }

                event.preventDefault();
                const currentTimer = allegroTransactionClickTimers.get(link);
                if (currentTimer) {
                    window.clearTimeout(currentTimer);
                    allegroTransactionClickTimers.delete(link);
                }
                window.open(link.href, '_blank', 'noopener,noreferrer');
            });

            const showPaymentLinkActivity = (payload, statusLabel, message) => {
                window.nexOmsShowActivity?.({
                    tone: 'success',
                    title: 'PayNow',
                    message,
                    orderLabel: payload.order?.label || '',
                    orderUrl: payload.order?.url || '#',
                    statusLabel,
                });
            };

            const showPaymentLinkError = (button, error) => {
                const message = error?.message || 'Nie udało się wygenerować linku płatności PayNow.';
                window.nexOmsShowActivity?.({
                    tone: 'error',
                    title: 'PayNow',
                    message,
                    orderLabel: `Zamówienie ${currentOrderId}`,
                    orderUrl: window.location.href,
                    statusLabel: 'Błąd',
                });

                if (typeof window.nexOmsShowActivity !== 'function') {
                    window.nexOmsShowError?.(message);
                }
            };

            document.addEventListener('click', (event) => {
                const button = event.target.closest('[data-payment-link-action]');
                if (!button || button.disabled || event.detail > 1) {
                    return;
                }

                event.preventDefault();
                const currentTimer = paymentLinkClickTimers.get(button);
                if (currentTimer) {
                    window.clearTimeout(currentTimer);
                }

                const timer = window.setTimeout(async () => {
                    paymentLinkClickTimers.delete(button);
                    try {
                        const payload = await requestPaymentLink(button);
                        await copyToClipboard(payload.url);
                        showPaymentLinkActivity(payload, 'Link skopiowany', payload.message || 'Link płatności PayNow został skopiowany do schowka.');
                    } catch (error) {
                        showPaymentLinkError(button, error);
                    }
                }, 260);

                paymentLinkClickTimers.set(button, timer);
            });

            document.addEventListener('dblclick', async (event) => {
                const button = event.target.closest('[data-payment-link-action]');
                if (!button || button.disabled) {
                    return;
                }

                event.preventDefault();
                const currentTimer = paymentLinkClickTimers.get(button);
                if (currentTimer) {
                    window.clearTimeout(currentTimer);
                    paymentLinkClickTimers.delete(button);
                }

                const paymentWindow = window.open('about:blank', '_blank');
                if (paymentWindow) {
                    paymentWindow.opener = null;
                }

                try {
                    const payload = await requestPaymentLink(button);
                    if (!paymentWindow) {
                        throw new Error('Przeglądarka zablokowała otwarcie linku w nowej karcie.');
                    }

                    paymentWindow.location.replace(payload.url);
                    showPaymentLinkActivity(payload, 'Link otwarty', payload.reused
                        ? 'Otworzono istniejący link płatności PayNow.'
                        : 'Utworzono i otworzono link płatności PayNow.');
                } catch (error) {
                    paymentWindow?.close();
                    showPaymentLinkError(button, error);
                }
            });

            initializePaymentLinkTooltips();

            const managementStatusInput = document.querySelector('[data-management-status-input]');
            const managementStatusDot = document.querySelector('[data-management-status-dot]');
            const managementStatusLabel = document.querySelector('[data-management-status-label]');
            const managementStatusOptions = Array.from(document.querySelectorAll('[data-management-status-option]'));
            const managementStatusForm = document.querySelector('[data-management-status-form]');
            const managementStatusSubmit = document.querySelector('[data-management-status-submit]');
            const managementStatusDate = document.querySelector('[data-management-status-date]');
            const managementStatusDateValue = document.querySelector('[data-management-status-date-value]');
            const managementStatusTimeValue = document.querySelector('[data-management-status-time-value]');
            const orderPage = document.querySelector('[data-order-state-url]');
            const orderStateUrl = orderPage?.dataset.orderStateUrl;
            const currentOrderId = Number(orderPage?.dataset.orderId || 0);
            let currentServerStatus = managementStatusInput?.value || null;
            let orderStateRequestRunning = false;
            let currentLatestEventId = null;
            let currentShipmentsSignature = null;
            let currentBuyerCommentsSignature = null;
            let payNowRefreshTimer = null;

            const cancelShipmentModal = document.getElementById('cancelShipmentModal');
            const courierFormButtons = Array.from(document.querySelectorAll('[data-courier-form-button]'));
            const courierFormHost = document.querySelector('[data-courier-form-host]');
            const shipmentNotice = document.querySelector('[data-shipment-ajax-notice]');
            const shipmentsEmpty = document.querySelector('[data-shipments-empty]');
            const shipmentsTableWrap = document.querySelector('[data-shipments-table-wrap]');
            const shipmentsTableBody = document.querySelector('[data-shipments-table-body]');
            let activeCourierProvider = null;
            let courierFormLoadController = null;

            const removeCourierFormPanels = () => {
                courierFormHost?.querySelectorAll('[data-courier-form-panel]').forEach((panel) => panel.remove());
            };

            const activateCourierPanel = (provider) => {
                activeCourierProvider = provider;
                courierFormHost?.classList.remove('d-none');

                courierFormHost?.querySelectorAll('[data-courier-form-panel]').forEach((panel) => {
                    panel.classList.toggle('d-none', panel.dataset.courierProvider !== provider);
                });

                courierFormButtons.forEach((button) => {
                    const isActive = button.dataset.courierProvider === provider;
                    button.classList.toggle('is-active', isActive);
                    button.setAttribute('aria-expanded', isActive ? 'true' : 'false');
                });
            };

            const closeCourierForm = () => {
                courierFormLoadController?.abort();
                courierFormLoadController = null;
                removeCourierFormPanels();
                activeCourierProvider = null;
                courierFormHost?.classList.add('d-none');
                courierFormButtons.forEach((button) => {
                    button.disabled = false;
                    button.classList.remove('is-active');
                    button.setAttribute('aria-expanded', 'false');
                });
            };

            const updateCourierParcelRows = (form) => {
                const parcels = form?.querySelector('[data-courier-parcels]');
                const rows = Array.from(parcels?.querySelectorAll('[data-courier-parcel]') || []);

                rows.forEach((row, index) => {
                    row.querySelectorAll('[name^="parcels["]').forEach((input) => {
                        input.name = input.name.replace(/^parcels\[\d+\]/, `parcels[${index}]`);
                    });

                    const values = Array.from(row.querySelectorAll('[data-courier-parcel-value]')).map((input) => Number(input.value || 0));
                    const [weight, length, width, height] = values;
                    const volumetricWeight = length > 0 && width > 0 && height > 0
                        ? (length * width * height / 5000).toFixed(2)
                        : '0.00';
                    const note = row.querySelector('[data-courier-volume]');

                    if (note) {
                        note.textContent = `Waga gabarytowa: ${volumetricWeight} kg; waga rzeczywista: ${(weight || 0).toFixed(2)} kg`;
                    }

                    const removeButton = row.querySelector('[data-remove-courier-parcel]');
                    if (removeButton) {
                        removeButton.disabled = rows.length === 1;
                    }
                });
            };

            const loadCourierForm = async (button) => {
                const provider = button?.dataset.courierProvider;
                const url = button?.dataset.courierFormUrl;

                if (!courierFormHost || !button || !provider || !url) {
                    return;
                }

                courierFormLoadController?.abort();
                const loadController = new AbortController();
                courierFormLoadController = loadController;

                removeCourierFormPanels();
                activateCourierPanel(provider);
                courierFormButtons.forEach((courierButton) => {
                    courierButton.disabled = true;
                });
                const stopCourierLoading = startAjaxLoading(courierFormHost, 'Pobieranie formularza przesyłki');

                try {
                    const response = await fetch(url, {
                        headers: {
                            Accept: 'application/json',
                            'X-Requested-With': 'XMLHttpRequest',
                        },
                        cache: 'no-store',
                        signal: loadController.signal,
                    });
                    const payload = await response.json().catch(() => ({}));

                    if (!response.ok || !payload.html) {
                        throw new Error(payload.message || 'Nie udało się pobrać formularza przesyłki.');
                    }

                    const template = document.createElement('template');
                    template.innerHTML = payload.html.trim();
                    const newPanel = template.content.firstElementChild;

                    if (!newPanel) {
                        throw new Error('Otrzymano nieprawidłowy formularz przesyłki.');
                    }

                    courierFormHost.appendChild(newPanel);

                    updateCourierParcelRows(newPanel.querySelector('[data-courier-shipment-form]'));
                    activateCourierPanel(provider);
                    shipmentNotice?.classList.add('d-none');
                } catch (error) {
                    if (error.name === 'AbortError') {
                        return;
                    }

                    removeCourierFormPanels();
                    activeCourierProvider = null;
                    courierFormHost.classList.add('d-none');
                    courierFormButtons.forEach((courierButton) => {
                        courierButton.classList.remove('is-active');
                        courierButton.setAttribute('aria-expanded', 'false');
                    });
                    showShipmentNotice(error.message || 'Nie udało się pobrać formularza przesyłki.', 'danger');
                } finally {
                    if (courierFormLoadController === loadController) {
                        courierFormLoadController = null;
                        stopCourierLoading();
                        courierFormButtons.forEach((courierButton) => {
                            courierButton.disabled = false;
                        });
                    }
                }
            };

            const reloadActiveCourierFormDefaults = async () => {
                const button = courierFormButtons.find((candidate) => candidate.dataset.courierProvider === activeCourierProvider);

                if (button) {
                    await loadCourierForm(button);
                }
            };

            courierFormButtons.forEach((button) => {
                button.addEventListener('click', () => {
                    if (activeCourierProvider === button.dataset.courierProvider && !courierFormHost?.classList.contains('d-none')) {
                        closeCourierForm();
                        return;
                    }

                    loadCourierForm(button);
                });
            });

            courierFormHost?.addEventListener('input', (event) => {
                const form = event.target.closest('[data-courier-shipment-form]');

                if (!form) {
                    return;
                }

                if (event.target.matches('[data-courier-parcel-value]')) {
                    updateCourierParcelRows(form);
                }

                if (event.target.matches('[data-courier-cod]')) {
                    const insurance = form.querySelector('[data-courier-insurance]');
                    const cod = Number(String(event.target.value || '0').replace(',', '.'));
                    const insuranceValue = Number(String(insurance?.value || '0').replace(',', '.'));

                    if (insurance && cod > insuranceValue) {
                        insurance.value = cod.toFixed(2);
                    }
                }
            });

            courierFormHost?.addEventListener('change', (event) => {
                const select = event.target.closest('[data-courier-parcel-template-select]');
                const row = select?.closest('[data-courier-parcel]');
                const form = select?.closest('[data-courier-shipment-form]');
                const option = select?.selectedOptions?.[0];

                if (!select?.value || !row || !form || !option) {
                    return;
                }

                ['weight', 'length', 'width', 'height'].forEach((field) => {
                    const input = row.querySelector(`[name$="[${field}]"]`);
                    const value = option.dataset[field];

                    if (input && value) {
                        input.value = value;
                    }
                });

                updateCourierParcelRows(form);
            });

            courierFormHost?.addEventListener('click', (event) => {
                const closeButton = event.target.closest('[data-close-courier-form]');
                const addButton = event.target.closest('[data-add-courier-parcel]');
                const removeButton = event.target.closest('[data-remove-courier-parcel]');
                const form = event.target.closest('[data-courier-shipment-form]');

                if (closeButton) {
                    closeCourierForm();
                    return;
                }

                if (!form) {
                    return;
                }

                const parcels = form.querySelector('[data-courier-parcels]');
                const parcelTemplate = form.closest('[data-courier-form-panel]')?.querySelector('[data-courier-parcel-template]');

                if (addButton && parcelTemplate && parcels) {
                    const index = parcels.querySelectorAll('[data-courier-parcel]').length;
                    parcels.insertAdjacentHTML('beforeend', parcelTemplate.innerHTML.replaceAll('INDEX', String(index)));
                    updateCourierParcelRows(form);
                }

                if (removeButton && parcels?.querySelectorAll('[data-courier-parcel]').length > 1) {
                    removeButton.closest('[data-courier-parcel]')?.remove();
                    updateCourierParcelRows(form);
                }
            });

            const showShipmentNotice = (message, type = 'info', autoHide = false) => {
                if (!shipmentNotice) {
                    return;
                }

                shipmentNotice.textContent = message;
                shipmentNotice.className = `alert alert-${type} py-2 px-3 small`;

                if (autoHide) {
                    window.setTimeout(() => shipmentNotice.classList.add('d-none'), 5000);
                }
            };

            const replaceShipmentRow = (payload) => {
                if (!shipmentsTableBody || !payload?.row_html) {
                    return;
                }

                const rowContainer = document.createElement('tbody');
                rowContainer.innerHTML = payload.row_html.trim();
                const newRow = rowContainer.firstElementChild;

                if (!newRow) {
                    return;
                }

                const currentRow = shipmentsTableBody.querySelector(`[data-shipment-id="${payload.id}"]`);

                if (currentRow) {
                    window.nexOmsDisposePrinterControls?.(currentRow);
                    currentRow.replaceWith(newRow);
                } else {
                    shipmentsTableBody.prepend(newRow);
                }

                window.nexOmsInitializePrinterControls?.(newRow);
                shipmentsEmpty?.classList.add('d-none');
                shipmentsTableWrap?.classList.remove('d-none');
            };

            const shipmentErrorMessage = (payload, fallback) => {
                const errors = payload?.errors ? Object.values(payload.errors).flat() : [];

                return errors[0] || payload?.message || fallback;
            };

            const shipmentCreationSucceeded = (payload) => (
                payload?.status === 'succeeded' && Boolean(payload?.tracking_number)
            );

            const closeShipmentFormAfterSuccess = (form) => {
                form?.closest('[data-courier-form-panel]')?.remove();
                closeCourierForm();
            };

            const pollShipment = async (statusUrl, attempt = 0, form = null) => {
                if (!statusUrl || attempt >= 120) {
                    showShipmentNotice('Przesy\u0142ka nadal oczekuje na obs\u0142ug\u0119 kolejki. Sprawd\u017a, czy worker kolejki jest uruchomiony.', 'warning');
                    return false;
                }

                await new Promise((resolve) => window.setTimeout(resolve, 2000));

                try {
                    const response = await fetch(statusUrl, {
                        cache: 'no-store',
                        headers: {
                            Accept: 'application/json',
                            'X-Requested-With': 'XMLHttpRequest',
                        },
                    });
                    const payload = await response.json();

                    if (!response.ok) {
                        throw new Error(shipmentErrorMessage(payload, 'Nie uda\u0142o si\u0119 odczyta\u0107 statusu przesy\u0142ki.'));
                    }

                    replaceShipmentRow(payload);

                    if (payload.polling_finished) {
                        if (shipmentCreationSucceeded(payload)) {
                            const message = payload.label_available
                                ? 'Przesy\u0142ka zosta\u0142a utworzona. Numer nadawczy i etykieta s\u0105 ju\u017c dost\u0119pne.'
                                : 'Przesy\u0142ka zosta\u0142a utworzona. Numer nadawczy jest ju\u017c dost\u0119pny.';
                            showShipmentNotice(message, 'success', true);
                            closeShipmentFormAfterSuccess(form);
                        } else {
                            showShipmentNotice(payload.error_message || 'Nie uda\u0142o si\u0119 utworzy\u0107 przesy\u0142ki.', 'danger');
                        }

                        await fetchOrderState(['history', 'shipments'], true);

                        return shipmentCreationSucceeded(payload);
                    }

                    return pollShipment(payload.status_url || statusUrl, attempt + 1, form);
                } catch (error) {
                    showShipmentNotice(error.message || 'Oczekiwanie na aktualizacj\u0119 przesy\u0142ki...', 'warning');
                    return pollShipment(statusUrl, attempt + 1, form);
                }
            };

            courierFormHost?.addEventListener('submit', async (event) => {
                    const ajaxShipmentForm = event.target.closest('[data-ajax-shipment-form]');

                    if (!ajaxShipmentForm) {
                        return;
                    }

                    event.preventDefault();

                    if (ajaxShipmentForm.dataset.courierFormLoaded !== '1') {
                        showShipmentNotice('Najpierw wybierz kuriera i poczekaj na pobranie danych przesy\u0142ki.', 'warning');
                        return;
                    }

                    const submitButton = ajaxShipmentForm.querySelector('button[type="submit"]');
                    const defaultButtonText = submitButton?.textContent;

                    ajaxShipmentForm.querySelectorAll('.is-invalid').forEach((field) => field.classList.remove('is-invalid'));

                    if (submitButton) {
                        submitButton.disabled = true;
                        submitButton.textContent = 'Nadawanie...';
                    }

                    showShipmentNotice('Dodawanie przesy\u0142ki do kolejki...', 'info');

                    try {
                        const response = await fetch(ajaxShipmentForm.action, {
                            method: 'POST',
                            body: new FormData(ajaxShipmentForm),
                            headers: {
                                Accept: 'application/json',
                                'X-Requested-With': 'XMLHttpRequest',
                            },
                        });
                        const payload = await response.json();

                        if (!response.ok) {
                            Object.keys(payload.errors || {}).forEach((name) => {
                                ajaxShipmentForm.querySelector(`[name="${name}"]`)?.classList.add('is-invalid');
                            });
                            throw new Error(shipmentErrorMessage(payload, 'Nie uda\u0142o si\u0119 nada\u0107 przesy\u0142ki.'));
                        }

                        document.dispatchEvent(new Event('nexoms:automation-wake'));

                        if (payload.polling_finished) {
                            replaceShipmentRow(payload);

                            if (shipmentCreationSucceeded(payload)) {
                                const message = payload.label_available
                                    ? 'Przesy\u0142ka zosta\u0142a utworzona. Numer nadawczy i etykieta s\u0105 ju\u017c dost\u0119pne.'
                                    : 'Przesy\u0142ka zosta\u0142a utworzona. Numer nadawczy jest ju\u017c dost\u0119pny.';
                                showShipmentNotice(message, 'success', true);
                                closeShipmentFormAfterSuccess(ajaxShipmentForm);
                            } else {
                                showShipmentNotice(payload.error_message || 'Nie uda\u0142o si\u0119 utworzy\u0107 przesy\u0142ki.', 'danger');
                            }

                            await fetchOrderState(['history', 'shipments'], true);
                        } else {
                            showShipmentNotice('Trwa tworzenie przesy\u0142ki. Oczekiwanie na numer nadawczy...', 'info');
                            await pollShipment(payload.status_url, 0, ajaxShipmentForm);
                        }
                    } catch (error) {
                        showShipmentNotice(error.message || 'Nie uda\u0142o si\u0119 nada\u0107 przesy\u0142ki.', 'danger');
                    } finally {
                        if (submitButton) {
                            submitButton.disabled = false;
                            submitButton.textContent = defaultButtonText;
                        }
                    }
            });

            if (cancelShipmentModal) {
                cancelShipmentModal.addEventListener('show.bs.modal', (event) => {
                    const trigger = event.relatedTarget;
                    const form = cancelShipmentModal.querySelector('[data-cancel-shipment-form]');
                    const number = cancelShipmentModal.querySelector('[data-cancel-shipment-number]');
                    const localNumber = cancelShipmentModal.querySelector('[data-cancel-shipment-local-number]');
                    const localOnlyInput = cancelShipmentModal.querySelector('[data-cancel-shipment-local-only]');
                    const apiMessage = cancelShipmentModal.querySelector('[data-cancel-shipment-api-message]');
                    const localMessage = cancelShipmentModal.querySelector('[data-cancel-shipment-local-message]');
                    const showLocalWarning = trigger?.dataset.shipmentCancelLocalWarning === '1';

                    if (form && trigger?.dataset.shipmentCancelUrl) {
                        form.action = trigger.dataset.shipmentCancelUrl;
                    }

                    if (number) {
                        number.textContent = trigger?.dataset.shipmentCancelNumber || '';
                    }

                    if (localNumber) {
                        localNumber.textContent = trigger?.dataset.shipmentCancelNumber || '';
                    }

                    if (localOnlyInput) {
                        localOnlyInput.value = trigger?.dataset.shipmentCancelLocalOnly === '1' ? '1' : '0';
                    }

                    apiMessage?.classList.toggle('d-none', showLocalWarning);
                    localMessage?.classList.toggle('d-none', !showLocalWarning);
                });
            }

            if (managementStatusInput && managementStatusDot && managementStatusLabel) {
                managementStatusOptions.forEach((option) => {
                    option.addEventListener('click', () => {
                        managementStatusInput.value = option.dataset.status || '';
                        managementStatusDot.style.background = option.dataset.statusColor || '#64748b';
                        managementStatusLabel.textContent = option.dataset.statusLabel || option.textContent.trim();

                        managementStatusOptions.forEach((item) => item.classList.remove('active'));
                        option.classList.add('active');
                    });
                });
            }

            const fragmentTargets = {
                'order-info': '[data-order-info-view]',
                shipping: '[data-inline-section="shipping"] .inline-section-view',
                billing: '[data-inline-section="billing"] .inline-section-view',
                pickup: '[data-pickup-view]',
                products: '[data-products-table-body]',
                shipments: '[data-shipments-table-body]',
                history: '[data-order-history-body]',
                'automation-history': '[data-order-automation-history-body]',
                payments: '[data-order-payments-body]',
                'buyer-comments': '[data-order-buyer-comments]',
            };

            const syncEditableFields = (fields, savedForm = null) => {
                if (savedForm) {
                    deferredFormFields.delete(savedForm);
                }
                Object.entries(fields || {}).forEach(([name, value]) => {
                    document.querySelectorAll(`[data-order-ajax-form] [name="${name}"]`).forEach((field) => {
                        const form = field.closest('form');
                        const hasDraft = form?.closest('.inline-section.is-editing')
                            || form?.matches('[data-paid-edit].is-editing');
                        if (hasDraft && form !== savedForm) {
                            const deferredFields = deferredFormFields.get(form) || {};
                            deferredFields[name] = value;
                            deferredFormFields.set(form, deferredFields);
                            return;
                        }
                        const normalizedValue = value ?? '';

                        if (field.type === 'checkbox' || field.type === 'radio') {
                            field.checked = String(field.value) === String(normalizedValue);
                            field.defaultChecked = field.checked;
                            return;
                        }

                        field.value = String(normalizedValue);
                        if (field.tagName === 'SELECT') {
                            Array.from(field.options).forEach((option) => {
                                option.defaultSelected = option.selected;
                            });
                        } else {
                            field.defaultValue = field.value;
                        }
                    });
                });
            };

            const applyStarState = (color) => {
                const starButton = document.querySelector('.order-star');
                if (!starButton) {
                    return;
                }

                const starClasses = ['star-empty', 'star-orange', 'star-navy', 'star-green', 'star-blue', 'star-red'];
                starButton.classList.remove(...starClasses);
                starButton.classList.add(color ? `star-${color}` : 'star-empty');
                const starIcon = starButton.querySelector('[data-order-star-icon]');
                starIcon?.classList.toggle('bi-star', !color);
                starIcon?.classList.toggle('bi-star-fill', Boolean(color));

                document.querySelectorAll('.star-picker form').forEach((form) => {
                    const button = form.querySelector('.star-color-button');
                    button?.classList.toggle('is-active', (form.querySelector('[name="star_color"]')?.value || '') === (color || ''));
                });
            };

            const disposeOrderKsefTooltips = (root) => {
                if (typeof bootstrap === 'undefined') {
                    return;
                }

                root.querySelectorAll('[data-order-ksef-tooltip]').forEach((element) => {
                    bootstrap.Tooltip.getInstance(element)?.dispose();
                });
            };

            const replaceSalesDocumentActions = (html) => {
                const current = document.querySelector('[data-sales-document-actions]');
                const template = document.createElement('template');
                template.innerHTML = String(html || '').trim();
                const replacement = template.content.firstElementChild;

                if (!current || !replacement) {
                    return false;
                }

                deferredSalesDocumentHtml = null;
                disposeOrderKsefTooltips(current);
                window.nexOmsDisposePrinterControls?.(current);
                current.replaceWith(replacement);
                syncSalesDocumentNumberWidths(replacement);
                initializeOrderKsefTooltips(replacement);
                window.nexOmsInitializePrinterControls?.(replacement);
                scheduleKsefAutomaticRefresh();

                return true;
            };

            function flushDeferredSalesDocumentRefresh() {
                if (salesDocumentInteractionActive() || deferredSalesDocumentHtml === null) {
                    return;
                }

                const html = deferredSalesDocumentHtml;
                deferredSalesDocumentHtml = null;
                replaceSalesDocumentActions(html);
            }

            const applyOrderFragments = (state, savedForm = null) => {
                Object.entries(state.fragments || {}).forEach(([name, html]) => {
                    if (name === 'sales-documents') {
                        if (salesDocumentInteractionActive()) {
                            deferredSalesDocumentHtml = String(html || '');
                        } else {
                            replaceSalesDocumentActions(html);
                        }

                        return;
                    }

                    const target = document.querySelector(fragmentTargets[name]);
                    if (target) {
                        if (name === 'shipments') {
                            window.nexOmsDisposePrinterControls?.(target);
                        }

                        if (name === 'order-info') {
                            const paidEditor = target.querySelector('[data-paid-edit].is-editing');
                            if (paidEditor && paidEditor !== savedForm) {
                                deferredOrderInfoHtml = String(html || '');
                                return;
                            }
                            deferredOrderInfoHtml = null;
                            disposePaymentLinkTooltips(target);
                        }

                        target.innerHTML = html;

                        if (name === 'shipments') {
                            window.nexOmsInitializePrinterControls?.(target);
                        }

                        if (name === 'order-info') {
                            initializePaymentLinkTooltips(target);
                        }
                    }
                });

                if (Object.prototype.hasOwnProperty.call(state.fragments || {}, 'shipments')) {
                    const hasShipments = Number(state.shipments_count || 0) > 0;
                    shipmentsEmpty?.classList.toggle('d-none', hasShipments);
                    shipmentsTableWrap?.classList.toggle('d-none', !hasShipments);
                }
            };

            const applyOrderState = (state, forceSelection = false, syncFields = false, savedForm = null) => {
                if (!state?.status) {
                    return;
                }

                const hasUnsavedSelection = managementStatusInput
                    && currentServerStatus
                    && managementStatusInput.value !== currentServerStatus;

                currentServerStatus = state.status;

                if (managementStatusInput && managementStatusDot && managementStatusLabel && (!hasUnsavedSelection || forceSelection)) {
                    managementStatusInput.value = state.status;
                    managementStatusDot.style.background = state.status_color || '#64748b';
                    managementStatusLabel.textContent = state.status_label || state.status;

                    managementStatusOptions.forEach((option) => {
                        option.classList.toggle('active', option.dataset.status === state.status);
                    });
                }

                if (managementStatusDateValue && managementStatusTimeValue) {
                    managementStatusDateValue.textContent = state.status_changed_at?.date || '...';
                    managementStatusTimeValue.textContent = state.status_changed_at?.time || '';
                    managementStatusDate?.setAttribute('title', state.status_changed_at?.iso || '');
                }

                applyOrderFragments(state, savedForm);
                applyStarState(state.star_color || '');

                const headerCustomer = document.querySelector('[data-order-header-customer]');
                if (headerCustomer) {
                    headerCustomer.textContent = state.header_customer || '';
                    headerCustomer.classList.toggle('d-none', !state.header_customer);
                }

                if (syncFields) {
                    syncEditableFields(state.fields, savedForm);
                }

                currentLatestEventId = Number(state.latest_event_id || 0);
                currentShipmentsSignature = state.shipments_signature || '';
                currentBuyerCommentsSignature = state.buyer_comments_signature || '';
            };

            const fetchOrderState = async (fragments = [], syncFields = false, savedForm = null, automationPage = null) => {
                if (!orderStateUrl) {
                    return null;
                }

                const url = new URL(orderStateUrl, window.location.origin);
                url.searchParams.set('payment_id_warning', document.querySelector('[data-payment-id-warning]') ? '1' : '0');
                const historyPage = automationPage ?? document.querySelector('[data-automation-history-current-page]')?.dataset.automationHistoryCurrentPage;
                if (historyPage) {
                    url.searchParams.set('automation_page', String(historyPage));
                }
                if (fragments.length) {
                    url.searchParams.set('fragments', Array.from(new Set(fragments)).join(','));
                }
                if (currentLatestEventId !== null) {
                    url.searchParams.set('latest_event_id', String(currentLatestEventId));
                }
                if (currentShipmentsSignature !== null) {
                    url.searchParams.set('shipments_signature', currentShipmentsSignature);
                }
                if (currentBuyerCommentsSignature !== null) {
                    url.searchParams.set('buyer_comments_signature', currentBuyerCommentsSignature);
                }
                const response = await fetch(url.toString(), {
                    headers: {
                        Accept: 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    cache: 'no-store',
                });

                if (!response.ok) {
                    throw new Error('Nie udało się odświeżyć danych zamówienia.');
                }

                const state = await response.json();
                applyOrderState(state, false, syncFields, savedForm);

                return state;
            };

            const refreshOrderState = async (fragments = []) => {
                if (!orderStateUrl || orderStateRequestRunning || document.hidden) {
                    return;
                }

                orderStateRequestRunning = true;

                try {
                    await fetchOrderState(fragments);
                } catch (error) {
                    // A later automation event or returning to this tab will retry the refresh.
                } finally {
                    orderStateRequestRunning = false;
                }
            };

            const automationHistoryPanel = document.getElementById('orderAutomationHistoryCollapse');
            let automationHistoryRequestRunning = false;

            const loadAutomationHistory = async (page = null) => {
                if (!automationHistoryPanel || automationHistoryRequestRunning) {
                    return;
                }
                const body = automationHistoryPanel.querySelector('[data-order-automation-history-body]');
                const error = automationHistoryPanel.querySelector('[data-automation-history-error]');
                const controls = Array.from(body.querySelectorAll('[data-automation-history-page]'))
                    .map((button) => [button, button.disabled]);
                automationHistoryRequestRunning = true;
                body.setAttribute('aria-busy', 'true');
                controls.forEach(([button]) => { button.disabled = true; });
                error.hidden = true;
                try {
                    const state = await fetchOrderState(['automation-history'], false, null, page);
                    if (typeof state?.fragments?.['automation-history'] !== 'string') {
                        throw new Error('Invalid automation history response');
                    }
                } catch (exception) {
                    error.textContent = 'Nie udało się odświeżyć historii automatycznych akcji.';
                    error.hidden = false;
                } finally {
                    automationHistoryRequestRunning = false;
                    body.removeAttribute('aria-busy');
                    controls.forEach(([button, disabled]) => { button.disabled = disabled; });
                }
            };

            automationHistoryPanel?.addEventListener('show.bs.collapse', (event) => {
                if (event.target === automationHistoryPanel) {
                    return loadAutomationHistory();
                }
            });

            automationHistoryPanel?.addEventListener('click', (event) => {
                const button = event.target.closest('[data-automation-history-page]');
                if (button && !button.disabled) {
                    return loadAutomationHistory(Number(button.dataset.automationHistoryPage));
                }
            });

            const ksefAutomaticRefreshMaximumMs = 10 * 60 * 1000;
            let ksefAutomaticRefreshStartedAt = 0;
            let ksefAutomaticRefreshTimer = null;

            const scheduleKsefAutomaticRefresh = (reset = false) => {
                window.clearTimeout(ksefAutomaticRefreshTimer);

                const container = document.querySelector('[data-sales-document-actions]');
                const pending = container?.dataset.ksefAutomaticRefresh === '1';

                if (!pending) {
                    ksefAutomaticRefreshStartedAt = 0;
                    return;
                }

                if (reset || ksefAutomaticRefreshStartedAt === 0) {
                    ksefAutomaticRefreshStartedAt = Date.now();
                }

                const elapsed = Date.now() - ksefAutomaticRefreshStartedAt;
                if (elapsed >= ksefAutomaticRefreshMaximumMs) {
                    return;
                }

                const delay = elapsed < 60000 ? 2000 : 5000;
                ksefAutomaticRefreshTimer = window.setTimeout(async () => {
                    if (!document.hidden) {
                        await refreshOrderState(['sales-documents']);
                    }

                    scheduleKsefAutomaticRefresh();
                }, delay);
            };

            const clearAjaxErrors = (form) => {
                form.querySelectorAll('.is-invalid').forEach((field) => field.classList.remove('is-invalid'));
                form.querySelectorAll('[data-ajax-error]').forEach((message) => message.remove());
            };

            const ajaxFieldByErrorName = (form, name) => {
                const direct = form.querySelector(`[name="${name}"]`);
                if (direct) {
                    return direct;
                }

                const bracketed = String(name).split('.').reduce(
                    (fieldName, segment, index) => index === 0 ? segment : `${fieldName}[${segment}]`,
                    '',
                );

                return form.querySelector(`[name="${bracketed}"]`);
            };

            const showAjaxErrors = (form, errors) => {
                Object.entries(errors || {}).forEach(([name, messages]) => {
                    const field = ajaxFieldByErrorName(form, name);
                    if (!field) {
                        return;
                    }

                    field.classList.add('is-invalid');
                    const message = document.createElement('div');
                    message.className = 'invalid-feedback d-block';
                    message.dataset.ajaxError = '1';
                    message.textContent = Array.isArray(messages) ? messages[0] : String(messages);
                    const fieldGroup = field.closest('.input-group, .billing-gus-control');
                    if (fieldGroup) {
                        fieldGroup.after(message);
                    } else {
                        field.after(message);
                    }
                });
            };

            const ajaxLoadingTarget = (form) => {
                if (form.closest('.star-picker')) {
                    return form.closest('.dropdown') || form;
                }

                if (form.closest('.order-info-paid')) {
                    return form.closest('.order-info-paid');
                }

                if (form.closest('.product-actions')) {
                    return form.closest('.products-card') || form;
                }

                if (form.closest('.shipment-actions')) {
                    return form.closest('.shipments-card') || form;
                }

                if (form.matches('[data-cancel-shipment-form]')) {
                    return form.closest('.modal-content') || form;
                }

                return form;
            };

            const startAjaxLoading = (form, ariaLabel = 'Zapisywanie zmian') => {
                const target = ajaxLoadingTarget(form);
                const overlay = document.createElement('div');
                overlay.className = 'ajax-update-loading-overlay';
                overlay.setAttribute('role', 'status');
                overlay.setAttribute('aria-label', ariaLabel);
                overlay.innerHTML = '<span class="ajax-update-loading-dots" aria-hidden="true"><span class="ajax-update-loading-dot"></span><span class="ajax-update-loading-dot"></span><span class="ajax-update-loading-dot"></span></span>';

                target.querySelector('.ajax-update-loading-overlay')?.remove();
                target.classList.add('ajax-update-loading-host');
                target.appendChild(overlay);

                return () => {
                    overlay.remove();
                    target.classList.remove('ajax-update-loading-host');
                };
            };

            document.addEventListener('submit', async (event) => {
                const form = event.target.closest('form[data-order-ajax-form]');
                if (!form) {
                    return;
                }

                event.preventDefault();

                if (form.dataset.confirm && !window.confirm(form.dataset.confirm)) {
                    return;
                }

                const paidInput = form.querySelector('[data-paid-input]');
                if (paidInput) {
                    const value = Number((paidInput.value || '0').replace(',', '.'));
                    const total = Number(form.dataset.total || '0');
                    if (Number.isNaN(value) || value < 0 || value > total) {
                        alert('Kwota wpłaty musi być od 0.00 do łącznej wartości zamówienia.');
                        paidInput.focus();
                        return;
                    }
                    paidInput.value = value.toFixed(2);
                }

                clearAjaxErrors(form);
                const buttons = Array.from(form.querySelectorAll('button'));
                buttons.forEach((button) => { button.disabled = true; });
                const stopAjaxLoading = startAjaxLoading(form);

                try {
                    const response = await fetch(form.action, {
                        method: 'POST',
                        headers: {
                            Accept: 'application/json',
                            'X-Requested-With': 'XMLHttpRequest',
                        },
                        body: new FormData(form),
                    });
                    const payload = await response.json().catch(() => ({}));

                    if (!response.ok) {
                        showAjaxErrors(form, payload.errors);
                        const messages = Object.values(payload.errors || {}).flat();
                        throw new Error(messages[0] || payload.message || 'Nie udało się zapisać zmian.');
                    }

                    await fetchOrderState(payload.refresh || [], true, form);

                    if (form.matches('[data-refresh-courier-defaults]')) {
                        await reloadActiveCourierFormDefaults();
                    }

                    const inlineSection = form.closest('.inline-section');
                    if (inlineSection) {
                        closeSection(inlineSection);
                    }

                    if (form.matches('[data-paid-edit]')) {
                        closePaidEdit();
                    }

                    if (form.closest('.star-picker')) {
                        window.bootstrap?.Dropdown.getOrCreateInstance(document.querySelector('.order-star'))?.hide();
                    }

                    if (form.matches('[data-cancel-shipment-form]')) {
                        window.bootstrap?.Modal.getOrCreateInstance(cancelShipmentModal)?.hide();
                    }

                    if (form.closest('#productAddPanel')) {
                        form.reset();
                        window.bootstrap?.Collapse.getOrCreateInstance(document.getElementById('productAddPanel'))?.hide();
                    }

                    if (form.matches('[data-catalog-products-form]')) {
                        window.bootstrap?.Modal.getOrCreateInstance(catalogProductModal)?.hide();
                    }
                } catch (error) {
                    if (form.matches('[data-catalog-products-form]') && catalogFormError) {
                        catalogFormError.textContent = error.message || 'Nie udało się dodać produktów.';
                        catalogFormError.hidden = false;
                    }
                    alert(error.message || 'Nie udało się zapisać zmian.');
                } finally {
                    stopAjaxLoading();
                    buttons.forEach((button) => { button.disabled = false; });
                }
            });

            if (managementStatusForm) {
                managementStatusForm.addEventListener('submit', async (event) => {
                    event.preventDefault();
                    managementStatusSubmit?.setAttribute('disabled', 'disabled');
                    const stopAjaxLoading = startAjaxLoading(managementStatusForm);

                    try {
                        const response = await fetch(managementStatusForm.action, {
                            method: 'POST',
                            headers: {
                                Accept: 'application/json',
                                'X-Requested-With': 'XMLHttpRequest',
                            },
                            body: new FormData(managementStatusForm),
                        });
                        const data = await response.json();

                        if (!response.ok) {
                            throw new Error(data.message || 'Nie udało się zmienić statusu zamówienia.');
                        }

                        applyOrderState(data, true);
                        document.dispatchEvent(new Event('nexoms:automation-wake'));
                        await fetchOrderState(['history']);
                    } catch (error) {
                        alert(error.message || 'Nie udało się zmienić statusu zamówienia.');
                    } finally {
                        stopAjaxLoading();
                        managementStatusSubmit?.removeAttribute('disabled');
                    }
                });
            }

            document.addEventListener('submit', async (event) => {
                const form = event.target.closest('[data-sales-document-form]');

                if (!form) {
                    return;
                }

                event.preventDefault();
                const openDocumentAfterSubmit = form.hasAttribute('data-open-document-after-submit');
                const deletesDocument = form.hasAttribute('data-sales-document-delete-form');
                const deleteModal = deletesDocument ? form.closest('.modal') : null;
                const container = deletesDocument
                    ? document.querySelector('[data-sales-document-actions]')
                    : form.closest('[data-sales-document-actions]');
                const errorBox = deletesDocument
                    ? salesDocumentDeleteError
                    : container?.querySelector('[data-sales-document-error]');
                const actions = Array.from(new Set([
                    ...(container?.querySelectorAll('button') || []),
                    ...form.querySelectorAll('button'),
                ]));
                const documentWindow = openDocumentAfterSubmit ? window.open('about:blank', '_blank') : null;

                if (!container) {
                    documentWindow?.close();
                    return;
                }

                if (documentWindow) {
                    documentWindow.opener = null;
                }

                if (errorBox) {
                    errorBox.textContent = '';
                    errorBox.hidden = true;
                }

                actions.forEach((action) => { action.disabled = true; });
                salesDocumentMutationActive = true;

                try {
                    const response = await fetch(form.action, {
                        method: 'POST',
                        headers: {
                            Accept: 'application/json',
                            'X-Requested-With': 'XMLHttpRequest',
                        },
                        body: new FormData(form),
                    });
                    const isJson = response.headers.get('content-type')?.includes('application/json');
                    const payload = isJson ? await response.json() : {};

                    if (!response.ok) {
                        throw new Error(payload.message || 'Nie udało się wykonać operacji na dokumencie. Spróbuj ponownie.');
                    }

                    if (typeof payload.html !== 'string' || payload.html.trim() === '') {
                        throw new Error('Nie udało się odświeżyć akcji dokumentów.');
                    }

                    if (openDocumentAfterSubmit && typeof payload.document?.pdf_url !== 'string') {
                        throw new Error('Nie udało się otworzyć dokumentu PDF.');
                    }

                    const template = document.createElement('template');
                    template.innerHTML = payload.html.trim();
                    const replacement = template.content.firstElementChild;

                    if (!replacement) {
                        throw new Error('Nie udało się odświeżyć akcji dokumentów.');
                    }

                    if (deletesDocument) {
                        await waitForModalHidden(deleteModal);
                    }

                    replaceSalesDocumentActions(payload.html);

                    if (openDocumentAfterSubmit) {
                        if (documentWindow) {
                            documentWindow.location.replace(payload.document.pdf_url);
                        } else {
                            window.location.assign(payload.document.pdf_url);
                        }
                    }
                } catch (error) {
                    documentWindow?.close();

                    if (errorBox) {
                        errorBox.textContent = error.message || 'Nie udało się wykonać operacji na dokumencie. Spróbuj ponownie.';
                        errorBox.hidden = false;
                    }
                } finally {
                    salesDocumentMutationActive = false;
                    actions.forEach((action) => { action.disabled = false; });
                    flushDeferredSalesDocumentRefresh();
                }
            });

            const emailExchange = document.querySelector('[data-order-email-exchange]');

            if (emailExchange) {
                const emailForm = emailExchange.querySelector('[data-order-email-form]');
                const emailToggle = emailExchange.querySelector('[data-email-compose-toggle]');
                const emailToggleLabel = emailExchange.querySelector('[data-email-compose-toggle-label]');
                const emailTemplate = emailExchange.querySelector('[data-email-template]');
                const emailAccount = emailExchange.querySelector('[data-email-account]');
                const emailSubject = emailExchange.querySelector('[data-email-subject]');
                const emailFormat = emailExchange.querySelector('[data-email-format]');
                const emailContentRendered = emailExchange.querySelector('[data-email-content-rendered]');
                const emailBodyInput = emailExchange.querySelector('[data-email-body-input]');
                const emailEditor = emailExchange.querySelector('[data-email-editor]');
                const emailSourceEditor = emailExchange.querySelector('[data-email-source-editor]');
                const emailToolbar = emailExchange.querySelector('[data-email-toolbar]');
                const emailAttachments = emailExchange.querySelector('[data-email-attachments]');
                const emailMessages = emailExchange.querySelector('[data-email-messages]');
                const emailEmpty = emailExchange.querySelector('[data-email-empty]');
                const emailError = emailExchange.querySelector('[data-email-form-error]');
                const emailIdempotencyKey = emailExchange.querySelector('[data-email-idempotency-key]');
                const emailSubmit = emailExchange.querySelector('[data-email-submit]');
                const defaultEmailAccount = emailAccount?.value || '';
                let emailFormDirty = false;
                let emailSourceMode = false;

                const newEmailIdempotencyKey = () => {
                    if (typeof crypto.randomUUID === 'function') {
                        return crypto.randomUUID();
                    }

                    const bytes = crypto.getRandomValues(new Uint8Array(16));
                    bytes[6] = (bytes[6] & 0x0f) | 0x40;
                    bytes[8] = (bytes[8] & 0x3f) | 0x80;
                    const hex = Array.from(bytes, (byte) => byte.toString(16).padStart(2, '0')).join('');

                    return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`;
                };

                const ensureEmailIdempotencyKey = () => {
                    if (emailIdempotencyKey && !emailIdempotencyKey.value) {
                        emailIdempotencyKey.value = newEmailIdempotencyKey();
                    }
                };

                const setEmailError = (message = '') => {
                    if (!emailError) {
                        return;
                    }

                    emailError.textContent = message;
                    emailError.classList.toggle('d-none', !message);
                };

                const setEmailEditorFormat = (format) => {
                    const isPlainText = format === 'plain_text';
                    emailFormat.value = isPlainText ? 'plain_text' : 'html';
                    emailEditor.classList.toggle('is-plain-text', isPlainText);
                    emailToolbar?.querySelectorAll('button:not([data-email-source]), select, input').forEach((control) => {
                        control.disabled = isPlainText;
                    });
                    emailToolbar?.querySelector('[data-email-source]')?.toggleAttribute('disabled', isPlainText);

                    if (isPlainText && emailSourceMode) {
                        emailEditor.textContent = emailSourceEditor.value;
                        emailSourceEditor.classList.add('d-none');
                        emailEditor.classList.remove('d-none');
                        emailSourceMode = false;
                    }
                };

                const setEmailBody = (body, format) => {
                    setEmailEditorFormat(format);
                    emailSourceMode = false;
                    emailSourceEditor.classList.add('d-none');
                    emailEditor.classList.remove('d-none');

                    if (format === 'plain_text') {
                        emailEditor.textContent = body || '';
                    } else {
                        emailEditor.innerHTML = body || '';
                    }
                };

                const currentEmailBody = () => {
                    if (emailSourceMode) {
                        return emailSourceEditor.value;
                    }

                    return emailFormat.value === 'plain_text'
                        ? emailEditor.innerText
                        : emailEditor.innerHTML;
                };

                const renderEmailAttachments = (attachments = []) => {
                    emailAttachments.replaceChildren();

                    attachments.forEach((attachment) => {
                        const item = document.createElement('span');
                        item.innerHTML = '<i class="bi bi-paperclip me-1" aria-hidden="true"></i>';
                        item.append(document.createTextNode(attachment.name || 'Załącznik'));
                        emailAttachments.append(item);
                    });

                    emailAttachments.classList.toggle('d-none', attachments.length === 0);
                };

                const resetEmailForm = () => {
                    emailForm.reset();
                    emailAccount.value = defaultEmailAccount;
                    emailSubject.value = '';
                    setEmailBody('', 'html');
                    emailContentRendered.value = '0';
                    renderEmailAttachments([]);
                    emailFormDirty = false;
                    emailIdempotencyKey.value = '';
                    setEmailError();
                };

                emailToggle?.addEventListener('click', () => {
                    const smsForm = emailExchange.querySelector('[data-sms-form]');
                    const opening = emailForm.classList.contains('d-none') && (!smsForm || smsForm.classList.contains('d-none'));
                    const smsSelected = emailExchange.querySelector('[name="order_contact_channel"]:checked')?.value === 'sms';
                    emailForm.classList.toggle('d-none', !opening || smsSelected);
                    smsForm?.classList.toggle('d-none', !opening || !smsSelected);
                    emailExchange.querySelector('[data-contact-channels]')?.classList.toggle('d-none', !opening);
                    emailToggleLabel.textContent = opening ? 'Zamknij wiadomość' : 'Napisz wiadomość';

                    if (opening) {
                        ensureEmailIdempotencyKey();
                        if (!smsSelected) emailTemplate.focus();
                    }
                });

                emailForm?.addEventListener('input', () => {
                    emailFormDirty = true;
                });

                emailTemplate?.addEventListener('change', async () => {
                    const selected = emailTemplate.selectedOptions[0];
                    const previewUrl = selected?.dataset.previewUrl;

                    if (!previewUrl) {
                        if (emailFormDirty && !window.confirm('Wyczyścić temat i treść wiadomości?')) {
                            emailTemplate.value = emailTemplate.dataset.previousValue || '';
                            return;
                        }

                        resetEmailForm();
                        emailTemplate.dataset.previousValue = '';
                        return;
                    }


                    emailTemplate.disabled = true;
                    setEmailError();

                    try {
                        const response = await fetch(previewUrl, {
                            cache: 'no-store',
                            headers: {
                                Accept: 'application/json',
                                'X-Requested-With': 'XMLHttpRequest',
                            },
                        });
                        const payload = await response.json().catch(() => ({}));

                        if (!response.ok) {
                            throw new Error(payload.message || 'Nie udało się wczytać szablonu.');
                        }

                        emailSubject.value = payload.subject || '';
                        setEmailBody(payload.body || '', payload.format || 'html');
                        emailContentRendered.value = payload.content_rendered ? '1' : '0';
                        renderEmailAttachments(payload.attachments || []);

                        if (payload.email_account_id && emailAccount.querySelector(`option[value="${payload.email_account_id}"]`)) {
                            emailAccount.value = String(payload.email_account_id);
                        }

                        emailTemplate.dataset.previousValue = emailTemplate.value;
                        emailFormDirty = false;
                    } catch (error) {
                        setEmailError(error.message || 'Nie udało się wczytać szablonu.');
                        emailTemplate.value = emailTemplate.dataset.previousValue || '';
                    } finally {
                        emailTemplate.disabled = false;
                    }
                });

                const runEmailEditorCommand = (command, value = null) => {
                    if (emailFormat.value === 'plain_text' || emailSourceMode) {
                        return;
                    }

                    emailEditor.focus();
                    document.execCommand(command, false, value);
                    emailFormDirty = true;
                };

                emailToolbar?.addEventListener('click', (event) => {
                    const commandButton = event.target.closest('[data-email-command]');
                    if (commandButton) {
                        runEmailEditorCommand(commandButton.dataset.emailCommand);
                        return;
                    }

                    if (event.target.closest('[data-email-source]')) {
                        if (emailFormat.value === 'plain_text') {
                            return;
                        }

                        emailSourceMode = !emailSourceMode;
                        const sourceButton = emailToolbar.querySelector('[data-email-source]');
                        sourceButton.classList.toggle('is-active', emailSourceMode);

                        if (emailSourceMode) {
                            emailSourceEditor.value = emailEditor.innerHTML;
                        } else {
                            emailEditor.innerHTML = emailSourceEditor.value;
                        }

                        emailSourceEditor.classList.toggle('d-none', !emailSourceMode);
                        emailEditor.classList.toggle('d-none', emailSourceMode);
                        (emailSourceMode ? emailSourceEditor : emailEditor).focus();
                        return;
                    }

                    if (event.target.closest('[data-email-link]')) {
                        const url = window.prompt('Podaj adres odnośnika (http, https lub mailto):', 'https://');
                        if (url && /^(https?:|mailto:)/i.test(url)) {
                            runEmailEditorCommand('createLink', url);
                        }
                        return;
                    }

                    if (event.target.closest('[data-email-table]')) {
                        const rowsInput = window.prompt('Liczba wierszy:', '2');
                        const columnsInput = window.prompt('Liczba kolumn:', '2');
                        if (rowsInput === null || columnsInput === null) {
                            return;
                        }
                        const rows = Math.min(10, Math.max(1, Number(rowsInput) || 1));
                        const columns = Math.min(10, Math.max(1, Number(columnsInput) || 1));
                        const cells = '<td>&nbsp;</td>'.repeat(columns);
                        runEmailEditorCommand('insertHTML', `<table border="1"><tbody>${`<tr>${cells}</tr>`.repeat(rows)}</tbody></table><p><br></p>`);
                        return;
                    }

                    if (event.target.closest('[data-email-image]')) {
                        const url = window.prompt('Podaj adres obrazu HTTPS:', 'https://');
                        if (url && /^https:\/\//i.test(url)) {
                            runEmailEditorCommand('insertImage', url);
                        }
                    }
                });

                emailToolbar?.querySelector('[data-email-font]')?.addEventListener('change', (event) => {
                    runEmailEditorCommand('fontName', event.target.value);
                });
                emailToolbar?.querySelector('[data-email-size]')?.addEventListener('change', (event) => {
                    runEmailEditorCommand('fontSize', event.target.value);
                });
                emailToolbar?.querySelector('[data-email-color]')?.addEventListener('input', (event) => {
                    runEmailEditorCommand('foreColor', event.target.value);
                });

                const replaceEmailMessage = (payload, prepend = false) => {
                    if (typeof payload.html !== 'string' || payload.html.trim() === '') {
                        return;
                    }

                    const template = document.createElement('template');
                    template.innerHTML = payload.html.trim();
                    const replacement = template.content.firstElementChild;
                    if (!replacement) {
                        return;
                    }

                    const current = emailMessages.querySelector(`[data-email-message-id="${payload.id}"]`);
                    if (current) {
                        current.replaceWith(replacement);
                    } else if (prepend) {
                        emailMessages.prepend(replacement);
                    }

                    emailEmpty?.classList.add('d-none');
                };

                const pollEmailMessage = async (statusUrl, messageId, attempt = 0) => {
                    if (!statusUrl || attempt >= 30) {
                        return;
                    }

                    await new Promise((resolve) => window.setTimeout(resolve, 2000));

                    try {
                        const response = await fetch(statusUrl, {
                            cache: 'no-store',
                            headers: {
                                Accept: 'application/json',
                                'X-Requested-With': 'XMLHttpRequest',
                            },
                        });
                        const payload = await response.json().catch(() => ({}));

                        if (!response.ok) {
                            throw new Error();
                        }

                        replaceEmailMessage(payload);
                        if (!payload.polling_finished) {
                            return pollEmailMessage(payload.status_url || statusUrl, messageId, attempt + 1);
                        }
                    } catch (error) {
                        return pollEmailMessage(statusUrl, messageId, attempt + 1);
                    }
                };

                emailForm?.addEventListener('submit', async (event) => {
                    event.preventDefault();
                    setEmailError();
                    ensureEmailIdempotencyKey();
                    emailBodyInput.value = currentEmailBody();
                    emailSubmit.disabled = true;
                    const previousLabel = emailSubmit.textContent;
                    emailSubmit.textContent = 'Wysyłanie...';

                    try {
                        const response = await fetch(emailForm.action, {
                            method: 'POST',
                            headers: {
                                Accept: 'application/json',
                                'X-Requested-With': 'XMLHttpRequest',
                            },
                            body: new FormData(emailForm),
                        });
                        const payload = await response.json().catch(() => ({}));

                        if (!response.ok) {
                            const messages = Object.values(payload.errors || {}).flat();
                            throw new Error(messages[0] || payload.message || 'Nie udało się wysłać wiadomości.');
                        }

                        replaceEmailMessage(payload, true);
                        resetEmailForm();
                        emailForm.classList.add('d-none');
                        emailExchange.querySelector('[data-contact-channels]')?.classList.add('d-none');
                        emailToggleLabel.textContent = 'Napisz wiadomość';

                        if (!payload.polling_finished) {
                            pollEmailMessage(payload.status_url, payload.id);
                        }
                    } catch (error) {
                        setEmailError(error.message || 'Nie udało się wysłać wiadomości.');
                    } finally {
                        emailSubmit.disabled = false;
                        emailSubmit.textContent = previousLabel;
                    }
                });

                emailMessages?.querySelectorAll('[data-email-terminal="0"]').forEach((message) => {
                    pollEmailMessage(message.dataset.emailStatusUrl, message.dataset.emailMessageId);
                });

                ensureEmailIdempotencyKey();
            }
            if (orderStateUrl) {
                fetchOrderState(['history', 'shipments', 'payments']).catch(() => {});
                scheduleKsefAutomaticRefresh(true);

                payNowRefreshTimer = window.setInterval(() => {
                    refreshOrderState(automationHistoryPanel?.classList.contains('show')
                        ? ['payments', 'automation-history'] : ['payments']);
                }, 15000);

                document.addEventListener('nexoms:automation-finished', (event) => {
                    if (Number(event.detail?.orderId || 0) === currentOrderId) {
                        refreshOrderState(['sales-documents', 'history', 'automation-history']);
                    }
                });

                document.addEventListener('visibilitychange', () => {
                    if (!document.hidden) {
                        refreshOrderState(automationHistoryPanel?.classList.contains('show')
                            ? ['sales-documents', 'payments', 'automation-history'] : ['sales-documents', 'payments']);
                        scheduleKsefAutomaticRefresh();
                    }
                });

                window.addEventListener('pagehide', () => {
                    window.clearTimeout(ksefAutomaticRefreshTimer);
                    window.clearInterval(payNowRefreshTimer);
                }, { once: true });
            }

            const initialCourierProvider = courierFormHost?.dataset.initialCourierProvider;
            const initialCourierButton = courierFormButtons.find(
                (button) => button.dataset.courierProvider === initialCourierProvider,
            );

            if (initialCourierButton) {
                loadCourierForm(initialCourierButton);
            }

        });
    </script>
@endsection
