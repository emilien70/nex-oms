<!doctype html>
<html lang="pl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'NEX-OMS')</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <style>
        body {
            background: #f5f7fb;
        }

        input[type="number"] {
            appearance: textfield;
            -moz-appearance: textfield;
        }

        input[type="number"]::-webkit-inner-spin-button,
        input[type="number"]::-webkit-outer-spin-button {
            appearance: none;
            margin: 0;
            -webkit-appearance: none;
        }

        .nex-pagination-toolbar {
            align-items: center;
            display: flex;
            gap: 8px;
            margin-left: auto;
            white-space: nowrap;
        }

        .nex-page-range {
            background: transparent;
            border: 0;
            color: #111827;
            font-size: 13px;
            padding: 4px 2px;
            text-decoration: underline;
        }

        .nex-page-range:hover,
        .nex-page-range:focus {
            background: #f8fafc;
            color: #0d6efd;
        }

        .nex-page-size-menu {
            max-height: 340px;
            min-width: 96px;
            overflow-y: auto;
        }

        .nex-page-size-menu .dropdown-item {
            font-size: 13px;
            text-align: center;
        }

        .nex-pagination-total {
            color: #9ca3af;
            font-size: 13px;
        }

        .nex-page-navigation .btn {
            align-items: center;
            border-color: #d7dee7;
            color: #475569;
            display: inline-flex;
            height: 32px;
            justify-content: center;
            padding: 0;
            width: 36px;
        }

        .nex-page-navigation .btn.disabled {
            color: #c1c7d0;
        }

        .nex-pagination-dropdown-host {
            overflow: visible !important;
            position: relative;
            z-index: 2;
        }

        .nex-pagination-dropdown-host > .inpost-panel-header {
            border-radius: 7px 7px 0 0;
        }

        .nex-pagination-toolbar .dropdown-menu {
            z-index: 1055;
        }

        @media (max-width: 767.98px) {
            .nex-pagination-toolbar {
                margin-left: 0;
            }
        }

        .app-shell {
            min-height: 100vh;
        }

        .app-workspace {
            display: flex;
            flex: 1 1 auto;
            flex-direction: column;
            min-height: 100vh;
            min-width: 0;
        }

        .app-workspace-body {
            display: flex;
            flex: 1 1 auto;
            min-width: 0;
        }

        .app-topbar {
            align-items: center;
            background: #ffffff;
            border-bottom: 1px solid #e5e7eb;
            column-gap: 18px;
            display: grid;
            flex: 0 0 56px;
            grid-template-columns: minmax(170px, 1fr) minmax(280px, 720px) minmax(170px, 1fr);
            height: 56px;
            padding: 0 20px;
            position: sticky;
            top: 0;
            z-index: 1030;
        }

        .app-topbar-leading {
            justify-self: start;
            min-width: 0;
        }

        .app-topbar-trailing {
            min-width: 0;
        }

        .app-menu-toggle {
            align-items: center;
            background: transparent;
            border: 0;
            border-radius: 6px;
            color: #64748b;
            display: inline-flex;
            font-size: 13px;
            font-weight: 500;
            gap: 10px;
            min-height: 38px;
            padding: 5px 8px;
            white-space: nowrap;
        }

        .app-menu-toggle:hover {
            background: #f1f5f9;
            color: #334155;
        }

        .app-menu-toggle:focus {
            outline: 0;
        }

        .app-menu-toggle:focus-visible {
            box-shadow: 0 0 0 2px rgba(13, 110, 253, .16);
        }

        .app-menu-toggle-chevron {
            font-size: 10px;
            margin-left: 1px;
        }

        .orders-search-form {
            margin: 0;
            max-width: 720px;
            width: 100%;
        }

        .orders-search-control {
            align-items: center;
            background: #ffffff;
            border: 1px solid #cbd5e1;
            border-radius: 999px;
            display: flex;
            gap: 9px;
            height: 42px;
            padding: 0 8px 0 17px;
            transition: border-color .12s ease, box-shadow .12s ease;
        }

        .orders-search-control:focus-within {
            border-color: #70a9e8;
            box-shadow: 0 0 0 2px rgba(13, 110, 253, .1);
        }

        .orders-search-icon {
            color: #64748b;
            flex: 0 0 auto;
            font-size: 14px;
        }

        .orders-search-input {
            background: transparent;
            border: 0;
            color: #111827;
            flex: 1 1 auto;
            font-size: 14px;
            min-width: 0;
            outline: 0;
        }

        .orders-search-input::placeholder {
            color: #9ca3af;
        }

        .orders-search-submit {
            align-items: center;
            background: transparent;
            border: 0;
            border-radius: 999px;
            color: #64748b;
            display: inline-flex;
            flex: 0 0 auto;
            font-size: 17px;
            height: 32px;
            justify-content: center;
            padding: 0;
            width: 32px;
        }

        .orders-search-submit:hover,
        .orders-search-submit:focus-visible {
            background: #f1f5f9;
            color: #0d6efd;
            outline: 0;
        }

        .nex-global-error-toast {
            align-items: flex-start;
            background: #dc2626;
            border: 1px solid rgba(127, 29, 29, .32);
            border-radius: 7px;
            bottom: 18px;
            box-shadow: 0 10px 26px rgba(127, 29, 29, .24);
            color: #ffffff;
            display: flex;
            gap: 10px;
            left: 18px;
            max-width: min(430px, calc(100vw - 36px));
            opacity: 0;
            padding: 12px 14px;
            pointer-events: none;
            position: fixed;
            transform: translateY(12px);
            transition: opacity .16s ease, transform .16s ease;
            visibility: hidden;
            z-index: 1100;
        }

        .nex-global-error-toast.is-visible {
            opacity: 1;
            pointer-events: auto;
            transform: translateY(0);
            visibility: visible;
        }

        .nex-global-error-toast > i {
            flex: 0 0 auto;
            font-size: 17px;
            line-height: 1.25;
        }

        .nex-global-error-toast-message {
            flex: 1 1 auto;
            font-size: 13px;
            line-height: 1.45;
        }

        .nex-global-error-toast-close {
            background: transparent;
            border: 0;
            color: #ffffff;
            flex: 0 0 auto;
            font-size: 19px;
            line-height: 1;
            opacity: .82;
            padding: 0;
        }

        .nex-global-error-toast-close:hover,
        .nex-global-error-toast-close:focus-visible {
            opacity: 1;
            outline: 0;
        }

        .app-shell.has-orders-context .content {
            padding-left: 1rem !important;
        }

        .app-shell.has-orders-context .content > .orders-page,
        .app-shell.has-orders-context .content > .status-settings-page,
        .app-shell.has-orders-context .content > .automatic-actions-page {
            margin-left: -1rem !important;
        }

        .sidebar {
            background: #222c36;
            flex: 0 0 52px;
            min-height: 100vh;
            overflow: visible;
            padding: 10px 0;
            position: sticky;
            top: 0;
            transition: width .16s ease, flex-basis .16s ease, padding .16s ease;
            width: 52px;
            z-index: 1040;
        }

        .sidebar-nav-item {
            position: relative;
        }

        .sidebar .nav-link {
            align-items: center;
            border-radius: 0;
            color: rgba(255, 255, 255, .78);
            display: flex;
            height: 46px;
            justify-content: center;
            margin: 2px 0;
            padding: 0;
            position: relative;
            text-decoration: none;
            transition: background .12s ease, border-radius .12s ease, justify-content .12s ease, padding .12s ease;
        }

        .sidebar .nav-link:hover,
        .sidebar .nav-link.active {
            color: #ffffff;
            background: transparent;
        }

        .sidebar .nav-link.active::after {
            background: #0d6efd;
            border-radius: 999px;
            bottom: 4px;
            content: "";
            height: 4px;
            left: 50%;
            position: absolute;
            transform: translateX(-50%);
            width: 4px;
        }

        .sidebar-brand {
            align-items: center;
            color: #ffffff;
            display: flex;
            font-size: 24px;
            font-weight: 800;
            height: 44px;
            justify-content: center;
            letter-spacing: 0;
            margin-bottom: 18px;
            position: relative;
        }

        .sidebar-brand-full {
            display: none;
        }

        .sidebar-brand::after {
            background: #0d6efd;
            border-radius: 50%;
            bottom: 8px;
            content: "";
            height: 5px;
            position: absolute;
            right: 13px;
            width: 5px;
        }

        .nav-initial {
            flex: 0 0 22px;
            font-size: 14px;
            font-weight: 700;
            line-height: 1;
            text-align: center;
            text-transform: uppercase;
        }

        .nav-dashboard-icon {
            font-size: 19px;
            font-weight: 400;
        }

        .nav-dashboard-icon .bi,
        .nav-orders-icon .bi,
        .nav-products-icon .bi,
        .nav-integrations-icon .bi,
        .nav-settings-icon .bi {
            display: block;
            line-height: 1;
        }

        .nav-orders-icon {
            font-size: 20px;
            font-weight: 400;
        }

        .nav-products-icon {
            font-size: 20px;
            font-weight: 400;
        }

        .nav-integrations-icon {
            font-size: 20px;
            font-weight: 400;
        }

        .nav-settings-icon {
            font-size: 19px;
            font-weight: 400;
        }

        .nav-text {
            display: none;
            font-size: 15px;
            font-weight: 600;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .app-shell.sidebar-expanded .sidebar {
            flex-basis: 252px;
            padding: 10px 8px;
            width: 252px;
        }

        .app-shell.sidebar-expanded .sidebar-brand {
            height: 56px;
            justify-content: flex-start;
            margin-bottom: 12px;
            padding-left: 8px;
        }

        .app-shell.sidebar-expanded .sidebar-brand::after {
            display: none;
        }

        .app-shell.sidebar-expanded .sidebar-brand-short {
            display: none;
        }

        .app-shell.sidebar-expanded .sidebar-brand-full {
            display: inline;
            font-size: 25px;
            letter-spacing: 0;
        }

        .app-shell.sidebar-expanded .sidebar .nav-link {
            border-radius: 999px;
            gap: 10px;
            height: 42px;
            justify-content: flex-start;
            margin: 5px 0;
            padding: 0 14px;
        }

        .app-shell.sidebar-expanded .sidebar .nav-link:hover,
        .app-shell.sidebar-expanded .sidebar .nav-link.active {
            background: #111923;
            color: #ffffff;
        }

        .app-shell.sidebar-expanded .sidebar .nav-link.active::after {
            display: none;
        }

        .app-shell.sidebar-expanded .nav-text {
            display: inline;
        }

        .app-shell.sidebar-expanded .sidebar-nav-item > .nav-flyout {
            display: none;
        }

        .nav-chevron {
            border-bottom: 1.5px solid currentColor;
            border-right: 1.5px solid currentColor;
            display: none;
            height: 7px;
            margin-left: auto;
            transform: rotate(45deg);
            transition: transform .14s ease;
            width: 7px;
        }

        .app-shell.sidebar-expanded .has-nav-flyout > .nav-link .nav-chevron {
            display: block;
        }

        .app-shell.sidebar-expanded .has-nav-flyout.is-open > .nav-link .nav-chevron {
            margin-top: 5px;
            transform: rotate(225deg);
        }

        .app-shell.sidebar-expanded .has-nav-flyout.is-open > .nav-flyout {
            background: transparent;
            border-radius: 0;
            box-shadow: none;
            display: block;
            left: auto;
            min-width: 0;
            opacity: 1;
            padding: 0 8px 7px 36px;
            pointer-events: auto;
            position: static;
            top: auto;
            transform: none;
            visibility: visible;
        }

        .app-shell.sidebar-expanded .has-nav-flyout.is-open > .nav-flyout::before,
        .app-shell.sidebar-expanded .has-nav-flyout.is-open > .nav-flyout > .nav-flyout-title {
            display: none;
        }

        .app-shell.sidebar-expanded .has-nav-flyout.is-open .nav-flyout-link,
        .app-shell.sidebar-expanded .has-nav-flyout.is-open .nav-flyout-button {
            min-height: 34px;
            padding: 6px 12px;
        }

        .nav-flyout {
            background: #2a333c;
            border-radius: 5px;
            box-shadow: 0 12px 28px rgba(15, 23, 42, .24);
            color: #ffffff;
            left: 52px;
            min-width: 212px;
            opacity: 0;
            padding: 14px 14px 16px;
            pointer-events: none;
            position: absolute;
            top: 0;
            transform: translateX(-6px);
            transition: opacity .12s ease, transform .12s ease;
            visibility: hidden;
            z-index: 1080;
        }

        .nav-flyout::before {
            border-bottom: 8px solid transparent;
            border-right: 8px solid #2a333c;
            border-top: 8px solid transparent;
            content: "";
            left: -8px;
            position: absolute;
            top: 22px;
        }

        .sidebar-nav-item:hover .nav-flyout,
        .sidebar-nav-item:focus-within .nav-flyout {
            opacity: 1;
            pointer-events: auto;
            transform: translateX(0);
            visibility: visible;
        }

        .nav-flyout-title {
            color: #ffffff;
            font-size: 15px;
            font-weight: 700;
            margin-bottom: 10px;
        }

        .nav-flyout-link,
        .nav-flyout-button {
            align-items: center;
            background: transparent;
            border: 0;
            border-radius: 999px;
            color: rgba(255, 255, 255, .9);
            display: flex;
            font-size: 13px;
            min-height: 34px;
            padding: 7px 14px;
            text-align: left;
            text-decoration: none;
            width: 100%;
        }

        .nav-flyout-link:hover,
        .nav-flyout-link.active,
        .nav-flyout-button:hover {
            background: #1d2730;
            color: #32a3ff;
        }

        .nav-flyout-primary {
            background: #0d8bff;
            color: #ffffff;
            font-weight: 700;
            justify-content: center;
            margin-bottom: 10px;
        }

        .nav-flyout-primary:hover {
            background: #0878df;
            color: #ffffff;
        }

        .content {
            min-width: 0;
        }

        .orders-context-sidebar {
            background: #f1f3f6;
            border-right: 1px solid #d7dee7;
            flex: 0 0 198px;
            min-height: calc(100vh - 56px);
            padding: 10px 0;
            position: sticky;
            top: 56px;
            width: 198px;
            z-index: 1020;
        }

        .orders-context-inner {
            padding: 0 0 12px;
        }

        .orders-context-toggle-icon {
            align-items: center;
            border: 2px solid #93a3b8;
            border-radius: 3px;
            display: inline-flex;
            flex: 0 0 auto;
            height: 23px;
            justify-content: center;
            position: relative;
            width: 24px;
        }

        .orders-context-toggle-icon::before {
            background: #93a3b8;
            content: "";
            height: 100%;
            left: 9px;
            position: absolute;
            top: 0;
            width: 2px;
        }

        .orders-context-toggle-icon::after {
            border-bottom: 5px solid transparent;
            border-right: 6px solid #93a3b8;
            border-top: 5px solid transparent;
            content: "";
            position: absolute;
            right: 4px;
            top: 50%;
            transform: translateY(-50%);
        }

        .app-shell.sidebar-expanded .orders-context-toggle-icon::after {
            border-left: 6px solid #93a3b8;
            border-right: 0;
            left: 4px;
            right: auto;
        }

        @media (max-width: 1199.98px) {
            .app-topbar {
                grid-template-columns: auto minmax(0, 1fr);
            }

            .app-topbar-trailing {
                display: none;
            }
        }

        .orders-context-add-form {
            padding: 0 8px 10px;
        }

        .orders-context-add {
            align-items: center;
            background: #0788e8;
            border: 0;
            border-radius: 16px;
            color: #ffffff;
            display: flex;
            font-size: 13px;
            font-weight: 700;
            gap: 8px;
            justify-content: center;
            min-height: 38px;
            width: 100%;
        }

        .orders-context-add:hover {
            background: #0777ce;
            color: #ffffff;
        }

        .orders-context-plus {
            align-items: center;
            border: 2px solid rgba(255, 255, 255, .95);
            border-radius: 50%;
            display: inline-flex;
            flex: 0 0 auto;
            font-size: 20px;
            height: 28px;
            justify-content: center;
            line-height: 1;
            width: 28px;
        }

        .orders-context-list {
            margin: 0;
            padding: 0;
        }

        .orders-context-link {
            align-items: center;
            color: #111827;
            display: flex;
            font-size: 12px;
            gap: 5px;
            min-height: 24px;
            padding: 3px 8px;
            text-decoration: none;
        }

        .orders-context-link:hover,
        .orders-context-link.active {
            background: #d0d6df;
            color: #111827;
        }

        .orders-context-link.active {
            font-weight: 700;
        }

        .orders-context-link-all {
            gap: 8px;
            min-height: 36px;
            padding: 7px 9px;
        }

        .orders-context-link-all.active {
            font-weight: 500;
        }

        .orders-context-all-icon {
            border: 1.5px solid #374151;
            border-radius: 2px;
            display: inline-block;
            flex: 0 0 auto;
            height: 9px;
            position: relative;
            width: 12px;
        }

        .orders-context-all-icon::before {
            border: 1.5px solid #374151;
            border-bottom: 0;
            border-radius: 2px 2px 0 0;
            content: '';
            height: 3px;
            left: 2px;
            position: absolute;
            right: 2px;
            top: -4px;
        }

        .orders-context-count {
            border-radius: 4px;
            color: #ffffff;
            flex: 0 0 auto;
            font-size: 11px;
            font-weight: 700;
            line-height: 1;
            min-width: 17px;
            padding: 2px 4px;
            text-align: center;
        }

        .orders-context-link-label {
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .orders-context-action-icon {
            color: #1f2937;
            flex: 0 0 14px;
            font-size: 13px;
            line-height: 1;
            text-align: center;
        }

        .orders-context-section {
            border-top: 1px solid #d7dee7;
            margin-top: 8px;
            padding-top: 7px;
        }

        .orders-context-add-status {
            color: #111827;
            font-size: 12px;
            text-decoration: none;
        }

        .orders-context-add-status:hover {
            color: #0d6efd;
        }

        .orders-context-footer {
            align-items: center;
            display: flex;
            justify-content: space-between;
            padding: 7px 8px 0;
        }

        .orders-context-refresh {
            align-items: center;
            border-radius: 4px;
            color: #64748b;
            display: inline-flex;
            font-size: 18px;
            height: 24px;
            justify-content: center;
            line-height: 1;
            text-decoration: none;
            width: 24px;
        }

        .orders-context-refresh:hover {
            background: #e3e8ef;
            color: #0d6efd;
        }

        .automation-activity-center {
            bottom: 16px;
            display: grid;
            gap: 8px;
            max-height: calc(100vh - 32px);
            overflow-y: auto;
            padding: 2px;
            pointer-events: none;
            position: fixed;
            right: 16px;
            width: min(390px, calc(100vw - 32px));
            z-index: 1090;
        }

        .automation-activity-center[hidden] {
            display: none;
        }

        .automation-activity-item {
            align-items: flex-start;
            background: #ffffff;
            border: 1px solid #d8dee7;
            border-left: 4px solid #0d6efd;
            border-radius: 7px;
            box-shadow: 0 8px 22px rgba(15, 23, 42, .16);
            display: flex;
            gap: 10px;
            min-height: 64px;
            padding: 10px 11px;
            pointer-events: auto;
        }

        .automation-activity-item.is-success {
            border-left-color: #198754;
        }

        .automation-activity-item.is-error {
            border-left-color: #dc3545;
        }

        .automation-activity-item.is-waiting {
            border-left-color: #d89b15;
        }

        .automation-activity-icon {
            align-items: center;
            background: #e9f2ff;
            border-radius: 50%;
            color: #0d6efd;
            display: inline-flex;
            flex: 0 0 27px;
            font-size: 14px;
            font-weight: 700;
            height: 27px;
            justify-content: center;
            margin-top: 1px;
        }

        .automation-activity-item.is-success .automation-activity-icon {
            background: #e8f6ee;
            color: #198754;
        }

        .automation-activity-item.is-error .automation-activity-icon {
            background: #fdebec;
            color: #dc3545;
        }

        .automation-activity-item.is-waiting .automation-activity-icon {
            background: #fff6df;
            color: #a86e00;
        }

        .automation-activity-spinner {
            animation: automation-activity-spin .75s linear infinite;
            border: 2px solid currentColor;
            border-right-color: transparent;
            border-radius: 50%;
            height: 14px;
            width: 14px;
        }

        @keyframes automation-activity-spin {
            to {
                transform: rotate(360deg);
            }
        }

        .automation-activity-content {
            min-width: 0;
            width: 100%;
        }

        .automation-activity-heading {
            align-items: flex-start;
            display: flex;
            gap: 8px;
            justify-content: space-between;
        }

        .automation-activity-title {
            color: #172033;
            font-size: 13px;
            font-weight: 700;
            line-height: 1.25;
            margin: 0;
        }

        .automation-activity-close {
            align-items: center;
            background: transparent;
            border: 0;
            border-radius: 4px;
            color: #7b8798;
            display: inline-flex;
            flex: 0 0 22px;
            font-size: 18px;
            height: 22px;
            justify-content: center;
            line-height: 1;
            margin: -4px -4px 0 0;
            padding: 0;
        }

        .automation-activity-close:hover {
            background: #eef2f6;
            color: #172033;
        }

        .automation-activity-meta {
            align-items: center;
            display: flex;
            flex-wrap: wrap;
            gap: 4px 8px;
            margin-top: 2px;
        }

        .automation-activity-order,
        .automation-activity-status,
        .automation-activity-progress {
            font-size: 11px;
            line-height: 1.25;
        }

        .automation-activity-order {
            color: #0b6fd3;
            text-decoration: none;
        }

        .automation-activity-order:hover {
            text-decoration: underline;
        }

        .automation-activity-status,
        .automation-activity-progress {
            color: #718096;
        }

        .automation-activity-message {
            color: #374151;
            font-size: 12px;
            line-height: 1.35;
            margin-top: 5px;
        }

        .automation-activity-details {
            color: #b4232f;
            display: -webkit-box;
            font-size: 11px;
            line-height: 1.35;
            margin-top: 3px;
            overflow: hidden;
            -webkit-box-orient: vertical;
            -webkit-line-clamp: 2;
        }

        .page-navigation-loading {
            align-items: center;
            background: rgba(244, 246, 248, .55);
            display: flex;
            inset: 0;
            justify-content: center;
            opacity: 0;
            pointer-events: none;
            position: fixed;
            transition: opacity .08s ease;
            visibility: hidden;
            z-index: 2000;
        }

        .page-navigation-loading.is-visible {
            opacity: 1;
            visibility: visible;
        }

        .page-navigation-loading-box {
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
            min-width: 280px;
            padding: 13px 22px;
        }

        .page-navigation-loading-box .spinner-border {
            border-width: 2px;
            height: 20px;
            width: 20px;
        }

        @media (max-width: 767.98px) {
            .app-shell {
                flex-direction: column;
            }

            .sidebar {
                flex: 0 0 auto;
                min-height: auto;
                position: static;
                width: 100%;
            }

            .sidebar nav {
                flex-direction: row !important;
                justify-content: center;
            }

            .sidebar-brand {
                display: none;
            }

            .app-workspace {
                min-height: auto;
                width: 100%;
            }

            .app-workspace-body {
                display: block;
            }

            .app-topbar {
                column-gap: 8px;
                flex-basis: 54px;
                grid-template-columns: 38px minmax(0, 1fr);
                height: 54px;
                padding: 6px 12px;
            }

            .app-menu-toggle {
                height: 38px;
                justify-content: center;
                min-height: 38px;
                padding: 0;
                width: 38px;
            }

            .app-menu-toggle-label,
            .app-menu-toggle-chevron {
                display: none;
            }

            .orders-search-control {
                height: 40px;
            }

            .app-shell.sidebar-expanded .sidebar {
                flex-basis: auto;
                padding: 10px 0;
                width: 100%;
            }

            .app-shell.sidebar-expanded .nav-text {
                display: none;
            }

            .nav-flyout {
                display: none;
            }

            .orders-context-sidebar {
                display: none;
            }

            .app-shell.has-orders-context .content {
                padding-left: 1.5rem !important;
            }

            .automation-activity-center {
                bottom: 8px;
                right: 8px;
                width: calc(100vw - 16px);
            }
        }
    </style>
</head>
<body>
    @php
        $ordersNavigationActive = request()->is('orders*')
            || request()->routeIs('invoices.*')
            || request()->routeIs('settings.order-statuses.index')
            || request()->routeIs('settings.variables.index');
        $hasOrdersContext = request()->is('orders*')
            || request()->routeIs('settings.order-statuses.index')
            || request()->routeIs('settings.variables.index');
        $currentLayoutStatus = request()->query('status');
        $currentLayoutStatus = is_string($currentLayoutStatus) ? $currentLayoutStatus : null;
        $layoutShowsTrash = request()->boolean('trash');
        $globalOrderSearchQuery = request()->routeIs('orders.index')
            ? trim((string) request()->query('q', ''))
            : '';
    @endphp

    <div class="app-shell d-flex {{ $hasOrdersContext ? 'has-orders-context' : '' }}">
        <aside class="sidebar text-white">
            <div class="sidebar-brand" aria-label="NEX-OMS">
                <span class="sidebar-brand-short">N</span>
                <span class="sidebar-brand-full">NEX-OMS</span>
            </div>
            <nav class="nav flex-column">
                <div class="sidebar-nav-item">
                    <a class="nav-link {{ request()->is('/') ? 'active' : '' }}" href="/" aria-label="Dashboard" title="Dashboard">
                        <span class="nav-initial nav-dashboard-icon" aria-hidden="true"><i class="bi bi-speedometer"></i></span>
                        <span class="nav-text">Strona g&#322;&oacute;wna</span>
                    </a>
                </div>
                <div class="sidebar-nav-item has-nav-flyout orders-nav-item {{ $ordersNavigationActive ? 'is-open' : '' }}">
                    <a class="nav-link {{ $ordersNavigationActive ? 'active' : '' }}" href="{{ route('orders.index') }}" aria-label="Zam&oacute;wienia" title="Zam&oacute;wienia" aria-expanded="{{ $ordersNavigationActive ? 'true' : 'false' }}">
                        <span class="nav-initial nav-orders-icon" aria-hidden="true"><i class="bi bi-cart3"></i></span>
                        <span class="nav-text">Zam&oacute;wienia</span>
                        <span class="nav-chevron" aria-hidden="true"></span>
                    </a>
                    <div class="nav-flyout">
                        <div class="nav-flyout-title">Zam&oacute;wienia</div>
                        <a class="nav-flyout-link {{ request()->routeIs('orders.index') && ! request()->boolean('trash') ? 'active' : '' }}" href="{{ route('orders.index') }}">Lista zam&oacute;wie&#324;</a>
                        <a class="nav-flyout-link {{ request()->routeIs('invoices.*') ? 'active' : '' }}" href="{{ route('invoices.index') }}">Faktury</a>
                        <a class="nav-flyout-link" href="#">Zwroty</a>
                        <a class="nav-flyout-link {{ request()->routeIs('settings.order-statuses.index') ? 'active' : '' }}" href="{{ route('settings.order-statuses.index') }}">Statusy zam&oacute;wie&#324;</a>
                        <a class="nav-flyout-link {{ request()->routeIs('orders.automatic-actions.index') ? 'active' : '' }}" href="{{ route('orders.automatic-actions.index') }}">Automatyczne akcje</a>
                        <a class="nav-flyout-link {{ request()->routeIs('settings.variables.index') ? 'active' : '' }}" href="{{ route('settings.variables.index') }}">Zmienne</a>
                    </div>
                </div>
                <div class="sidebar-nav-item has-nav-flyout">
                    <a class="nav-link" href="#" aria-label="Produkty" title="Produkty" aria-expanded="false">
                        <span class="nav-initial nav-products-icon" aria-hidden="true"><i class="bi bi-bookshelf"></i></span>
                        <span class="nav-text">Produkty</span>
                        <span class="nav-chevron" aria-hidden="true"></span>
                    </a>
                    <div class="nav-flyout">
                        <div class="nav-flyout-title">Produkty</div>
                        <a class="nav-flyout-link" href="#">Lista produkt&oacute;w</a>
                    </div>
                </div>
                <div class="sidebar-nav-item has-nav-flyout {{ request()->is('integrations*') ? 'is-open' : '' }}">
                    <a class="nav-link {{ request()->is('integrations*') ? 'active' : '' }}" href="{{ route('integrations.index') }}" aria-label="Integracje" title="Integracje" aria-expanded="{{ request()->is('integrations*') ? 'true' : 'false' }}">
                        <span class="nav-initial nav-integrations-icon" aria-hidden="true"><i class="bi bi-plug"></i></span>
                        <span class="nav-text">Integracje</span>
                        <span class="nav-chevron" aria-hidden="true"></span>
                    </a>
                    <div class="nav-flyout">
                        <div class="nav-flyout-title">Integracje</div>
                        <a class="nav-flyout-link {{ request()->routeIs('integrations.index') ? 'active' : '' }}" href="{{ route('integrations.index') }}">Lista integracji</a>
                        <a class="nav-flyout-link {{ request()->routeIs('integrations.couriers.index') ? 'active' : '' }}" href="{{ route('integrations.couriers.index') }}">Kurierzy</a>
                    </div>
                </div>
                <div class="sidebar-nav-item has-nav-flyout">
                    <a class="nav-link" href="#" aria-label="Ustawienia" title="Ustawienia" aria-expanded="false">
                        <span class="nav-initial nav-settings-icon" aria-hidden="true"><i class="bi bi-gear"></i></span>
                        <span class="nav-text">Ustawienia</span>
                        <span class="nav-chevron" aria-hidden="true"></span>
                    </a>
                    <div class="nav-flyout">
                        <div class="nav-flyout-title">Ustawienia</div>
                        <a class="nav-flyout-link" href="#">Ustawienia has&#322;a</a>
                    </div>
                </div>
            </nav>
        </aside>

        <div class="app-workspace">
            <header class="app-topbar">
                <div class="app-topbar-leading">
                    <button class="app-menu-toggle" type="button" data-sidebar-toggle aria-expanded="false" aria-label="Poka&#380; menu" title="Poka&#380; menu">
                        <span class="orders-context-toggle-icon" aria-hidden="true"></span>
                        <span class="app-menu-toggle-label">Szybki dost&#281;p</span>
                        <i class="bi bi-chevron-down app-menu-toggle-chevron" aria-hidden="true"></i>
                    </button>
                </div>
                <form class="orders-search-form" method="GET" action="{{ route('orders.index') }}" data-global-order-search-form>
                    <div class="orders-search-control">
                        <i class="bi bi-search orders-search-icon" aria-hidden="true"></i>
                        <input
                            class="orders-search-input"
                            type="search"
                            name="q"
                            value="{{ $globalOrderSearchQuery }}"
                            placeholder="Szukaj..."
                            aria-label="Szukaj zam&oacute;wienia"
                            autocomplete="off"
                            data-global-order-search-input
                        >
                        <button class="orders-search-submit" type="submit" aria-label="Wyszukaj zam&oacute;wienie" title="Wyszukaj zam&oacute;wienie">
                            <i class="bi bi-arrow-right" aria-hidden="true"></i>
                        </button>
                    </div>
                </form>
                <div class="app-topbar-trailing" aria-hidden="true"></div>
            </header>

            <div class="app-workspace-body">
        @if ($hasOrdersContext)
            <aside class="orders-context-sidebar" aria-label="Menu zamowien">
                <div class="orders-context-inner">
                    <form class="orders-context-add-form" method="POST" action="{{ route('orders.empty-store') }}">
                        @csrf
                        <button class="orders-context-add" type="submit">
                            <span class="orders-context-plus">+</span>
                            Dodaj zam&oacute;wienie
                        </button>
                    </form>

                    <nav class="orders-context-list">
                        <a class="orders-context-link orders-context-link-all {{ ! $layoutShowsTrash && $currentLayoutStatus === null && request()->routeIs('orders.index') ? 'active' : '' }}" href="{{ route('orders.index') }}">
                            <span class="orders-context-all-icon" aria-hidden="true"></span>
                            <span class="orders-context-link-label">Wszystkie</span>
                        </a>
                        @foreach ($layoutOrderStatuses ?? [] as $status)
                            <a class="orders-context-link {{ ! $layoutShowsTrash && $currentLayoutStatus === $status['code'] ? 'active' : '' }}" href="{{ route('orders.index', ['status' => $status['code']]) }}">
                                <span class="orders-context-count" style="background: {{ $status['color'] }}; color: {{ $status['code'] === 'new' ? '#ffffff' : ($status['text_color'] ?? '#ffffff') }};">{{ $layoutOrderStatusCounts[$status['code']] ?? 0 }}</span>
                                <span class="orders-context-link-label">{{ $status['name'] }}</span>
                            </a>
                        @endforeach

                        <div class="orders-context-section">
                            <a class="orders-context-link" href="#">
                                <i class="bi bi-file-earmark-text orders-context-action-icon" aria-hidden="true"></i>
                                <span class="orders-context-link-label">Archiwum</span>
                            </a>
                            <a class="orders-context-link {{ $layoutShowsTrash ? 'active' : '' }}" href="{{ route('orders.index', ['trash' => 1]) }}">
                                <i class="bi bi-trash3 orders-context-action-icon" aria-hidden="true"></i>
                                <span class="orders-context-link-label">Kosz</span>
                                @if (($layoutTrashOrdersCount ?? 0) > 0)
                                    <span class="orders-context-count ms-auto" style="background: #111827;">{{ $layoutTrashOrdersCount }}</span>
                                @endif
                            </a>
                        </div>

                        <div class="orders-context-footer">
                            <a class="orders-context-add-status" href="{{ route('settings.order-statuses.index') }}">+ Dodaj status</a>
                            <a class="orders-context-refresh" href="{{ request()->fullUrl() }}" title="Od&#347;wie&#380; list&#281; i liczniki zam&oacute;wie&#324;" aria-label="Od&#347;wie&#380; list&#281; i liczniki zam&oacute;wie&#324;">&#8635;</a>
                        </div>
                    </nav>
                </div>
            </aside>
        @endif

        <main class="content flex-grow-1 p-4">
            @if (session('success'))
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    {{ session('success') }}
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Zamknij"></button>
                </div>
            @endif

            @if ($errors->any())
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <div class="fw-semibold mb-1">Nie uda&#322;o si&#281; zapisa&#263; zmian.</div>
                    <ul class="mb-0">
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Zamknij"></button>
                </div>
            @endif

            @yield('content')
        </main>
            </div>
        </div>
    </div>

    <div class="nex-global-error-toast" data-global-error-toast role="alert" aria-live="assertive" aria-hidden="true">
        <i class="bi bi-exclamation-circle-fill" aria-hidden="true"></i>
        <span class="nex-global-error-toast-message" data-global-error-toast-message></span>
        <button class="nex-global-error-toast-close" type="button" aria-label="Zamknij komunikat" data-global-error-toast-close>&times;</button>
    </div>

    <div class="page-navigation-loading" data-page-navigation-loading-overlay aria-hidden="true">
        <div class="page-navigation-loading-box" role="status" aria-live="polite">
            <span class="spinner-border spinner-border-sm" aria-hidden="true"></span>
            <span>Prosz&#281; czeka&#263;</span>
        </div>
    </div>

    <div
        class="automation-activity-center"
        data-automation-activity-center
        data-endpoint="{{ route('automation.activity.index') }}"
        aria-live="polite"
        aria-label="Wykonywane automatyczne akcje"
        hidden
    ></div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const toast = document.querySelector('[data-global-error-toast]');
            const toastMessage = toast?.querySelector('[data-global-error-toast-message]');
            const toastClose = toast?.querySelector('[data-global-error-toast-close]');
            const scanUrl = @json(route('orders.scan'));
            const minimumScanLength = 6;
            const maximumKeyGap = 100;
            let scanBuffer = '';
            let scanStartedAt = 0;
            let lastScanKeyAt = 0;
            let scanInProgress = false;
            let toastTimer = null;

            const hideError = () => {
                window.clearTimeout(toastTimer);
                toast?.classList.remove('is-visible');
                toast?.setAttribute('aria-hidden', 'true');
            };

            const showError = (message) => {
                if (!toast || !toastMessage) {
                    return;
                }

                window.clearTimeout(toastTimer);
                toastMessage.textContent = message;
                toast.classList.add('is-visible');
                toast.setAttribute('aria-hidden', 'false');
                toastTimer = window.setTimeout(hideError, 6500);
            };

            window.nexOmsShowError = showError;
            toastClose?.addEventListener('click', hideError);

            const resetScan = () => {
                scanBuffer = '';
                scanStartedAt = 0;
                lastScanKeyAt = 0;
            };

            const openScannedOrder = async (code) => {
                if (scanInProgress) {
                    return;
                }

                scanInProgress = true;

                try {
                    const url = new URL(scanUrl, window.location.origin);
                    url.searchParams.set('code', code);
                    const response = await fetch(url.toString(), {
                        headers: {
                            Accept: 'application/json',
                            'X-Requested-With': 'XMLHttpRequest',
                        },
                    });
                    const payload = await response.json().catch(() => ({}));

                    if (!response.ok || !payload.order_url) {
                        throw new Error(payload.message || 'Nie znaleziono zam\u00f3wienia dla zeskanowanego numeru przesy\u0142ki.');
                    }

                    window.location.assign(payload.order_url);
                } catch (error) {
                    showError(error.message || 'Nie uda\u0142o si\u0119 sprawdzi\u0107 numeru przesy\u0142ki.');
                    scanInProgress = false;
                }
            };

            document.addEventListener('keydown', (event) => {
                const target = event.target instanceof Element ? event.target : null;
                const isEditable = target?.closest('input, textarea, select, [contenteditable="true"]');

                if (isEditable || event.ctrlKey || event.metaKey || event.altKey || event.isComposing) {
                    resetScan();
                    return;
                }

                const now = performance.now();

                if (event.key === 'Enter' || event.key === 'Tab') {
                    const code = scanBuffer.trim();
                    const duration = scanStartedAt > 0 ? now - scanStartedAt : Number.POSITIVE_INFINITY;
                    const maximumDuration = Math.max(900, code.length * maximumKeyGap);
                    const looksLikeScannerInput = code.length >= minimumScanLength
                        && now - lastScanKeyAt <= 220
                        && duration <= maximumDuration;

                    resetScan();

                    if (looksLikeScannerInput) {
                        event.preventDefault();
                        openScannedOrder(code);
                    }

                    return;
                }

                if (event.key.length !== 1) {
                    if (!['Shift', 'CapsLock'].includes(event.key)) {
                        resetScan();
                    }

                    return;
                }

                if (lastScanKeyAt > 0 && now - lastScanKeyAt > maximumKeyGap) {
                    scanBuffer = '';
                    scanStartedAt = 0;
                }

                if (scanStartedAt === 0) {
                    scanStartedAt = now;
                }

                scanBuffer += event.key;
                lastScanKeyAt = now;
                event.preventDefault();
            }, true);
        });
    </script>
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const overlay = document.querySelector('[data-page-navigation-loading-overlay]');
            let showTimer = null;

            if (!overlay) {
                return;
            }

            const hideOverlay = () => {
                window.clearTimeout(showTimer);
                overlay.classList.remove('is-visible');
                overlay.setAttribute('aria-hidden', 'true');
            };

            document.addEventListener('click', (event) => {
                const link = event.target.closest('a[data-page-navigation-loading]');

                if (
                    !link
                    || event.defaultPrevented
                    || event.button !== 0
                    || event.metaKey
                    || event.ctrlKey
                    || event.shiftKey
                    || event.altKey
                    || link.target === '_blank'
                    || link.hasAttribute('download')
                ) {
                    return;
                }

                window.clearTimeout(showTimer);
                showTimer = window.setTimeout(() => {
                    overlay.classList.add('is-visible');
                    overlay.setAttribute('aria-hidden', 'false');
                }, 120);
            });

            window.addEventListener('pageshow', hideOverlay);
        });
    </script>
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const shell = document.querySelector('.app-shell');
            const toggle = document.querySelector('[data-sidebar-toggle]');
            const navItems = [...document.querySelectorAll('.sidebar-nav-item.has-nav-flyout')];
            const storageKey = 'nexOmsSidebarExpanded';

            if (!shell) {
                return;
            }

            const syncNavItems = (sidebarExpanded) => {
                navItems.forEach((item) => {
                    const link = item.querySelector(':scope > .nav-link');
                    link?.setAttribute(
                        'aria-expanded',
                        sidebarExpanded && item.classList.contains('is-open') ? 'true' : 'false',
                    );
                });
            };

            const applyState = (expanded) => {
                const label = expanded ? 'Ukryj menu' : 'Poka\u017c menu';

                shell.classList.toggle('sidebar-expanded', expanded);
                toggle?.setAttribute('aria-expanded', expanded ? 'true' : 'false');
                toggle?.setAttribute('aria-label', label);
                toggle?.setAttribute('title', label);
                syncNavItems(expanded);
            };

            applyState(localStorage.getItem(storageKey) === '1');

            toggle?.addEventListener('click', () => {
                const expanded = !shell.classList.contains('sidebar-expanded');

                applyState(expanded);
                localStorage.setItem(storageKey, expanded ? '1' : '0');
            });

            navItems.forEach((item) => {
                const link = item.querySelector(':scope > .nav-link');

                link?.addEventListener('click', (event) => {
                    if (!shell.classList.contains('sidebar-expanded')) {
                        return;
                    }

                    event.preventDefault();
                    const shouldOpen = !item.classList.contains('is-open');

                    navItems.forEach((otherItem) => otherItem.classList.remove('is-open'));
                    if (shouldOpen) {
                        item.classList.add('is-open');
                    }

                    syncNavItems(true);
                });
            });
        });
    </script>
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const center = document.querySelector('[data-automation-activity-center]');

            if (!center) {
                return;
            }

            const endpoint = center.dataset.endpoint;
            const dismissedStorageKey = 'nexOmsDismissedAutomationActivities';
            const autoHideTimers = new Map();
            const handledTerminalActivities = new Set();
            let activities = [];
            let loading = false;
            let refreshTimer = null;
            let fastPollingUntil = 0;

            const readDismissed = () => {
                try {
                    const stored = JSON.parse(sessionStorage.getItem(dismissedStorageKey) || '[]');

                    return new Set(Array.isArray(stored) ? stored : []);
                } catch (error) {
                    return new Set();
                }
            };

            const dismissed = readDismissed();

            const activityToken = (activity) => [
                activity.id,
                activity.status,
                activity.updated_at || '',
            ].join(':');

            const notifyFinishedAutomations = (incoming) => {
                incoming.forEach((activity) => {
                    if (!activity.is_terminal) {
                        return;
                    }

                    const token = `${activity.id}:${activity.status}`;
                    if (handledTerminalActivities.has(token)) {
                        return;
                    }

                    handledTerminalActivities.add(token);
                    document.dispatchEvent(new CustomEvent('nexoms:automation-finished', {
                        detail: {
                            orderId: Number(activity.order_id),
                            runId: Number(activity.id),
                            status: activity.status,
                        },
                    }));
                });

                while (handledTerminalActivities.size > 100) {
                    handledTerminalActivities.delete(handledTerminalActivities.values().next().value);
                }
            };

            const rememberDismissed = (token) => {
                dismissed.add(token);

                try {
                    sessionStorage.setItem(
                        dismissedStorageKey,
                        JSON.stringify(Array.from(dismissed).slice(-100)),
                    );
                } catch (error) {
                    // The panel still works when browser storage is unavailable.
                }
            };

            const statusIcon = (activity) => {
                const icon = document.createElement('span');
                icon.className = 'automation-activity-icon';
                icon.setAttribute('aria-hidden', 'true');

                if (activity.tone === 'progress') {
                    const spinner = document.createElement('span');
                    spinner.className = 'automation-activity-spinner';
                    icon.appendChild(spinner);
                } else if (activity.tone === 'success') {
                    icon.textContent = '\u2713';
                } else if (activity.tone === 'error') {
                    icon.textContent = '!';
                } else {
                    icon.textContent = '\u2026';
                }

                return icon;
            };

            const render = () => {
                center.replaceChildren();

                const visible = activities
                    .filter((activity) => !dismissed.has(activityToken(activity)))
                    .slice(0, 6);

                center.hidden = visible.length === 0;

                visible.forEach((activity) => {
                    const token = activityToken(activity);
                    const item = document.createElement('article');
                    item.className = `automation-activity-item is-${activity.tone}`;
                    item.setAttribute('role', activity.tone === 'error' ? 'alert' : 'status');
                    item.appendChild(statusIcon(activity));

                    const content = document.createElement('div');
                    content.className = 'automation-activity-content';

                    const heading = document.createElement('div');
                    heading.className = 'automation-activity-heading';

                    const title = document.createElement('p');
                    title.className = 'automation-activity-title';
                    title.textContent = activity.title;
                    heading.appendChild(title);

                    if (activity.can_dismiss) {
                        const close = document.createElement('button');
                        close.className = 'automation-activity-close';
                        close.type = 'button';
                        close.textContent = '\u00d7';
                        close.setAttribute('aria-label', 'Zamknij komunikat automatycznej akcji');
                        close.addEventListener('click', () => {
                            rememberDismissed(token);
                            render();
                        });
                        heading.appendChild(close);
                    }

                    content.appendChild(heading);

                    const meta = document.createElement('div');
                    meta.className = 'automation-activity-meta';

                    const order = document.createElement('a');
                    order.className = 'automation-activity-order';
                    order.href = activity.order_url;
                    order.textContent = activity.order_label;
                    meta.appendChild(order);

                    const status = document.createElement('span');
                    status.className = 'automation-activity-status';
                    status.textContent = activity.status_label;
                    meta.appendChild(status);

                    if (activity.progress?.total > 1) {
                        const progress = document.createElement('span');
                        progress.className = 'automation-activity-progress';
                        progress.textContent = `Krok ${activity.progress.current} z ${activity.progress.total}`;
                        meta.appendChild(progress);
                    }

                    content.appendChild(meta);

                    const message = document.createElement('div');
                    message.className = 'automation-activity-message';
                    message.textContent = activity.message;
                    content.appendChild(message);

                    if (activity.details) {
                        const details = document.createElement('div');
                        details.className = 'automation-activity-details';
                        details.textContent = activity.details;
                        details.title = activity.details;
                        content.appendChild(details);
                    }

                    item.appendChild(content);
                    center.appendChild(item);

                    if (activity.tone === 'success' && !autoHideTimers.has(token)) {
                        const timer = window.setTimeout(() => {
                            rememberDismissed(token);
                            autoHideTimers.delete(token);
                            render();
                        }, 7000);

                        autoHideTimers.set(token, timer);
                    }
                });
            };

            const hasActiveActivity = () => activities.some((activity) => (
                activity.status === 'queued' || activity.status === 'running'
            ));

            const nextRefreshDelay = () => {
                if (hasActiveActivity()) {
                    return 1500;
                }

                if (Date.now() < fastPollingUntil) {
                    return 2000;
                }

                return 10000;
            };

            const scheduleRefresh = (delay = nextRefreshDelay()) => {
                window.clearTimeout(refreshTimer);
                refreshTimer = window.setTimeout(refresh, delay);
            };

            const refresh = async () => {
                if (document.hidden) {
                    scheduleRefresh(10000);
                    return;
                }

                if (
                    loading
                    || document.querySelector('[data-orders-page].is-loading')
                ) {
                    scheduleRefresh(1200);
                    return;
                }

                loading = true;

                try {
                    const response = await fetch(endpoint, {
                        headers: {
                            'Accept': 'application/json',
                            'X-Requested-With': 'XMLHttpRequest',
                        },
                        cache: 'no-store',
                    });

                    if (!response.ok) {
                        return;
                    }

                    const payload = await response.json();
                    const incoming = Array.isArray(payload.activities) ? payload.activities : [];
                    const incomingIds = new Set(incoming.map((activity) => activity.id));
                    const retainedErrors = activities.filter((activity) => (
                        activity.tone === 'error'
                        && !dismissed.has(activityToken(activity))
                        && !incomingIds.has(activity.id)
                    ));

                    notifyFinishedAutomations(incoming);
                    activities = [...incoming, ...retainedErrors];
                    render();
                } catch (error) {
                    // A temporary polling error must not interrupt work in the OMS.
                } finally {
                    loading = false;
                    scheduleRefresh();
                }
            };

            const wakeActivityPolling = () => {
                fastPollingUntil = Date.now() + 15000;
                scheduleRefresh(0);
            };

            document.addEventListener('nexoms:automation-wake', wakeActivityPolling);
            scheduleRefresh(1500);
            document.addEventListener('visibilitychange', () => {
                if (!document.hidden) {
                    wakeActivityPolling();
                }
            });
            window.addEventListener('pagehide', () => window.clearTimeout(refreshTimer), { once: true });
        });
    </script>
</body>
</html>
