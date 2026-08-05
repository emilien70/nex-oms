@once
    <style>
        .invoice-module-tabs {
            align-items: end;
            border-bottom: 1px solid #dfe4ea;
            display: flex;
            gap: 24px;
            margin-bottom: 16px;
            overflow-x: auto;
        }

        .invoice-module-tab {
            border-bottom: 2px solid transparent;
            color: #64748b;
            display: inline-flex;
            font-size: 13px;
            padding: 10px 2px 9px;
            text-decoration: none;
            white-space: nowrap;
        }

        .invoice-module-tab:hover {
            color: #0d6efd;
        }

        .invoice-module-tab.active {
            border-bottom-color: #0d6efd;
            color: #111827;
            font-weight: 600;
        }

        .invoice-module-tab.disabled {
            color: #9ca3af;
            cursor: default;
        }
    </style>
@endonce

<nav class="invoice-module-tabs" aria-label="Nawigacja modułu faktur">
    <a class="invoice-module-tab {{ request()->routeIs('invoices.index') ? 'active' : '' }}" href="{{ route('invoices.index') }}">Faktury</a>
    <a class="invoice-module-tab {{ request()->routeIs('invoices.proformas.*') ? 'active' : '' }}" href="{{ route('invoices.proformas.index') }}">Faktury pro forma</a>
    <span class="invoice-module-tab disabled" aria-disabled="true">Korekty</span>
    <span class="invoice-module-tab disabled" aria-disabled="true">Rejestr sprzedaży</span>
    <a class="invoice-module-tab {{ request()->routeIs('invoices.series.*') ? 'active' : '' }}" href="{{ route('invoices.series.index') }}">Ustawienia</a>
</nav>
