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
    <div class="products-toolbar">
        <h1>Produkty</h1>
        <a class="products-add-button" href="{{ route('products.create') }}">
            <i class="bi bi-plus-lg" aria-hidden="true"></i>
            <span>Dodaj produkt</span>
        </a>
    </div>

    <form class="products-filters" method="GET" action="{{ route('products.index') }}">
        <input type="hidden" name="per_page" value="{{ $perPage }}">
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
            <a class="btn btn-outline-secondary" href="{{ route('products.index', ['per_page' => $perPage]) }}">
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
                <span>Produktów na stronę</span>
                <strong>{{ $perPage === 'all' ? 'Wszystkie' : $perPage }}</strong>
                <i class="bi bi-chevron-down" aria-hidden="true"></i>
            </button>
            <div class="dropdown-menu dropdown-menu-end products-page-size-menu">
                <div class="products-page-size-title">Produktów na stronę</div>
                <div class="products-page-size-grid">
                    @foreach (['25', '50', '100', '200', '500', 'all'] as $option)
                        <a
                            class="btn {{ $perPage === $option ? 'btn-primary' : 'btn-outline-secondary' }} products-page-size-option"
                            href="{{ route('products.index', ['q' => $search, 'status' => $status, 'per_page' => $option]) }}"
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
                        <th style="width: 32%;">Produkt</th>
                        <th style="width: 18%;">Stan</th>
                        <th style="width: 14%;">Cena brutto</th>
                        <th style="width: 8%;">VAT</th>
                        <th style="width: 16%;">Wysyłka</th>
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
                                        <a class="product-name" href="{{ route('products.edit', $product) }}">{{ $product->name }}</a>
                                        <div class="product-meta">
                                            ID {{ $product->getKey() }}
                                            @if ($product->ean)
                                                <span aria-hidden="true">|</span> EAN {{ $product->ean }}
                                            @endif
                                        </div>
                                    </div>
                                </div>
                            </td>
                            <td>
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
                            </td>
                            <td class="product-price">{{ $formatDecimal($product->price_gross, 2) }} {{ $product->currency }}</td>
                            <td>{{ $formatDecimal($product->vat_rate) }}%</td>
                            <td>
                                <div>{{ $product->package_weight_kg !== null ? $formatDecimal($product->package_weight_kg).' kg' : '—' }}</div>
                                @if ($product->package_length_cm !== null)
                                    <div class="product-meta">
                                        {{ $formatDecimal($product->package_length_cm) }} ×
                                        {{ $formatDecimal($product->package_width_cm) }} ×
                                        {{ $formatDecimal($product->package_height_cm) }} cm
                                    </div>
                                @endif
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
                                        aria-label="Operacje dla produktu {{ $product->name }}"
                                        title="Operacje"
                                    >
                                        <i class="bi bi-three-dots-vertical" aria-hidden="true"></i>
                                    </button>
                                    <div class="dropdown-menu product-actions-menu" data-product-actions-menu hidden>
                                        <a class="dropdown-item" href="{{ route('products.edit', $product) }}">
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
                            <td colspan="7" class="products-empty">
                                <i class="bi bi-box-seam d-block fs-3 mb-2" aria-hidden="true"></i>
                                Brak produktów spełniających wybrane kryteria.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="products-list-footer">
            <span class="products-count">
                {{ $products->total() }} {{ $products->total() === 1 ? 'produkt' : 'produktów' }}
            </span>
            {{ $products->onEachSide(1)->links() }}
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
