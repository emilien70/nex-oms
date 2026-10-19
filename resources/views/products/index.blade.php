@extends('layouts.app')

@section('title', 'Produkty - NEX-OMS')

@section('content')
<style>
    .products-page {
        color: #1f2937;
        max-width: 100%;
        min-width: 0;
        overflow-x: hidden;
    }

    .products-tabs {
        border-bottom: 1px solid #d5dde6;
        display: flex;
        gap: 28px;
        margin-bottom: 20px;
    }

    .products-tab {
        border-bottom: 2px solid transparent;
        color: #43505e;
        font-size: 14px;
        padding: 10px 2px 11px;
        text-decoration: none;
    }

    .products-tab:hover,
    .products-tab:focus {
        color: #0b66c3;
    }

    .products-tab.is-active {
        border-bottom-color: #0d6efd;
        color: #0b5ed7;
        font-weight: 650;
    }

    .products-toolbar {
        align-items: center;
        display: flex;
        gap: 16px;
        justify-content: space-between;
        margin-bottom: 20px;
    }

    .products-toolbar h1 {
        font-size: 24px;
        font-weight: 650;
        letter-spacing: 0;
        margin: 0;
    }

    .products-add-button {
        align-items: center;
        background: #167445;
        border: 1px solid #167445;
        border-radius: 6px;
        color: #fff;
        display: inline-flex;
        font-weight: 600;
        gap: 8px;
        min-height: 40px;
        padding: 8px 15px;
        text-decoration: none;
    }

    .products-add-button:hover,
    .products-add-button:focus {
        background: #115c37;
        border-color: #115c37;
        color: #fff;
    }

    .products-filters {
        align-items: end;
        background: #f8fafc;
        border: 1px solid #d9e0e7;
        border-radius: 6px;
        display: grid;
        gap: 12px;
        grid-template-columns: minmax(240px, 1fr) minmax(170px, 220px) auto;
        margin-bottom: 16px;
        padding: 14px;
    }

    .products-filter-label {
        color: #52606d;
        display: block;
        font-size: 12px;
        font-weight: 600;
        margin-bottom: 5px;
    }

    .products-filter-actions {
        display: flex;
        gap: 8px;
    }

    .products-filter-actions .btn {
        white-space: nowrap;
    }

    .products-list-toolbar {
        align-items: center;
        display: flex;
        justify-content: flex-end;
        margin: -2px 0 12px;
    }

    .products-page-size-button {
        align-items: center;
        background: #fff;
        border: 1px solid #cfd7e1;
        border-radius: 5px;
        color: #263241;
        display: inline-flex;
        font-size: 13px;
        gap: 8px;
        min-height: 38px;
        padding: 7px 11px;
    }

    .products-page-size-button:hover,
    .products-page-size-button:focus {
        background: #f8fafc;
        border-color: #8fa1b5;
        color: #111827;
    }

    .products-page-size-menu {
        min-width: 286px;
        padding: 14px;
    }

    .products-page-size-title {
        font-size: 13px;
        font-weight: 650;
        margin-bottom: 10px;
        text-align: center;
    }

    .products-page-size-grid {
        display: grid;
        gap: 8px;
        grid-template-columns: repeat(3, minmax(0, 1fr));
    }

    .products-page-size-option {
        border-radius: 4px;
        font-size: 13px;
        min-height: 36px;
    }

    .products-list-shell {
        background: #fff;
        border: 1px solid #d9e0e7;
        border-radius: 6px;
        max-width: 100%;
        min-width: 0;
        overflow: hidden;
    }

    .products-table-scroll {
        max-width: 100%;
        overflow-x: auto;
        overscroll-behavior-inline: contain;
    }

    .products-table {
        margin: 0;
        min-width: 860px;
        table-layout: fixed;
    }

    .products-table thead th {
        background: #f4f6f8;
        border-bottom: 1px solid #ccd5df;
        color: #52606d;
        font-size: 11px;
        font-weight: 700;
        letter-spacing: 0;
        padding: 10px 12px;
        text-transform: uppercase;
        vertical-align: middle;
    }

    .products-table tbody td {
        border-color: #e3e8ee;
        padding: 8px 12px;
        vertical-align: middle;
    }

    .product-main-cell {
        align-items: center;
        display: flex;
        gap: 10px;
        min-width: 0;
    }

    .product-thumbnail,
    .product-thumbnail-placeholder {
        align-items: center;
        background: #f3f5f7;
        border: 1px solid #d9e0e7;
        border-radius: 4px;
        display: flex;
        flex: 0 0 52px;
        height: 52px;
        justify-content: center;
        object-fit: cover;
        width: 52px;
    }

    .product-thumbnail-placeholder {
        color: #8b98a7;
        font-size: 20px;
    }

    .product-name {
        color: #1f2937;
        display: block;
        font-size: 14px;
        font-weight: 650;
        overflow: hidden;
        text-decoration: none;
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    .product-name:hover,
    .product-name:focus {
        color: #0b66c3;
        text-decoration: underline;
    }

    .product-meta {
        color: #6b7785;
        font-size: 12px;
        margin-top: 3px;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    .product-stock {
        font-variant-numeric: tabular-nums;
        font-weight: 650;
    }

    .product-stock.is-negative {
        color: #b42318;
    }

    .product-stock-trigger {
        align-items: center;
        background: #fff;
        border: 1px solid transparent;
        border-radius: 4px;
        color: #1f2937;
        display: inline-flex;
        gap: 2px;
        min-height: 34px;
        padding: 4px 6px 4px 2px;
        white-space: nowrap;
    }

    .product-stock-trigger:hover,
    .product-stock-trigger:focus {
        border-color: #0d6efd;
        color: #0b5ed7;
        outline: 0;
    }

    .product-stock-handle {
        color: #0d6efd;
        font-size: 16px;
    }

    .product-stock-form {
        align-items: start;
        background: #fff;
        border: 1px solid #d9e0e7;
        border-radius: 5px;
        box-shadow: 0 5px 14px rgba(31, 41, 55, .14);
        display: grid;
        gap: 5px;
        grid-template-columns: minmax(68px, 1fr) 34px 34px;
        max-width: 190px;
        min-width: 154px;
        padding: 7px;
    }

    .product-stock-form[hidden] {
        display: none;
    }

    .product-stock-input {
        font-variant-numeric: tabular-nums;
        min-width: 0;
    }

    .product-stock-form .btn {
        align-items: center;
        display: inline-flex;
        height: 34px;
        justify-content: center;
        padding: 0;
        width: 34px;
    }

    .product-stock-error {
        color: #b42318;
        font-size: 11px;
        grid-column: 1 / -1;
        line-height: 1.3;
    }

    .product-stock-error[hidden] {
        display: none;
    }

    .product-price {
        font-variant-numeric: tabular-nums;
        font-weight: 650;
        white-space: nowrap;
    }

    .product-integrations-list {
        align-items: center;
        display: flex;
        flex-wrap: wrap;
        gap: 6px;
    }

    .product-integration-link {
        align-items: center;
        background: #f8fbff;
        border: 1px solid #b9d5f1;
        border-radius: 5px;
        color: #0b5fae;
        display: inline-flex;
        font-size: 12px;
        gap: 5px;
        min-height: 32px;
        padding: 5px 8px;
    }

    .product-integration-link:hover,
    .product-integration-link:focus {
        background: #eaf4ff;
        border-color: #5b9bd5;
        color: #084b89;
        outline: 0;
    }

    .product-integration-count {
        align-items: center;
        background: #0d6efd;
        border-radius: 8px;
        color: #fff;
        display: inline-flex;
        font-size: 10px;
        font-variant-numeric: tabular-nums;
        font-weight: 700;
        justify-content: center;
        line-height: 1;
        min-height: 18px;
        min-width: 18px;
        padding: 3px 5px;
    }

    .product-integration-add {
        align-items: center;
        background: #fff;
        border: 1px solid #c9d2dc;
        border-radius: 50%;
        color: #52606d;
        display: inline-flex;
        flex: 0 0 30px;
        height: 30px;
        justify-content: center;
        padding: 0;
        width: 30px;
    }

    .product-integration-add:hover,
    .product-integration-add:focus {
        border-color: #0d6efd;
        color: #0d6efd;
        outline: 0;
    }

    .product-integration-choice {
        align-items: center;
        background: #fff;
        border: 1px solid #ccd6e0;
        border-radius: 5px;
        color: #1f2937;
        display: flex;
        font-weight: 600;
        gap: 10px;
        justify-content: space-between;
        min-height: 46px;
        padding: 10px 12px;
        text-align: left;
        width: 100%;
    }

    .product-integration-choice:hover,
    .product-integration-choice:focus {
        background: #f7fbff;
        border-color: #0d6efd;
        color: #0b5ed7;
        outline: 0;
    }

    .product-integration-offers {
        border-bottom: 1px solid #d8e0e8;
        border-top: 1px solid #d8e0e8;
    }

    .product-integration-offer-row {
        align-items: center;
        display: flex;
        gap: 12px;
        justify-content: space-between;
        min-height: 48px;
        padding: 8px 2px;
    }

    .product-integration-offer-row + .product-integration-offer-row {
        border-top: 1px solid #e7ebf0;
    }

    .product-integration-offer-link {
        font-variant-numeric: tabular-nums;
        font-weight: 650;
        text-decoration: none;
    }

    .product-integration-offer-link:hover,
    .product-integration-offer-link:focus {
        text-decoration: underline;
    }

    .product-integration-context {
        background: #f7f9fb;
        border: 1px solid #dce3ea;
        border-radius: 5px;
        display: grid;
        gap: 8px;
        grid-template-columns: minmax(110px, 145px) 1fr;
        margin-bottom: 16px;
        padding: 12px;
    }

    .product-integration-context dt,
    .product-integration-context dd {
        margin: 0;
    }

    .product-integration-context dt {
        color: #657180;
        font-size: 12px;
        font-weight: 600;
    }

    .product-integration-context dd {
        color: #1f2937;
        font-size: 13px;
        font-weight: 600;
        min-width: 0;
        overflow-wrap: anywhere;
    }

    .product-status {
        border-radius: 999px;
        display: inline-flex;
        font-size: 11px;
        font-weight: 650;
        padding: 4px 8px;
    }

    .product-status.is-active {
        background: #e8f5ee;
        color: #11623a;
    }

    .product-status.is-inactive {
        background: #eef1f4;
        color: #5d6875;
    }

    .product-actions-button {
        align-items: center;
        background: #fff;
        border: 1px solid #d4dbe3;
        border-radius: 50%;
        color: #44505e;
        display: inline-flex;
        height: 34px;
        justify-content: center;
        padding: 0;
        width: 34px;
    }

    .product-actions-button:hover,
    .product-actions-button:focus {
        background: #f3f5f7;
        color: #111827;
    }

    .product-actions-menu {
        min-width: 170px;
        z-index: 1090;
    }

    .product-actions-menu[hidden] {
        display: none !important;
    }

    .products-empty {
        color: #657180;
        padding: 52px 20px;
        text-align: center;
    }

    .products-list-footer {
        align-items: center;
        border-top: 1px solid #e3e8ee;
        display: flex;
        gap: 16px;
        justify-content: space-between;
        padding: 12px 14px;
    }

    .products-count {
        color: #657180;
        font-size: 13px;
    }

    .products-list-footer nav {
        margin-left: auto;
    }

    @media (max-width: 760px) {
        .products-toolbar {
            align-items: stretch;
            flex-direction: column;
        }

        .products-add-button {
            justify-content: center;
        }

        .products-filters {
            grid-template-columns: 1fr;
        }

        .products-filters .btn {
            width: 100%;
        }

        .products-list-toolbar {
            justify-content: stretch;
        }

        .products-list-toolbar .dropdown,
        .products-page-size-button {
            width: 100%;
        }

        .products-page-size-button {
            justify-content: space-between;
        }
    }
</style>

@php
    $formatDecimal = static function (?string $value, int $minimumScale = 0): string {
        if ($value === null) {
            return '—';
        }

        $formatted = rtrim(rtrim($value, '0'), '.');
        if ($formatted === '' || $formatted === '-') {
            $formatted .= '0';
        }

        if ($minimumScale > 0) {
            [$whole, $fraction] = array_pad(explode('.', $formatted, 2), 2, '');
            $formatted = $whole.'.'.str_pad($fraction, $minimumScale, '0');
        }

        return str_replace('.', ',', $formatted);
    };
@endphp

<div class="products-page">
    <nav class="products-tabs" aria-label="Rodzaj pozycji katalogowej">
        <a
            class="products-tab {{ $activeTab === 'products' ? 'is-active' : '' }}"
            href="{{ route('products.index', ['tab' => 'products', 'status' => $status, 'per_page' => $perPage]) }}"
            @if ($activeTab === 'products') aria-current="page" @endif
        >
            Produkty
        </a>
        <a
            class="products-tab {{ $activeTab === 'bundles' ? 'is-active' : '' }}"
            href="{{ route('products.index', ['tab' => 'bundles', 'status' => $status, 'per_page' => $perPage]) }}"
            @if ($activeTab === 'bundles') aria-current="page" @endif
        >
            Zestawy
        </a>
    </nav>

    <div class="products-toolbar">
        <h1>{{ $activeTab === 'bundles' ? 'Zestawy' : 'Produkty' }}</h1>
        <a class="products-add-button" href="{{ $activeTab === 'bundles' ? route('product-bundles.create') : route('products.create') }}">
            <i class="bi bi-plus-lg" aria-hidden="true"></i>
            <span>{{ $activeTab === 'bundles' ? 'Dodaj zestaw' : 'Dodaj produkt' }}</span>
        </a>
    </div>

    <form class="products-filters" method="GET" action="{{ route('products.index') }}">
        <input type="hidden" name="per_page" value="{{ $perPage }}">
        <input type="hidden" name="tab" value="{{ $activeTab }}">
        <div>
            <label class="products-filter-label" for="products-search">Szukaj</label>
            <input
                class="form-control"
                id="products-search"
                name="q"
                type="search"
                value="{{ $search }}"
                placeholder="Nazwa, ID lub EAN"
            >
        </div>
        <div>
            <label class="products-filter-label" for="products-status">Widoczność</label>
            <select class="form-select" id="products-status" name="status">
                <option value="active" @selected($status === 'active')>Aktywne</option>
                <option value="inactive" @selected($status === 'inactive')>Ukryte</option>
                <option value="all" @selected($status === 'all')>Wszystkie</option>
            </select>
        </div>
        <div class="products-filter-actions">
            <button class="btn btn-primary" type="submit">
                <i class="bi bi-search" aria-hidden="true"></i>
                Filtruj
            </button>
            <a class="btn btn-outline-secondary" href="{{ route('products.index', ['per_page' => $perPage, 'tab' => $activeTab]) }}">
                <i class="bi bi-x-circle" aria-hidden="true"></i>
                Wyczyść filtry
            </a>
        </div>
    </form>

    <div class="products-list-toolbar">
        <div class="dropdown">
            <button
                class="products-page-size-button"
                type="button"
                data-bs-toggle="dropdown"
                aria-expanded="false"
            >
                <span>{{ $activeTab === 'bundles' ? 'Zestawów' : 'Produktów' }} na stronę</span>
                <strong>{{ $perPage === 'all' ? 'Wszystkie' : $perPage }}</strong>
                <i class="bi bi-chevron-down" aria-hidden="true"></i>
            </button>
            <div class="dropdown-menu dropdown-menu-end products-page-size-menu">
                <div class="products-page-size-title">{{ $activeTab === 'bundles' ? 'Zestawów' : 'Produktów' }} na stronę</div>
                <div class="products-page-size-grid">
                    @foreach (['25', '50', '100', '200', '500', 'all'] as $option)
                        <a
                            class="btn {{ $perPage === $option ? 'btn-primary' : 'btn-outline-secondary' }} products-page-size-option"
                            href="{{ route('products.index', ['q' => $search, 'status' => $status, 'per_page' => $option, 'tab' => $activeTab]) }}"
                            @if ($perPage === $option) aria-current="page" @endif
                        >
                            {{ $option === 'all' ? 'Wszystkie' : $option }}
                        </a>
                    @endforeach
                </div>
            </div>
        </div>
    </div>

    <div class="products-list-shell">
        <div class="products-table-scroll">
            <table class="table products-table align-middle">
                <thead>
                    <tr>
                        <th style="width: 34%;">{{ $activeTab === 'bundles' ? 'Zestaw' : 'Produkt' }}</th>
                        <th style="width: 16%;">Stan</th>
                        <th style="width: 14%;">Cena brutto</th>
                        <th style="width: 24%;">Integracje</th>
                        <th style="width: 8%;">Status</th>
                        <th class="text-end" style="width: 4%;" aria-label="Akcje"></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($products as $product)
                        <tr data-product-row>
                            <td>
                                <div class="product-main-cell">
                                    @if ($product->thumbnail_path)
                                        <img
                                            class="product-thumbnail"
                                            src="{{ route('products.image', [$product, 'thumbnail']) }}"
                                            alt=""
                                            loading="lazy"
                                        >
                                    @else
                                        <span class="product-thumbnail-placeholder" aria-hidden="true">
                                            <i class="bi bi-image"></i>
                                        </span>
                                    @endif
                                    <div class="min-w-0">
                                        <a class="product-name" href="{{ $product->isBundle() ? route('product-bundles.edit', $product) : route('products.edit', $product) }}">{{ $product->name }}</a>
                                        <div class="product-meta">
                                            ID {{ $product->getKey() }}
                                            @if ($product->ean)
                                                <span aria-hidden="true">|</span> EAN {{ $product->ean }}
                                            @endif
                                            @if ($product->isBundle())
                                                <span aria-hidden="true">|</span>
                                                {{ $product->bundleComponents->count() }} składniki
                                            @endif
                                        </div>
                                    </div>
                                </div>
                            </td>
                            <td>
                                @if ($product->isBundle())
                                    <span
                                        class="product-stock"
                                        title="Stan zestawu wynika ze stanów i ilości jego składników"
                                    >
                                        {{ $product->availableStockQuantity() }} szt.
                                    </span>
                                    <div class="product-meta">Ze składników</div>
                                @else
                                    <div data-stock-editor data-stock-value="{{ $product->stock_quantity }}">
                                    <button
                                        class="product-stock-trigger"
                                        type="button"
                                        data-stock-trigger
                                        aria-expanded="false"
                                        aria-controls="product-stock-form-{{ $product->getKey() }}"
                                        title="Edytuj stan magazynowy"
                                    >
                                        <i class="bi bi-grip-vertical product-stock-handle" aria-hidden="true"></i>
                                        <span class="product-stock {{ $product->stock_quantity < 0 ? 'is-negative' : '' }}" data-stock-display>
                                            <span data-stock-number>{{ $product->stock_quantity }}</span> szt.
                                        </span>
                                        <i
                                            class="bi bi-exclamation-triangle ms-1 text-danger"
                                            title="Ujemny stan magazynowy"
                                            aria-label="Ujemny stan magazynowy"
                                            data-stock-warning
                                            @if ($product->stock_quantity >= 0) hidden @endif
                                        ></i>
                                    </button>

                                    <form
                                        class="product-stock-form"
                                        id="product-stock-form-{{ $product->getKey() }}"
                                        method="POST"
                                        action="{{ route('products.stock', $product) }}"
                                        data-stock-form
                                        hidden
                                    >
                                        @csrf
                                        @method('PATCH')
                                        <label class="visually-hidden" for="product-stock-{{ $product->getKey() }}">Stan magazynowy produktu {{ $product->name }}</label>
                                        <input
                                            class="form-control form-control-sm product-stock-input"
                                            id="product-stock-{{ $product->getKey() }}"
                                            name="stock_quantity"
                                            type="number"
                                            step="1"
                                            min="-2147483648"
                                            max="2147483647"
                                            value="{{ $product->stock_quantity }}"
                                            data-stock-input
                                            required
                                        >
                                        <button class="btn btn-outline-success" type="submit" title="Zapisz stan" data-stock-save>
                                            <i class="bi bi-check-lg" aria-hidden="true" data-stock-save-icon></i>
                                            <span class="visually-hidden">Zapisz stan</span>
                                        </button>
                                        <button class="btn btn-outline-secondary" type="button" title="Anuluj" data-stock-cancel>
                                            <i class="bi bi-x-lg" aria-hidden="true"></i>
                                            <span class="visually-hidden">Anuluj</span>
                                        </button>
                                        <div class="product-stock-error" data-stock-error hidden></div>
                                    </form>
                                    <div class="visually-hidden" aria-live="polite" data-stock-status></div>
                                    </div>
                                @endif
                            </td>
                            <td class="product-price">{{ $formatDecimal($product->price_gross, 2) }} {{ $product->currency }}</td>
                            <td
                                data-product-integrations
                                data-product-id="{{ $product->getKey() }}"
                                data-product-name="{{ $product->name }}"
                            >
                                @include('products.partials.integration-links', ['product' => $product])
                            </td>
                            <td>
                                <span class="product-status {{ $product->is_active ? 'is-active' : 'is-inactive' }}">
                                    {{ $product->is_active ? 'Aktywny' : 'Ukryty' }}
                                </span>
                            </td>
                            <td class="text-end">
                                <div data-product-actions>
                                    <button
                                        class="product-actions-button"
                                        type="button"
                                        data-product-actions-toggle
                                        aria-expanded="false"
                                        aria-label="Operacje dla {{ $product->isBundle() ? 'zestawu' : 'produktu' }} {{ $product->name }}"
                                        title="Operacje"
                                    >
                                        <i class="bi bi-three-dots-vertical" aria-hidden="true"></i>
                                    </button>
                                    <div class="dropdown-menu product-actions-menu" data-product-actions-menu hidden>
                                        <a class="dropdown-item" href="{{ $product->isBundle() ? route('product-bundles.edit', $product) : route('products.edit', $product) }}">
                                            <i class="bi bi-pencil me-2" aria-hidden="true"></i>Edytuj
                                        </a>
                                        <form method="POST" action="{{ route('products.active', $product) }}">
                                            @csrf
                                            @method('PATCH')
                                            <input type="hidden" name="is_active" value="{{ $product->is_active ? 0 : 1 }}">
                                            <button class="dropdown-item" type="submit">
                                                <i class="bi {{ $product->is_active ? 'bi-eye-slash' : 'bi-eye' }} me-2" aria-hidden="true"></i>
                                                {{ $product->is_active ? 'Ukryj' : 'Aktywuj' }}
                                            </button>
                                        </form>
                                    </div>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="products-empty">
                                <i class="bi bi-box-seam d-block fs-3 mb-2" aria-hidden="true"></i>
                                Brak {{ $activeTab === 'bundles' ? 'zestawów' : 'produktów' }} spełniających wybrane kryteria.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="products-list-footer">
            <span class="products-count">
                {{ $products->total() }}
                @if ($activeTab === 'bundles')
                    {{ $products->total() === 1 ? 'zestaw' : 'zestawów' }}
                @else
                    {{ $products->total() === 1 ? 'produkt' : 'produktów' }}
                @endif
            </span>
            {{ $products->onEachSide(1)->links() }}
        </div>
    </div>
</div>

<div
    class="modal fade"
    id="productIntegrationModal"
    tabindex="-1"
    aria-labelledby="productIntegrationModalLabel"
    aria-hidden="true"
    data-integration-store-url="{{ route('products.integrations.prestashop.store', ['__product__', '__account__']) }}"
    data-integration-update-url="{{ route('products.integrations.prestashop.update', ['__product__', '__account__', '__link__']) }}"
    data-integration-delete-url="{{ route('products.integrations.prestashop.destroy', ['__product__', '__account__', '__link__']) }}"
>
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title fs-6" id="productIntegrationModalLabel">Powiązania z integracją</h2>
                <button class="btn-close" type="button" data-bs-dismiss="modal" aria-label="Zamknij"></button>
            </div>
            <div class="modal-body">
                <div data-integration-choice-step>
                    <div class="fw-semibold mb-3">Wybierz integrację</div>
                    <div class="d-grid gap-2">
                        @forelse ($prestashopAccounts as $account)
                            <button
                                class="product-integration-choice"
                                type="button"
                                data-integration-choice
                                data-account-id="{{ $account->getKey() }}"
                                data-account-label="{{ $account->name }} (PrestaShop)"
                            >
                                <span>{{ $account->name }} (PrestaShop)</span>
                                <i class="bi bi-chevron-right" aria-hidden="true"></i>
                            </button>
                        @empty
                            <div class="text-muted small">Brak aktywnych integracji.</div>
                        @endforelse
                    </div>
                </div>

                <div data-integration-list-step hidden>
                    <div class="d-flex flex-wrap align-items-start justify-content-between gap-3 mb-3">
                        <div>
                            <div class="fw-semibold" data-integration-list-account></div>
                            <div class="text-muted small" data-integration-list-product></div>
                        </div>
                        <button class="btn btn-outline-primary btn-sm" type="button" data-integration-list-add>
                            <i class="bi bi-plus-lg" aria-hidden="true"></i>
                            Dodaj powiązanie
                        </button>
                    </div>

                    <div class="product-integration-offers" data-integration-list-items></div>
                    <div class="text-muted small py-3" data-integration-list-empty hidden>
                        Brak powiązanych ofert w tej integracji.
                    </div>
                    <div class="alert alert-success py-2 px-3 small mt-3 mb-0" role="status" data-integration-list-success hidden></div>
                </div>

                <form data-integration-form hidden novalidate>
                    <dl class="product-integration-context">
                        <dt>Pozycja OMS</dt>
                        <dd data-integration-product-name></dd>
                        <dt>Integracja</dt>
                        <dd data-integration-account-label></dd>
                        <dt>Oferta</dt>
                        <dd>
                            <a
                                href="#"
                                target="_blank"
                                rel="noopener noreferrer"
                                data-integration-offer
                                hidden
                            ></a>
                            <span data-integration-offer-empty>—</span>
                        </dd>
                    </dl>

                    <div class="mb-3">
                        <label class="form-label fw-semibold" for="prestashopExternalProductId">ID produktu w PrestaShop</label>
                        <input
                            class="form-control"
                            id="prestashopExternalProductId"
                            name="external_product_id"
                            type="number"
                            min="1"
                            step="1"
                            inputmode="numeric"
                            required
                            data-integration-external-id
                        >
                        <div class="invalid-feedback" data-integration-field-error></div>
                    </div>

                    <div class="alert alert-danger py-2 px-3 small" role="alert" data-integration-error hidden></div>
                    <div class="alert alert-success py-2 px-3 small" role="status" data-integration-success hidden></div>

                    <div class="d-flex flex-wrap justify-content-between gap-2">
                        <button class="btn btn-outline-secondary" type="button" data-integration-back>
                            <i class="bi bi-arrow-left" aria-hidden="true"></i>
                            <span data-integration-back-label>Wybierz inną integrację</span>
                        </button>
                        <div class="d-flex gap-2">
                            <button class="btn btn-outline-danger" type="button" data-integration-delete hidden>
                                <i class="bi bi-trash" aria-hidden="true"></i>
                                Usuń
                            </button>
                            <button class="btn btn-primary" type="submit" data-integration-save>
                                <i class="bi bi-check-lg" aria-hidden="true" data-integration-save-icon></i>
                                Zapisz powiązanie
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
    (() => {
        const editors = Array.from(document.querySelectorAll('[data-stock-editor]'));

        const closeEditor = (editor, restoreFocus = false) => {
            const trigger = editor.querySelector('[data-stock-trigger]');
            const form = editor.querySelector('[data-stock-form]');
            const input = editor.querySelector('[data-stock-input]');
            const error = editor.querySelector('[data-stock-error]');

            if (!trigger || !form || !input) {
                return;
            }

            input.value = editor.dataset.stockValue ?? '0';
            form.hidden = true;
            trigger.hidden = false;
            trigger.setAttribute('aria-expanded', 'false');

            if (error) {
                error.hidden = true;
                error.textContent = '';
            }

            if (restoreFocus) {
                trigger.focus();
            }
        };

        const openEditor = (editor) => {
            editors.forEach((candidate) => {
                if (candidate !== editor) {
                    closeEditor(candidate);
                }
            });

            const trigger = editor.querySelector('[data-stock-trigger]');
            const form = editor.querySelector('[data-stock-form]');
            const input = editor.querySelector('[data-stock-input]');

            if (!trigger || !form || !input) {
                return;
            }

            trigger.hidden = true;
            trigger.setAttribute('aria-expanded', 'true');
            form.hidden = false;
            input.value = editor.dataset.stockValue ?? '0';
            input.focus();
            input.select();
        };

        editors.forEach((editor) => {
            const trigger = editor.querySelector('[data-stock-trigger]');
            const form = editor.querySelector('[data-stock-form]');
            const input = editor.querySelector('[data-stock-input]');
            const cancel = editor.querySelector('[data-stock-cancel]');
            const save = editor.querySelector('[data-stock-save]');
            const saveIcon = editor.querySelector('[data-stock-save-icon]');
            const display = editor.querySelector('[data-stock-display]');
            const number = editor.querySelector('[data-stock-number]');
            const warning = editor.querySelector('[data-stock-warning]');
            const error = editor.querySelector('[data-stock-error]');
            const status = editor.querySelector('[data-stock-status]');

            trigger?.addEventListener('click', () => openEditor(editor));
            cancel?.addEventListener('click', () => closeEditor(editor, true));
            input?.addEventListener('keydown', (event) => {
                if (event.key === 'Escape') {
                    event.preventDefault();
                    closeEditor(editor, true);
                }
            });

            form?.addEventListener('submit', async (event) => {
                event.preventDefault();

                if (!input || !input.checkValidity()) {
                    input?.reportValidity();
                    return;
                }

                if (error) {
                    error.hidden = true;
                    error.textContent = '';
                }

                const formData = new FormData(form);

                input.disabled = true;
                if (save) {
                    save.disabled = true;
                }
                if (cancel) {
                    cancel.disabled = true;
                }
                if (saveIcon) {
                    saveIcon.className = 'spinner-border spinner-border-sm';
                }

                try {
                    const response = await fetch(form.action, {
                        method: 'POST',
                        headers: {
                            'Accept': 'application/json',
                            'X-Requested-With': 'XMLHttpRequest',
                        },
                        body: formData,
                    });
                    const payload = await response.json().catch(() => ({}));

                    if (!response.ok) {
                        const message = payload.errors?.stock_quantity?.[0]
                            ?? payload.message
                            ?? 'Nie udało się zapisać stanu magazynowego.';
                        throw new Error(message);
                    }

                    const stockQuantity = Number(payload.stock_quantity);
                    editor.dataset.stockValue = String(stockQuantity);

                    if (number) {
                        number.textContent = String(stockQuantity);
                    }
                    display?.classList.toggle('is-negative', stockQuantity < 0);
                    if (warning) {
                        warning.hidden = stockQuantity >= 0;
                    }
                    if (status) {
                        status.textContent = payload.message ?? 'Stan magazynowy został zapisany.';
                    }

                    closeEditor(editor, true);
                } catch (exception) {
                    if (error) {
                        error.textContent = exception instanceof Error
                            ? exception.message
                            : 'Nie udało się zapisać stanu magazynowego.';
                        error.hidden = false;
                    }
                    input.focus();
                } finally {
                    input.disabled = false;
                    if (save) {
                        save.disabled = false;
                    }
                    if (cancel) {
                        cancel.disabled = false;
                    }
                    if (saveIcon) {
                        saveIcon.className = 'bi bi-check-lg';
                    }
                }
            });
        });

        const integrationModalElement = document.getElementById('productIntegrationModal');
        const integrationChoiceStep = integrationModalElement?.querySelector('[data-integration-choice-step]');
        const integrationListStep = integrationModalElement?.querySelector('[data-integration-list-step]');
        const integrationListAccount = integrationModalElement?.querySelector('[data-integration-list-account]');
        const integrationListProduct = integrationModalElement?.querySelector('[data-integration-list-product]');
        const integrationListItems = integrationModalElement?.querySelector('[data-integration-list-items]');
        const integrationListEmpty = integrationModalElement?.querySelector('[data-integration-list-empty]');
        const integrationListSuccess = integrationModalElement?.querySelector('[data-integration-list-success]');
        const integrationListAdd = integrationModalElement?.querySelector('[data-integration-list-add]');
        const integrationForm = integrationModalElement?.querySelector('[data-integration-form]');
        const integrationProductName = integrationModalElement?.querySelector('[data-integration-product-name]');
        const integrationAccountLabel = integrationModalElement?.querySelector('[data-integration-account-label]');
        const integrationOffer = integrationModalElement?.querySelector('[data-integration-offer]');
        const integrationOfferEmpty = integrationModalElement?.querySelector('[data-integration-offer-empty]');
        const integrationExternalId = integrationModalElement?.querySelector('[data-integration-external-id]');
        const integrationFieldError = integrationModalElement?.querySelector('[data-integration-field-error]');
        const integrationError = integrationModalElement?.querySelector('[data-integration-error]');
        const integrationSuccess = integrationModalElement?.querySelector('[data-integration-success]');
        const integrationBack = integrationModalElement?.querySelector('[data-integration-back]');
        const integrationBackLabel = integrationModalElement?.querySelector('[data-integration-back-label]');
        const integrationDelete = integrationModalElement?.querySelector('[data-integration-delete]');
        const integrationSave = integrationModalElement?.querySelector('[data-integration-save]');
        const integrationSaveIcon = integrationModalElement?.querySelector('[data-integration-save-icon]');
        const getIntegrationModal = () => integrationModalElement && window.bootstrap
            ? window.bootstrap.Modal.getOrCreateInstance(integrationModalElement)
            : null;
        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content ?? '';
        const integrationStoreUrl = integrationModalElement?.dataset.integrationStoreUrl ?? '';
        const integrationUpdateUrl = integrationModalElement?.dataset.integrationUpdateUrl ?? '';
        const integrationDeleteUrl = integrationModalElement?.dataset.integrationDeleteUrl ?? '';
        let activeIntegrationCell = null;
        let activeIntegrationAccountId = null;
        let activeIntegrationAccountLabel = '';
        let activeIntegrationLinkId = null;
        let integrationFormReturnStep = 'choices';

        const integrationUrl = (template, productId, accountId, linkId = null) => template
            .replace('__product__', encodeURIComponent(productId))
            .replace('__account__', encodeURIComponent(accountId))
            .replace('__link__', encodeURIComponent(linkId ?? ''));

        const clearIntegrationError = () => {
            integrationExternalId?.classList.remove('is-invalid');

            if (integrationFieldError) {
                integrationFieldError.textContent = '';
            }

            if (integrationError) {
                integrationError.hidden = true;
                integrationError.textContent = '';
            }

            if (integrationSuccess) {
                integrationSuccess.hidden = true;
                integrationSuccess.textContent = '';
            }
        };

        const clearIntegrationListSuccess = () => {
            if (integrationListSuccess) {
                integrationListSuccess.hidden = true;
                integrationListSuccess.textContent = '';
            }
        };

        const showIntegrationOffer = (productId, offerUrl) => {
            const hasOffer = Boolean(productId && offerUrl);

            if (integrationOffer) {
                integrationOffer.hidden = !hasOffer;
                integrationOffer.textContent = hasOffer ? String(productId) : '';
                integrationOffer.href = hasOffer ? String(offerUrl) : '#';
            }
            if (integrationOfferEmpty) {
                integrationOfferEmpty.hidden = hasOffer;
            }
        };

        const setIntegrationBusy = (busy) => {
            if (integrationExternalId) {
                integrationExternalId.disabled = busy;
            }
            if (integrationBack) {
                integrationBack.disabled = busy;
            }
            if (integrationDelete) {
                integrationDelete.disabled = busy;
            }
            if (integrationSave) {
                integrationSave.disabled = busy;
            }
            if (integrationSaveIcon) {
                integrationSaveIcon.className = busy
                    ? 'spinner-border spinner-border-sm'
                    : 'bi bi-check-lg';
            }
        };

        const showIntegrationChoices = () => {
            clearIntegrationError();
            clearIntegrationListSuccess();
            activeIntegrationAccountId = null;
            activeIntegrationAccountLabel = '';
            activeIntegrationLinkId = null;

            if (integrationChoiceStep) {
                integrationChoiceStep.hidden = false;
            }
            if (integrationListStep) {
                integrationListStep.hidden = true;
            }
            if (integrationForm) {
                integrationForm.hidden = true;
            }
        };

        const integrationRecords = (accountId) => {
            const group = Array.from(
                activeIntegrationCell?.querySelectorAll('[data-integration-group]') ?? [],
            ).find((element) => element.dataset.accountId === String(accountId));

            return Array.from(group?.querySelectorAll('[data-integration-link-record]') ?? []);
        };

        const findIntegrationRecord = (accountId, linkId) => integrationRecords(accountId)
            .find((element) => element.dataset.linkId === String(linkId));

        const renderIntegrationList = () => {
            if (!integrationListItems || !activeIntegrationAccountId) {
                return;
            }

            integrationListItems.replaceChildren();
            const records = integrationRecords(activeIntegrationAccountId);

            records.forEach((record) => {
                const row = document.createElement('div');
                row.className = 'product-integration-offer-row';

                const link = document.createElement('a');
                link.className = 'product-integration-offer-link';
                link.href = record.dataset.offerUrl ?? '#';
                link.target = '_blank';
                link.rel = 'noopener noreferrer';
                link.textContent = `Oferta: ${record.dataset.externalProductId ?? ''}`;

                const edit = document.createElement('button');
                edit.className = 'btn btn-outline-secondary btn-sm';
                edit.type = 'button';
                edit.dataset.integrationListEdit = '';
                edit.dataset.linkId = record.dataset.linkId ?? '';
                edit.title = `Edytuj powiązanie oferty ${record.dataset.externalProductId ?? ''}`;
                edit.setAttribute('aria-label', edit.title);
                edit.innerHTML = '<i class="bi bi-pencil" aria-hidden="true"></i>';

                row.append(link, edit);
                integrationListItems.append(row);
            });

            if (integrationListEmpty) {
                integrationListEmpty.hidden = records.length > 0;
            }
        };

        const showIntegrationList = (accountId, accountLabel, message = '') => {
            if (!activeIntegrationCell || !integrationListStep) {
                return;
            }

            clearIntegrationError();
            clearIntegrationListSuccess();
            activeIntegrationAccountId = String(accountId);
            activeIntegrationAccountLabel = accountLabel;
            activeIntegrationLinkId = null;

            if (integrationListAccount) {
                integrationListAccount.textContent = accountLabel;
            }
            if (integrationListProduct) {
                integrationListProduct.textContent = activeIntegrationCell.dataset.productName ?? '';
            }
            if (message && integrationListSuccess) {
                integrationListSuccess.textContent = message;
                integrationListSuccess.hidden = false;
            }

            renderIntegrationList();

            if (integrationChoiceStep) {
                integrationChoiceStep.hidden = true;
            }
            integrationListStep.hidden = false;
            if (integrationForm) {
                integrationForm.hidden = true;
            }
        };

        const showIntegrationForm = (accountId, accountLabel, record = null, returnStep = 'choices') => {
            if (!activeIntegrationCell || !integrationForm || !integrationExternalId) {
                return;
            }

            clearIntegrationError();
            clearIntegrationListSuccess();
            activeIntegrationAccountId = String(accountId);
            activeIntegrationAccountLabel = accountLabel;
            activeIntegrationLinkId = record?.dataset.linkId ?? null;
            integrationFormReturnStep = returnStep;

            if (integrationProductName) {
                integrationProductName.textContent = activeIntegrationCell.dataset.productName ?? '';
            }
            if (integrationAccountLabel) {
                integrationAccountLabel.textContent = accountLabel;
            }

            integrationExternalId.value = record?.dataset.externalProductId ?? '';
            showIntegrationOffer(
                record?.dataset.externalProductId,
                record?.dataset.offerUrl,
            );
            if (integrationDelete) {
                integrationDelete.hidden = !activeIntegrationLinkId;
            }
            if (integrationBackLabel) {
                integrationBackLabel.textContent = returnStep === 'list'
                    ? 'Powrót do listy powiązań'
                    : 'Wybierz inną integrację';
            }
            if (integrationChoiceStep) {
                integrationChoiceStep.hidden = true;
            }
            if (integrationListStep) {
                integrationListStep.hidden = true;
            }
            integrationForm.hidden = false;

            window.setTimeout(() => {
                integrationExternalId.focus();
                integrationExternalId.select();
            }, 120);
        };

        document.addEventListener('click', (event) => {
            const addButton = event.target.closest('[data-integration-add]');
            const listButton = event.target.closest('[data-integration-list]');
            const editButton = event.target.closest('[data-integration-list-edit]');

            if (addButton) {
                activeIntegrationCell = addButton.closest('[data-product-integrations]');
                showIntegrationChoices();
                getIntegrationModal()?.show();
                return;
            }

            if (listButton) {
                activeIntegrationCell = listButton.closest('[data-product-integrations]');
                getIntegrationModal()?.show();
                showIntegrationList(
                    listButton.dataset.accountId,
                    listButton.dataset.accountLabel ?? 'PrestaShop',
                );
                return;
            }

            if (editButton) {
                const record = findIntegrationRecord(activeIntegrationAccountId, editButton.dataset.linkId);

                if (!record) {
                    return;
                }

                showIntegrationForm(
                    activeIntegrationAccountId,
                    activeIntegrationAccountLabel,
                    record,
                    'list',
                );
            }
        });

        integrationModalElement?.querySelectorAll('[data-integration-choice]').forEach((choice) => {
            choice.addEventListener('click', () => showIntegrationForm(
                choice.dataset.accountId,
                choice.dataset.accountLabel ?? 'PrestaShop',
                null,
                'choices',
            ));
        });

        integrationListAdd?.addEventListener('click', () => showIntegrationForm(
            activeIntegrationAccountId,
            activeIntegrationAccountLabel,
            null,
            'list',
        ));

        integrationBack?.addEventListener('click', () => {
            if (integrationFormReturnStep === 'list') {
                showIntegrationList(activeIntegrationAccountId, activeIntegrationAccountLabel);
                return;
            }

            showIntegrationChoices();
        });

        integrationForm?.addEventListener('submit', async (event) => {
            event.preventDefault();

            if (!activeIntegrationCell || !activeIntegrationAccountId || !integrationExternalId) {
                return;
            }

            if (!integrationExternalId.checkValidity()) {
                integrationExternalId.reportValidity();
                return;
            }

            clearIntegrationError();
            setIntegrationBusy(true);

            try {
                const isEditing = Boolean(activeIntegrationLinkId);
                const response = await fetch(integrationUrl(
                    isEditing ? integrationUpdateUrl : integrationStoreUrl,
                    activeIntegrationCell.dataset.productId,
                    activeIntegrationAccountId,
                    activeIntegrationLinkId,
                ), {
                    method: isEditing ? 'PATCH' : 'POST',
                    headers: {
                        'Accept': 'application/json',
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': csrfToken,
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    body: JSON.stringify({
                        external_product_id: integrationExternalId.value,
                    }),
                });
                const payload = await response.json().catch(() => ({}));

                if (!response.ok) {
                    const fieldMessage = payload.errors?.external_product_id?.[0];

                    if (fieldMessage && integrationFieldError) {
                        integrationExternalId.classList.add('is-invalid');
                        integrationFieldError.textContent = fieldMessage;
                    }

                    throw new Error(
                        fieldMessage
                        ?? payload.errors?.integration?.[0]
                        ?? payload.message
                        ?? 'Nie udało się zapisać powiązania.',
                    );
                }

                activeIntegrationCell.innerHTML = payload.html ?? activeIntegrationCell.innerHTML;
                activeIntegrationLinkId = payload.link?.id ?? activeIntegrationLinkId;
                const savedIntegration = findIntegrationRecord(
                    activeIntegrationAccountId,
                    activeIntegrationLinkId,
                );

                showIntegrationOffer(
                    savedIntegration?.dataset.externalProductId,
                    savedIntegration?.dataset.offerUrl,
                );
                if (integrationDelete) {
                    integrationDelete.hidden = !activeIntegrationLinkId;
                }
                if (integrationSuccess) {
                    integrationSuccess.textContent = payload.message ?? 'Powiązanie zostało zapisane.';
                    integrationSuccess.hidden = false;
                }
            } catch (exception) {
                if (integrationError) {
                    integrationError.textContent = exception instanceof Error
                        ? exception.message
                        : 'Nie udało się zapisać powiązania.';
                    integrationError.hidden = false;
                }
            } finally {
                setIntegrationBusy(false);
            }
        });

        integrationDelete?.addEventListener('click', async () => {
            if (
                !activeIntegrationCell
                || !activeIntegrationAccountId
                || !activeIntegrationLinkId
                || !window.confirm('Czy na pewno usunąć to powiązanie produktu?')
            ) {
                return;
            }

            clearIntegrationError();
            setIntegrationBusy(true);

            try {
                const response = await fetch(integrationUrl(
                    integrationDeleteUrl,
                    activeIntegrationCell.dataset.productId,
                    activeIntegrationAccountId,
                    activeIntegrationLinkId,
                ), {
                    method: 'DELETE',
                    headers: {
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': csrfToken,
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                });
                const payload = await response.json().catch(() => ({}));

                if (!response.ok) {
                    throw new Error(payload.message ?? 'Nie udało się usunąć powiązania.');
                }

                activeIntegrationCell.innerHTML = payload.html ?? activeIntegrationCell.innerHTML;
                showIntegrationList(
                    activeIntegrationAccountId,
                    activeIntegrationAccountLabel,
                    payload.message ?? 'Powiązanie zostało usunięte.',
                );
            } catch (exception) {
                if (integrationError) {
                    integrationError.textContent = exception instanceof Error
                        ? exception.message
                        : 'Nie udało się usunąć powiązania.';
                    integrationError.hidden = false;
                }
            } finally {
                setIntegrationBusy(false);
            }
        });

        integrationModalElement?.addEventListener('hidden.bs.modal', () => {
            activeIntegrationCell = null;
            showIntegrationChoices();
        });

        const actionDropdowns = Array.from(document.querySelectorAll('[data-product-actions]'));
        let openActionDropdown = null;

        const closeActionMenu = (dropdown, restoreFocus = false) => {
            if (!dropdown) {
                return;
            }

            const toggle = dropdown.querySelector('[data-product-actions-toggle]');
            const menu = document.body.querySelector(`[data-product-actions-menu][data-owner="${dropdown.dataset.actionOwner}"]`)
                ?? dropdown.querySelector('[data-product-actions-menu]');

            if (!toggle || !menu) {
                return;
            }

            menu.classList.remove('show');
            menu.hidden = true;
            menu.style.removeProperty('left');
            menu.style.removeProperty('top');
            menu.style.removeProperty('position');
            menu.style.removeProperty('visibility');
            menu.style.removeProperty('z-index');
            menu.removeAttribute('data-owner');
            dropdown.appendChild(menu);
            toggle.setAttribute('aria-expanded', 'false');

            if (openActionDropdown === dropdown) {
                openActionDropdown = null;
            }

            if (restoreFocus) {
                toggle.focus();
            }
        };

        const openActionMenu = (dropdown) => {
            if (openActionDropdown && openActionDropdown !== dropdown) {
                closeActionMenu(openActionDropdown);
            }

            const toggle = dropdown.querySelector('[data-product-actions-toggle]');
            const menu = dropdown.querySelector('[data-product-actions-menu]');

            if (!toggle || !menu) {
                return;
            }

            const owner = `product-actions-${Math.random().toString(36).slice(2)}`;
            dropdown.dataset.actionOwner = owner;
            menu.dataset.owner = owner;
            document.body.appendChild(menu);
            menu.hidden = false;
            menu.classList.add('show');
            menu.style.position = 'fixed';
            menu.style.visibility = 'hidden';
            menu.style.zIndex = '1090';

            const toggleRect = toggle.getBoundingClientRect();
            const menuRect = menu.getBoundingClientRect();
            const gap = 4;
            const margin = 8;
            const left = Math.max(
                margin,
                Math.min(toggleRect.right - menuRect.width, window.innerWidth - menuRect.width - margin),
            );
            let top = toggleRect.bottom + gap;

            if (top + menuRect.height > window.innerHeight - margin) {
                top = Math.max(margin, toggleRect.top - menuRect.height - gap);
            }

            menu.style.left = `${Math.round(left)}px`;
            menu.style.top = `${Math.round(top)}px`;
            menu.style.visibility = 'visible';
            toggle.setAttribute('aria-expanded', 'true');
            openActionDropdown = dropdown;
        };

        actionDropdowns.forEach((dropdown) => {
            const toggle = dropdown.querySelector('[data-product-actions-toggle]');

            toggle?.addEventListener('click', (event) => {
                event.stopPropagation();

                if (openActionDropdown === dropdown) {
                    closeActionMenu(dropdown, true);
                    return;
                }

                openActionMenu(dropdown);
            });
        });

        document.addEventListener('click', (event) => {
            if (!openActionDropdown) {
                return;
            }

            const owner = openActionDropdown.dataset.actionOwner;
            const menu = owner
                ? document.body.querySelector(`[data-product-actions-menu][data-owner="${owner}"]`)
                : null;

            if (menu?.contains(event.target)) {
                return;
            }

            closeActionMenu(openActionDropdown);
        });

        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape' && openActionDropdown) {
                event.preventDefault();
                closeActionMenu(openActionDropdown, true);
            }
        });

        window.addEventListener('resize', () => closeActionMenu(openActionDropdown));
        window.addEventListener('scroll', () => closeActionMenu(openActionDropdown));
    })();
</script>
@endsection
