@extends('layouts.app')

@section('title', 'Zamowienie ' . $order->id . ' - NEX-OMS')

@php
    $dateValue = fn ($value) => $value ? $value->format('Y-m-d\TH:i') : null;
    $displayDate = fn ($value) => $value ? $value->format('Y-m-d H:i') : '-';
    $shippingAddress = $order->shippingAddressData();
    $billingAddress = $order->billingAddressData();
    $headerCustomerName = $shippingAddress?->name ?: $order->customer_login;
    $formatAddressLine = fn ($address) => $address ? \App\Support\AddressLineFormatter::formatAddressLine($address->street, $address->building_number, $address->apartment_number) : null;
    $formatPostalCity = fn ($address) => $address ? \App\Support\AddressLineFormatter::formatPostalCity($address->postal_code, $address->city) : null;
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
            background: #f4f6f8;
            margin: -1.5rem;
            min-height: calc(100vh - 0px);
            padding: 24px;
        }

        .order-topbar {
            align-items: center;
            border-bottom: 1px solid #dfe3e8;
            display: flex;
            gap: 16px;
            justify-content: space-between;
            margin-bottom: 16px;
            min-height: 52px;
            padding: 0 0 8px;
        }

        .order-heading {
            align-items: center;
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            min-width: 0;
        }

        .order-star {
            color: #9ca3af;
            font-size: 18px;
            line-height: 1;
        }

        .star-button {
            background: transparent;
            border: 0;
            font-size: 19px;
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

        .order-title {
            color: #111827;
            font-size: 16px;
            font-weight: 600;
            line-height: 1.2;
            margin: 0;
        }

        .order-customer {
            color: #6b7280;
            font-size: 13px;
            line-height: 1.2;
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
            gap: 8px;
            grid-template-columns: 25% 150px max-content;
            justify-content: stretch;
        }

        .management-status-fields,
        .management-sales-document-actions {
            display: contents;
        }

        .management-status-label,
        .management-invoice-label,
        .management-meta-label {
            color: #374151;
            font-size: 13px;
            white-space: nowrap;
        }

        .management-invoice-label,
        .management-meta-label {
            grid-column: 1;
            margin-top: 8px;
        }

        .management-meta-label {
            margin-top: 10px;
        }

        .management-meta-value {
            color: #111827;
            font-size: 13px;
            grid-column: 2 / 4;
            margin-top: 10px;
            min-height: 24px;
        }

        .management-meta-label.is-status-date,
        .management-meta-value.is-status-date {
            margin-top: 0;
        }

        .management-meta-time {
            margin-left: 14px;
        }

        .management-invoice-button {
            font-size: 12px;
            font-weight: 700;
            grid-column: 2;
            margin-top: 8px;
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
            margin-top: 8px;
        }

        .management-correction-label,
        .management-issued-correction-actions {
            margin-top: -4px;
        }

        .management-issued-invoice-group {
            box-shadow: none;
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
            margin-top: 8px;
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
            box-shadow: 0 1px 3px rgba(15, 23, 42, .06);
            overflow: visible;
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
            margin-top: 14px;
        }

        .products-table {
            font-size: 13px;
            table-layout: auto;
            width: 100%;
        }

        .products-table thead th {
            color: #374151;
            white-space: nowrap;
        }

        .products-table tbody td {
            height: 60px;
            vertical-align: middle;
        }

        .products-table .product-main-column {
            width: 100%;
        }

        .products-table .product-metric {
            padding-left: 10px;
            padding-right: 10px;
            text-align: center;
            white-space: nowrap;
            width: 1%;
        }

        .products-table .product-actions-column {
            padding-left: 8px;
            white-space: nowrap;
            width: 1%;
        }

        .product-date-stack {
            line-height: 1.15;
        }

        .product-thumb {
            align-items: center;
            background: #f8fafc;
            border: 1px solid #e5e7eb;
            border-radius: 10px;
            color: #64748b;
            display: inline-flex;
            font-size: 16px;
            height: 38px;
            justify-content: center;
            width: 38px;
        }

        .product-name {
            color: #111827;
            font-size: 14px;
            font-weight: 600;
        }

        .product-actions {
            position: relative;
        }

        .product-actions .dropdown {
            display: inline-block;
        }

        .product-actions .btn {
            align-items: center;
            border-radius: 999px;
            display: inline-flex;
            height: 30px;
            justify-content: center;
            padding: 0;
            width: 30px;
        }

        .product-actions .dropdown-menu {
            background: #ffffff;
            border: 1px solid #e5e7eb;
            border-radius: 10px;
            box-shadow: 0 10px 24px rgba(15, 23, 42, .12);
            position: absolute;
            z-index: 1055;
        }

        .product-add-button {
            background: #ffffff;
            border: 1px solid #d1d5db;
            border-radius: 999px;
            color: #374151;
            font-size: 13px;
            padding: 7px 13px;
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
            min-height: 26px;
            padding: 1px 6px;
            position: relative;
        }

        .inline-field-row .nex-label,
        .order-info-row .nex-label {
            align-self: center;
            margin-bottom: 0;
            min-width: 0;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .inline-field-row:hover,
        .order-info-row:hover {
            background: #f7fbff;
            border-radius: 4px;
            cursor: pointer;
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
            color: #0d6efd;
            flex: 0 0 auto;
            font-size: 11px;
            line-height: 1;
            margin-left: 8px;
            opacity: 0;
            transition: opacity .15s ease;
        }

        .inline-field-row:hover .inline-pencil,
        .order-info-row:hover .inline-pencil {
            opacity: 1;
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
            gap: 3px;
            margin: 0;
        }

        .inline-section-edit .row.g-2 > [class*="col-"] {
            align-items: center;
            display: grid;
            gap: 10px;
            grid-template-columns: 126px minmax(0, 1fr);
            max-width: none;
            min-height: 29px;
            padding: 1px 6px;
            width: 100%;
        }

        .inline-section-edit .form-label {
            color: #6b7280;
            font-size: 11px;
            letter-spacing: .03em;
            margin: 0;
            min-width: 0;
            overflow: hidden;
            text-overflow: ellipsis;
            text-transform: uppercase;
            white-space: nowrap;
        }

        .inline-section-edit .form-control,
        .inline-section-edit .form-select {
            background-color: #ffffff;
            border: 1px solid #cbd5e1;
            border-radius: 4px;
            color: #111827;
            font-size: 13px;
            min-height: 26px;
            padding: 3px 7px;
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
            border-top: 1px solid #eef0f3;
            display: flex;
            gap: 8px;
            justify-content: flex-end;
            margin-top: 8px;
            padding-top: 9px;
        }

        .inline-actions .btn {
            font-size: 12px;
            line-height: 1.2;
            padding: 4px 9px;
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

        .shipment-label-group .btn {
            border-color: #d3dae4;
            border-radius: 4px;
            color: #334155;
            font-size: 9px;
            font-weight: 700;
            min-height: 28px;
            text-transform: uppercase;
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
            .order-topbar {
                align-items: stretch;
                flex-direction: column;
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

    <div class="order-page" data-order-id="{{ $order->id }}" data-order-state-url="{{ route('orders.state', $order) }}">
    <div class="order-topbar">
        <div class="order-heading">
            <div class="dropdown">
                <button class="star-button order-star {{ $starClass }}" type="button" data-bs-toggle="dropdown" data-bs-boundary="viewport" aria-expanded="false" aria-label="Wybierz kolor oznaczenia zamowienia">
                    {!! $currentStar ? '&#9733;' : '&#9734;' !!}
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
            <h1 class="order-title">Zam&oacute;wienie {{ $order->id }}</h1>
            <span class="order-customer {{ $headerCustomerName ? '' : 'd-none' }}" data-order-header-customer>{{ $headerCustomerName }}</span>
        </div>
        <a class="btn btn-outline-primary btn-sm" href="{{ route('orders.index') }}" data-page-navigation-loading>Powr&oacute;t do listy zam&oacute;wie&#324;</a>
    </div>

    <div class="nex-card products-card">
        <div class="nex-card-header">
            <h2 class="nex-card-title">Produkty</h2>
        </div>
        <div class="nex-card-body">
            <div class="products-table-wrapper">
                <table class="table nex-table products-table align-middle mb-0">
                    <thead>
                        <tr>
                            <th style="width: 56px;"></th>
                            <th class="product-main-column">Nazwa produktu</th>
                            <th class="product-metric">Ilo&#347;&#263;</th>
                            <th class="product-metric">Cena</th>
                            <th class="product-metric">VAT</th>
                            <th class="product-metric">Waga</th>
                            <th class="product-metric">Data</th>
                            <th class="text-end product-actions-column">Akcje</th>
                        </tr>
                    </thead>
                    <tbody data-products-table-body>
                        @include('orders.partials.product-rows', ['order' => $order])
                    </tbody>
                </table>
            </div>

            <div class="products-toolbar">
                <button class="btn product-add-button" type="button" data-bs-toggle="collapse" data-bs-target="#productAddPanel">
                    Dodaj produkty do zam&oacute;wienia... <span aria-hidden="true">&#9662;</span>
                </button>

                <div class="dropdown">
                    <button class="btn btn-sm btn-light border" type="button" data-bs-toggle="dropdown" aria-expanded="false">Operacje na produktach</button>
                    <ul class="dropdown-menu dropdown-menu-end">
                        <li><span class="dropdown-item text-secondary">Przelicz warto&#347;ci</span></li>
                        <li><span class="dropdown-item text-secondary">Od&#347;wie&#380; sekcj&#281;</span></li>
                    </ul>
                </div>
            </div>

            <div class="collapse product-add-panel" id="productAddPanel">
                <h3 class="h6 mb-3">Dodawanie produktu do zam&oacute;wienia</h3>
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
        </div>
    </div>
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
                            <div class="col-12"><label class="form-label">&#377;r&oacute;d&#322;o</label><select name="source" class="form-select form-select-sm">@foreach ($sourceOptions as $source => $label)<option value="{{ $source }}" @selected(old('source', $order->source) === $source)>{{ $label }}</option>@endforeach</select></div>
                            <div class="col-12"><label class="form-label">Spos&oacute;b wysy&#322;ki</label><input type="text" name="shipping_method" class="form-control form-control-sm" value="{{ old('shipping_method', $order->shipping_method) }}"></div>
                            <div class="col-12"><label class="form-label">Pobranie</label><select name="cash_on_delivery" class="form-select form-select-sm"><option value="0" @selected(! old('cash_on_delivery', $order->cash_on_delivery))>Nie</option><option value="1" @selected((bool) old('cash_on_delivery', $order->cash_on_delivery))>Tak</option></select></div>
                            <div class="col-12"><label class="form-label">Koszt wysy&#322;ki</label><input type="number" step="0.01" min="0" name="delivery_cost_gross" class="form-control form-control-sm" value="{{ old('delivery_cost_gross', $order->delivery_cost_gross) }}"></div>
                            <div class="col-12"><label class="form-label">Spos&oacute;b p&#322;atno&#347;ci</label><input type="text" name="payment_method" class="form-control form-control-sm" value="{{ old('payment_method', $order->payment_method) }}"></div>
                            <div class="col-12"><label class="form-label">Uwagi</label><textarea name="notes" class="form-control form-control-sm" rows="2">{{ old('notes', $order->notes) }}</textarea></div>
                        </div>
                        <div class="inline-actions"><button type="button" class="btn btn-sm btn-light border inline-cancel">Anuluj</button><button type="submit" class="btn btn-sm btn-primary">Zapisz</button></div>
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
                        <div class="management-status-form">
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
                            @include('orders.partials.sales-document-actions', [
                                'order' => $order,
                                'salesDocumentActions' => $salesDocumentActions,
                            ])
                            @if ($order->source === 'prestashop')
                                <div class="management-meta-label">Numer w sklepie:</div>
                                <div class="management-meta-value">{{ $order->external_id ?: '...' }}</div>
                            @elseif ($order->source === 'allegro')
                                <div class="management-meta-label">Numer transakcji:</div>
                                <div class="management-meta-value">{{ $order->external_id ?: '...' }}</div>
                            @endif
                            <div class="management-meta-label">Data z&#322;o&#380;enia:</div>
                            <div class="management-meta-value">
                                @if ($createdAtParts = $managementDateParts($order->created_at))
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
                        <button class="btn btn-sm btn-light border icon-action-button inline-section-edit-button" type="button" data-edit-section="shipping" title="Edytuj adres dostawy" aria-label="Edytuj adres dostawy">&#9998;</button>
                    </div>
                </div>
                <div class="nex-card-body">
                    <div class="inline-section-view inline-edit-trigger" data-edit-section="shipping">@include('orders.partials.address', ['address' => $shippingAddress, 'showTaxId' => false, 'showCountry' => true, 'countryName' => $countryCatalog->name($shippingAddress?->country_code), 'showProvince' => false, 'showPhone' => false, 'showEmail' => false])<span class="inline-pencil">&#9998;</span></div>
                    <form method="POST" action="{{ route('orders.sections.shipping-address', $order) }}" class="inline-section-edit" data-order-ajax-form data-refresh-courier-defaults>
                        @csrf
                        @method('PATCH')
                        <div class="row g-2">
                            <div class="col-12"><label class="form-label">Imi&#281; i Nazwisko</label><input type="text" name="shipping_name" class="form-control form-control-sm" value="{{ old('shipping_name', $shippingAddress?->name) }}"></div>
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
                        <div class="inline-actions"><button type="button" class="btn btn-sm btn-light border inline-cancel">Anuluj</button><button type="submit" class="btn btn-sm btn-primary">Zapisz</button></div>
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
                        <button class="btn btn-sm btn-light border icon-action-button inline-section-edit-button" type="button" data-edit-section="billing" title="Edytuj dane do faktury" aria-label="Edytuj dane do faktury">&#9998;</button>
                    </div>
                </div>
                <div class="nex-card-body">
                    <div class="inline-section-view inline-edit-trigger" data-edit-section="billing">
                        <div class="nex-address-grid">
                            <div class="inline-field-row"><div class="nex-label">Imi&#281; i Nazwisko</div><div class="nex-value {{ $billingAddress?->name ? '' : 'nex-empty' }}">{{ $billingAddress?->name ?: '...' }}<span class="inline-pencil">&#9998;</span></div></div>
                            <div class="inline-field-row"><div class="nex-label">Firma</div><div class="nex-value {{ $billingAddress?->company_name ? '' : 'nex-empty' }}">{{ $billingAddress?->company_name ?: '...' }}<span class="inline-pencil">&#9998;</span></div></div>
                            <div class="inline-field-row"><div class="nex-label">Adres</div><div class="nex-value {{ $formatAddressLine($billingAddress) ? '' : 'nex-empty' }}">{{ $formatAddressLine($billingAddress) ?: '...' }}<span class="inline-pencil">&#9998;</span></div></div>
                            <div class="inline-field-row"><div class="nex-label">Kod i miasto</div><div class="nex-value {{ $formatPostalCity($billingAddress) ? '' : 'nex-empty' }}">{{ $formatPostalCity($billingAddress) ?: '...' }}<span class="inline-pencil">&#9998;</span></div></div>
                            <div class="inline-field-row"><div class="nex-label">Kraj</div><div class="nex-value {{ $countryCatalog->name($billingAddress?->country_code) ? '' : 'nex-empty' }}">{{ $countryCatalog->name($billingAddress?->country_code) ?: '...' }}<span class="inline-pencil">&#9998;</span></div></div>
                            <div class="inline-field-row"><div class="nex-label">NIP</div><div class="nex-value {{ $billingAddress?->tax_id ? '' : 'nex-empty' }}">{{ $billingAddress?->tax_id ?: '...' }}<span class="inline-pencil">&#9998;</span></div></div>
                        </div>
                    </div>
                    <form method="POST" action="{{ route('orders.sections.billing-address', $order) }}" class="inline-section-edit" data-billing-form data-gus-lookup-url="{{ route('gus.company-by-nip') }}" data-order-ajax-form>
                        @csrf
                        @method('PATCH')
                        <input type="hidden" name="billing_province" value="{{ old('billing_province', $billingAddress?->province) }}">
                        <div class="row g-2">
                            <div class="col-12"><label class="form-label">Imi&#281; i Nazwisko</label><input type="text" name="billing_name" class="form-control form-control-sm" value="{{ old('billing_name', $billingAddress?->name) }}"></div>
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
                        <div class="inline-actions"><button type="button" class="btn btn-sm btn-light border inline-cancel">Anuluj</button><button type="submit" class="btn btn-sm btn-primary">Zapisz</button></div>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-12 col-xl-4">
            <div class="nex-card h-100 inline-section" data-inline-section="pickup">
                <div class="nex-card-header"><h2 class="nex-card-title">Odbi&oacute;r w punkcie</h2><button class="btn btn-sm btn-light border inline-section-edit-button" type="button" data-edit-section="pickup">Edytuj</button></div>
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
                        <div class="inline-actions"><button type="button" class="btn btn-sm btn-light border inline-cancel">Anuluj</button><button type="submit" class="btn btn-sm btn-primary">Zapisz</button></div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    @php
        $shipmentFormHasErrors = $errors->hasAny(['shipment', 'parcel_template', 'target_point_id', 'sending_method', 'content_description', 'cod_amount', 'insurance_amount', 'additional_services', 'service', 'parcels']);
        $initialShipmentProvider = $shipmentFormHasErrors ? $selectedShipmentProvider : null;
    @endphp
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
                    <h2 class="invoice-ksef-confirm-question" id="orderKsefSendConfirmationQuestion">Czy przekaza&#263; faktur&#281; do KSeF 2.0?</h2>
                    <div class="invoice-ksef-confirm-actions">
                        <button class="btn invoice-ksef-confirm-accept" type="button" data-order-ksef-send-confirm>Tak</button>
                        <button class="btn btn-outline-secondary" type="button" data-bs-dismiss="modal">Anuluj</button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="nex-card">
        <div class="nex-card-header">
            <h2 class="nex-card-title">Historia</h2>
        </div>
        <div class="nex-card-body" data-order-history-body>
            @include('orders.partials.history', ['order' => $order])
        </div>
    </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', () => {
            let activeSection = null;

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
            window.addEventListener('load', () => syncSalesDocumentNumberWidths());
            document.fonts?.ready.then(() => syncSalesDocumentNumberWidths());

            const ksefSendModalElement = document.querySelector('[data-order-ksef-send-modal]');
            const ksefSendConfirmButton = document.querySelector('[data-order-ksef-send-confirm]');
            let pendingKsefSendForm = null;

            document.addEventListener('submit', (event) => {
                const form = event.target.closest('[data-order-ksef-send-form]');

                if (!form) {
                    return;
                }

                event.preventDefault();
                pendingKsefSendForm = form;
            });

            ksefSendConfirmButton?.addEventListener('click', () => {
                if (!pendingKsefSendForm) {
                    return;
                }

                const form = pendingKsefSendForm;
                pendingKsefSendForm = null;
                ksefSendConfirmButton.disabled = true;
                HTMLFormElement.prototype.submit.call(form);
            });

            ksefSendModalElement?.addEventListener('hidden.bs.modal', () => {
                pendingKsefSendForm = null;
                ksefSendConfirmButton.disabled = false;
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

            const ksefInvoiceDownloadError = async (response) => {
                try {
                    const data = await response.json();

                    if (typeof data?.message === 'string' && data.message.trim() !== '') {
                        return data.message;
                    }
                } catch (error) {
                    // The controlled endpoint may fail before producing a JSON response.
                }

                return 'Nie udało się pobrać Faktury z KSeF.';
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

                try {
                    const [generator, response] = await Promise.all([
                        loadKsefPdfGenerator(trigger.dataset.ksefPdfGeneratorSrc),
                        fetch(trigger.dataset.ksefInvoiceSourceUrl, {
                            headers: {
                                'Accept': 'application/xml',
                            },
                        }),
                    ]);

                    if (!response.ok) {
                        throw new Error(await ksefInvoiceDownloadError(response));
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
                    const download = document.createElement('a');
                    download.href = url;
                    download.download = trigger.dataset.ksefPdfFilename || 'Faktura_KSeF.pdf';
                    document.body.append(download);
                    download.click();
                    download.remove();
                    window.setTimeout(() => URL.revokeObjectURL(url), 1000);
                } catch (error) {
                    alert(error instanceof Error && error.message
                        ? error.message
                        : 'Nie udało się pobrać Faktury z KSeF.');
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
                activeSection = null;
            };

            const openSection = (sectionName) => {
                const section = document.querySelector(`[data-inline-section="${sectionName}"]`);
                if (!section) {
                    return;
                }
                if (activeSection && activeSection !== section) {
                    alert('Najpierw zapisz albo anuluj aktualnie edytowana sekcje.');
                    return;
                }
                section.classList.add('is-editing');
                activeSection = section;
                const firstInput = section.querySelector('input, select, textarea');
                if (firstInput) {
                    firstInput.focus();
                }
            };

            document.addEventListener('click', (event) => {
                const editTrigger = event.target.closest('[data-edit-section]');
                if (editTrigger) {
                    event.preventDefault();
                    openSection(editTrigger.dataset.editSection);
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
                    const billingSection = document.querySelector('[data-inline-section="billing"]');

                    if (activeSection && activeSection !== billingSection) {
                        alert('Najpierw zapisz albo anuluj aktualnie edytowana sekcje.');
                        return;
                    }

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
                    if (activeSection && activeSection !== shippingSection) {
                        alert('Najpierw zapisz albo anuluj aktualnie edytowana sekcje.');
                        return;
                    }

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
                const paidView = document.querySelector('[data-paid-view]');
                const paidEdit = document.querySelector('[data-paid-edit]');
                const paidInput = document.querySelector('[data-paid-input]');

                if (!paidEdit || !paidView || !paidInput) {
                    return;
                }
                if (activeSection) {
                    alert('Najpierw zapisz albo anuluj aktualnie edytowana sekcje.');
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
                    currentRow.replaceWith(newRow);
                } else {
                    shipmentsTableBody.prepend(newRow);
                }

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
            };

            const syncEditableFields = (fields) => {
                Object.entries(fields || {}).forEach(([name, value]) => {
                    document.querySelectorAll(`[data-order-ajax-form] [name="${name}"]`).forEach((field) => {
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
                starButton.innerHTML = color ? '&#9733;' : '&#9734;';

                document.querySelectorAll('.star-picker form').forEach((form) => {
                    const button = form.querySelector('.star-color-button');
                    button?.classList.toggle('is-active', (form.querySelector('[name="star_color"]')?.value || '') === (color || ''));
                });
            };

            const applyOrderFragments = (state) => {
                Object.entries(state.fragments || {}).forEach(([name, html]) => {
                    if (name === 'sales-documents') {
                        const current = document.querySelector('[data-sales-document-actions]');
                        const template = document.createElement('template');
                        template.innerHTML = String(html || '').trim();
                        const replacement = template.content.firstElementChild;

                        if (current && replacement) {
                            current.replaceWith(replacement);
                            syncSalesDocumentNumberWidths(replacement);
                            initializeOrderKsefTooltips(replacement);
                            scheduleKsefAutomaticRefresh();
                        }

                        return;
                    }

                    const target = document.querySelector(fragmentTargets[name]);
                    if (target) {
                        target.innerHTML = html;
                    }
                });

                if (Object.prototype.hasOwnProperty.call(state.fragments || {}, 'shipments')) {
                    const hasShipments = Number(state.shipments_count || 0) > 0;
                    shipmentsEmpty?.classList.toggle('d-none', hasShipments);
                    shipmentsTableWrap?.classList.toggle('d-none', !hasShipments);
                }
            };

            const applyOrderState = (state, forceSelection = false, syncFields = false) => {
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

                applyOrderFragments(state);
                applyStarState(state.star_color || '');

                const headerCustomer = document.querySelector('[data-order-header-customer]');
                if (headerCustomer) {
                    headerCustomer.textContent = state.header_customer || '';
                    headerCustomer.classList.toggle('d-none', !state.header_customer);
                }

                if (syncFields) {
                    syncEditableFields(state.fields);
                }

                currentLatestEventId = Number(state.latest_event_id || 0);
                currentShipmentsSignature = state.shipments_signature || '';
            };

            const fetchOrderState = async (fragments = [], syncFields = false) => {
                if (!orderStateUrl) {
                    return null;
                }

                const url = new URL(orderStateUrl, window.location.origin);
                if (fragments.length) {
                    url.searchParams.set('fragments', Array.from(new Set(fragments)).join(','));
                }
                if (currentLatestEventId !== null) {
                    url.searchParams.set('latest_event_id', String(currentLatestEventId));
                }
                if (currentShipmentsSignature !== null) {
                    url.searchParams.set('shipments_signature', currentShipmentsSignature);
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
                applyOrderState(state, false, syncFields);

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

            const showAjaxErrors = (form, errors) => {
                Object.entries(errors || {}).forEach(([name, messages]) => {
                    const field = form.querySelector(`[name="${name}"]`);
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

                    await fetchOrderState(payload.refresh || [], true);

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
                } catch (error) {
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
                const container = form.closest('[data-sales-document-actions]');
                const errorBox = container?.querySelector('[data-sales-document-error]');
                const actions = Array.from(container?.querySelectorAll('button') || []);
                const openDocumentAfterSubmit = form.hasAttribute('data-open-document-after-submit');
                const deletesDocument = form.hasAttribute('data-sales-document-delete-form');
                const deleteModal = deletesDocument ? form.closest('.modal') : null;
                const documentWindow = openDocumentAfterSubmit ? window.open('about:blank', '_blank') : null;

                const closeDeleteModal = () => {
                    if (!deletesDocument) {
                        return;
                    }

                    bootstrap.Modal.getInstance(deleteModal)?.hide();
                    document.querySelectorAll('.modal-backdrop').forEach((backdrop) => backdrop.remove());
                    document.body.classList.remove('modal-open');
                    document.body.style.removeProperty('padding-right');
                };

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

                    closeDeleteModal();

                    container.replaceWith(replacement);
                    syncSalesDocumentNumberWidths(replacement);
                    initializeOrderKsefTooltips(replacement);
                    scheduleKsefAutomaticRefresh();

                    if (openDocumentAfterSubmit) {
                        if (documentWindow) {
                            documentWindow.location.replace(payload.document.pdf_url);
                        } else {
                            window.location.assign(payload.document.pdf_url);
                        }
                    }
                } catch (error) {
                    documentWindow?.close();
                    closeDeleteModal();

                    if (errorBox) {
                        errorBox.textContent = error.message || 'Nie udało się wykonać operacji na dokumencie. Spróbuj ponownie.';
                        errorBox.hidden = false;
                    }

                    actions.forEach((action) => { action.disabled = false; });
                }
            });

            if (orderStateUrl) {
                fetchOrderState(['history', 'shipments']).catch(() => {});
                scheduleKsefAutomaticRefresh(true);

                document.addEventListener('nexoms:automation-finished', (event) => {
                    if (Number(event.detail?.orderId || 0) === currentOrderId) {
                        refreshOrderState(['sales-documents', 'history']);
                    }
                });

                document.addEventListener('visibilitychange', () => {
                    if (!document.hidden) {
                        refreshOrderState(['sales-documents']);
                        scheduleKsefAutomaticRefresh();
                    }
                });

                window.addEventListener('pagehide', () => {
                    window.clearTimeout(ksefAutomaticRefreshTimer);
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
