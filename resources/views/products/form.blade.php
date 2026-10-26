@extends('layouts.app')

@php
    $isEdit = $product->exists;
    $isBundle = $isBundle ?? $product->isBundle();
    $itemLabel = $isBundle ? 'zestawu' : 'produktu';
    $itemLabelTitle = $isBundle ? 'Zestaw' : 'Produkt';
    $newItemLabel = $isBundle ? 'Nowy zestaw' : 'Nowy produkt';
    $storedVatRate = (string) ($product->vat_rate ?? '23.00');
    $standardVatRates = ['23.00', '8.00', '5.00', '0.00'];
    $defaultVatPreset = in_array($storedVatRate, $standardVatRates, true) ? $storedVatRate : 'custom';
    $vatPreset = old('vat_rate_preset', $defaultVatPreset);
    $customVatRate = old('custom_vat_rate', $defaultVatPreset === 'custom' ? $storedVatRate : '');
    $componentRows = old('components');
    if ($isBundle && ! is_array($componentRows)) {
        $componentRows = $product->bundleComponents
            ->map(fn ($component) => [
                'product_id' => $component->component_product_id,
                'quantity' => $component->quantity,
            ])
            ->values()
            ->all();
    }
    if ($isBundle && count($componentRows ?? []) < 2) {
        $componentRows = array_pad($componentRows ?? [], 2, ['product_id' => '', 'quantity' => 1]);
    }
@endphp

@section('title', ($isEdit ? 'Edycja '.$itemLabel : $newItemLabel).' - NEX-OMS')

@section('content')
<style>
    .product-form-page {
        color: #1f2937;
        margin: 0 auto;
        max-width: 1180px;
    }

    .product-form-header {
        align-items: center;
        display: flex;
        gap: 16px;
        justify-content: space-between;
        margin-bottom: 20px;
    }

    .product-form-header h1 {
        font-size: 24px;
        font-weight: 650;
        letter-spacing: 0;
        margin: 0;
    }

    .product-form-id {
        color: #657180;
        font-size: 13px;
        margin-top: 4px;
    }

    .product-form {
        background: #fff;
        border: 1px solid #d9e0e7;
        border-radius: 6px;
        overflow: hidden;
    }

    .product-form-section {
        border-bottom: 1px solid #e3e8ee;
        padding: 22px;
    }

    .product-form-section:last-of-type {
        border-bottom: 0;
    }

    .product-form-section h2 {
        font-size: 16px;
        font-weight: 650;
        letter-spacing: 0;
        margin: 0 0 18px;
    }

    .product-form-grid {
        display: grid;
        gap: 16px;
        grid-template-columns: repeat(12, minmax(0, 1fr));
    }

    .product-form-field {
        grid-column: span 6;
    }

    .product-form-field.is-full {
        grid-column: 1 / -1;
    }

    .product-form-field.is-third {
        grid-column: span 4;
    }

    .product-form-field.is-quarter {
        grid-column: span 3;
    }

    .product-form-label {
        color: #44505e;
        display: block;
        font-size: 13px;
        font-weight: 600;
        margin-bottom: 6px;
    }

    .product-image-layout {
        align-items: start;
        display: grid;
        gap: 20px;
        grid-template-columns: 160px minmax(0, 1fr);
    }

    .product-image-preview {
        align-items: center;
        background: #f4f6f8;
        border: 1px solid #d9e0e7;
        border-radius: 5px;
        display: flex;
        height: 160px;
        justify-content: center;
        overflow: hidden;
        width: 160px;
    }

    .product-image-preview img {
        height: 100%;
        object-fit: cover;
        width: 100%;
    }

    .product-image-preview i {
        color: #8b98a7;
        font-size: 40px;
    }

    .product-form-actions {
        align-items: center;
        background: #f8fafc;
        border-top: 1px solid #d9e0e7;
        display: flex;
        gap: 10px;
        justify-content: flex-end;
        padding: 16px 22px;
    }

    .product-form-actions .btn {
        min-width: 110px;
    }

    .product-custom-vat[hidden] {
        display: none !important;
    }

    .bundle-component-list {
        display: grid;
        gap: 10px;
    }

    .bundle-component-row {
        align-items: start;
        display: grid;
        gap: 10px;
        grid-template-columns: minmax(0, 1fr) minmax(110px, 150px) 38px;
    }

    .bundle-component-remove {
        align-items: center;
        display: inline-flex;
        height: 38px;
        justify-content: center;
        padding: 0;
        width: 38px;
    }

    .bundle-availability {
        color: #52606d;
        font-size: 13px;
        margin-bottom: 14px;
    }

    @media (max-width: 760px) {
        .product-form-header {
            align-items: flex-start;
            flex-direction: column;
        }

        .product-form-section {
            padding: 18px;
        }

        .product-form-field,
        .product-form-field.is-third,
        .product-form-field.is-quarter {
            grid-column: 1 / -1;
        }

        .product-image-layout {
            grid-template-columns: 1fr;
        }

        .product-form-actions {
            align-items: stretch;
            flex-direction: column-reverse;
        }

        .product-form-actions .btn {
            width: 100%;
        }

        .bundle-component-row {
            grid-template-columns: minmax(0, 1fr) 100px 38px;
        }
    }
</style>

<div class="product-form-page">
    <div class="product-form-header">
        <div>
            <h1>{{ $isEdit ? 'Edycja '.$itemLabel : $newItemLabel }}</h1>
            @if ($isEdit)
                <div class="product-form-id">ID {{ $product->getKey() }}</div>
            @endif
        </div>
        <a class="btn btn-outline-secondary" href="{{ route('products.index', ['status' => 'all', 'tab' => $isBundle ? 'bundles' : 'products']) }}">
            <i class="bi bi-arrow-left" aria-hidden="true"></i>
            {{ $isBundle ? 'Lista zestawów' : 'Lista produktów' }}
        </a>
    </div>

    <form
        class="product-form"
        method="POST"
        action="{{ $isBundle
            ? ($isEdit ? route('product-bundles.update', $product) : route('product-bundles.store'))
            : ($isEdit ? route('products.update', $product) : route('products.store')) }}"
        enctype="multipart/form-data"
        data-product-form
    >
        @csrf
        @if ($isEdit)
            @method('PATCH')
        @endif

        <section class="product-form-section">
            <h2>Podstawowe</h2>
            <div class="product-form-grid">
                <div class="product-form-field is-full">
                    <label class="product-form-label" for="product-name">Nazwa {{ $itemLabel }}</label>
                    <input
                        class="form-control @error('name') is-invalid @enderror"
                        id="product-name"
                        name="name"
                        type="text"
                        maxlength="255"
                        value="{{ old('name', $product->name) }}"
                        required
                        autofocus
                    >
                    @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>

                <div class="product-form-field">
                    <label class="product-form-label" for="product-ean">EAN</label>
                    <input
                        class="form-control @error('ean') is-invalid @enderror"
                        id="product-ean"
                        name="ean"
                        type="text"
                        inputmode="numeric"
                        maxlength="14"
                        value="{{ old('ean', $product->ean) }}"
                    >
                    @error('ean')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>

                <div class="product-form-field">
                    <input type="hidden" name="is_active" value="0">
                    <span class="product-form-label">Widoczność</span>
                    <div class="form-check form-switch pt-2">
                        <input
                            class="form-check-input"
                            id="product-is-active"
                            name="is_active"
                            type="checkbox"
                            value="1"
                            @checked((bool) old('is_active', $product->is_active ?? true))
                        >
                        <label class="form-check-label" for="product-is-active">{{ $itemLabelTitle }} aktywny</label>
                    </div>
                </div>
            </div>
        </section>

        <section class="product-form-section">
            <h2>Zdjęcie</h2>
            <div class="product-image-layout">
                <div class="product-image-preview" data-product-image-preview>
                    @if ($isEdit && $product->thumbnail_path)
                        <img src="{{ route('products.image', [$product, 'thumbnail']) }}" alt="Miniatura {{ $itemLabel }}">
                    @else
                        <i class="bi bi-image" aria-hidden="true"></i>
                    @endif
                </div>
                <div>
                    <label class="product-form-label" for="product-image">Plik zdjęcia</label>
                    <input
                        class="form-control @error('image') is-invalid @enderror"
                        id="product-image"
                        name="image"
                        type="file"
                        accept="image/jpeg,image/png,image/webp"
                        data-product-image-input
                    >
                    @error('image')<div class="invalid-feedback">{{ $message }}</div>@enderror

                    <input type="hidden" name="remove_image" value="0">
                    @if ($isEdit && $product->image_path)
                        <div class="form-check mt-3">
                            <input
                                class="form-check-input"
                                id="product-remove-image"
                                name="remove_image"
                                type="checkbox"
                                value="1"
                                @checked((bool) old('remove_image'))
                                data-product-remove-image
                            >
                            <label class="form-check-label" for="product-remove-image">Usuń obecne zdjęcie</label>
                        </div>
                    @endif
                </div>
            </div>
        </section>

        <section class="product-form-section">
            <h2>{{ $isBundle ? 'Sprzedaż' : 'Sprzedaż i magazyn' }}</h2>
            <div class="product-form-grid">
                <div class="product-form-field is-third">
                    <label class="product-form-label" for="product-price">Cena brutto</label>
                    <input
                        class="form-control @error('price_gross') is-invalid @enderror"
                        id="product-price"
                        name="price_gross"
                        type="text"
                        inputmode="decimal"
                        value="{{ old('price_gross', $product->price_gross) }}"
                        required
                    >
                    @error('price_gross')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>

                <div class="product-form-field is-third">
                    <label class="product-form-label" for="product-currency">Waluta</label>
                    <select class="form-select @error('currency') is-invalid @enderror" id="product-currency" name="currency" required>
                        @foreach ($currencies as $code => $label)
                            <option value="{{ $code }}" @selected(old('currency', $product->currency) === $code)>{{ $label }}</option>
                        @endforeach
                    </select>
                    @error('currency')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>

                @unless ($isBundle)
                    <div class="product-form-field is-third">
                        <label class="product-form-label" for="product-stock">Stan magazynowy</label>
                        <div class="input-group">
                            <input
                                class="form-control @error('stock_quantity') is-invalid @enderror"
                                id="product-stock"
                                name="stock_quantity"
                                type="number"
                                step="1"
                                value="{{ old('stock_quantity', $product->stock_quantity ?? 0) }}"
                                required
                            >
                            <span class="input-group-text">szt.</span>
                            @error('stock_quantity')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                    </div>
                @endunless

                <div class="product-form-field is-third">
                    <label class="product-form-label" for="product-vat-preset">VAT</label>
                    <select
                        class="form-select @error('vat_rate_preset') is-invalid @enderror"
                        id="product-vat-preset"
                        name="vat_rate_preset"
                        data-vat-preset
                    >
                        <option value="23.00" @selected($vatPreset === '23.00')>23%</option>
                        <option value="8.00" @selected($vatPreset === '8.00')>8%</option>
                        <option value="5.00" @selected($vatPreset === '5.00')>5%</option>
                        <option value="0.00" @selected($vatPreset === '0.00')>0%</option>
                        <option value="custom" @selected($vatPreset === 'custom')>Inna stawka</option>
                    </select>
                    @error('vat_rate_preset')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>

                <div class="product-form-field is-third product-custom-vat" data-custom-vat @if ($vatPreset !== 'custom') hidden @endif>
                    <label class="product-form-label" for="product-custom-vat">Inna stawka VAT</label>
                    <div class="input-group">
                        <input
                            class="form-control @error('custom_vat_rate') is-invalid @enderror"
                            id="product-custom-vat"
                            name="custom_vat_rate"
                            type="text"
                            inputmode="decimal"
                            value="{{ $customVatRate }}"
                            @required($vatPreset === 'custom')
                            data-custom-vat-input
                        >
                        <span class="input-group-text">%</span>
                        @error('custom_vat_rate')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                </div>
            </div>
        </section>

        @if ($isBundle)
            <section class="product-form-section">
                <h2>Skład zestawu</h2>
                @if ($isEdit)
                    <div class="bundle-availability">
                        Dostępna liczba zestawów na podstawie stanów składników:
                        <strong>{{ $product->availableStockQuantity() }} szt.</strong>
                    </div>
                @endif
                @error('components')<div class="alert alert-danger py-2">{{ $message }}</div>@enderror
                <div class="bundle-component-list" data-bundle-components>
                    @foreach ($componentRows as $index => $componentRow)
                        <div class="bundle-component-row" data-bundle-component-row>
                            <div>
                                <label class="visually-hidden" for="bundle-component-product-{{ $index }}">Produkt</label>
                                <select
                                    class="form-select @error('components.'.$index.'.product_id') is-invalid @enderror"
                                    id="bundle-component-product-{{ $index }}"
                                    name="components[{{ $index }}][product_id]"
                                    required
                                >
                                    <option value="">Wybierz produkt</option>
                                    @foreach ($componentProducts as $componentProduct)
                                        <option
                                            value="{{ $componentProduct->getKey() }}"
                                            @selected((string) ($componentRow['product_id'] ?? '') === (string) $componentProduct->getKey())
                                        >
                                            {{ $componentProduct->name }} (ID {{ $componentProduct->getKey() }}){{ $componentProduct->is_active ? '' : ' - ukryty' }}
                                        </option>
                                    @endforeach
                                </select>
                                @error('components.'.$index.'.product_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                            <div>
                                <label class="visually-hidden" for="bundle-component-quantity-{{ $index }}">Ilość</label>
                                <div class="input-group">
                                    <input
                                        class="form-control @error('components.'.$index.'.quantity') is-invalid @enderror"
                                        id="bundle-component-quantity-{{ $index }}"
                                        name="components[{{ $index }}][quantity]"
                                        type="number"
                                        min="1"
                                        max="2147483647"
                                        step="1"
                                        value="{{ $componentRow['quantity'] ?? 1 }}"
                                        required
                                    >
                                    <span class="input-group-text">szt.</span>
                                    @error('components.'.$index.'.quantity')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                </div>
                            </div>
                            <button
                                class="btn btn-outline-danger bundle-component-remove"
                                type="button"
                                title="Usuń składnik"
                                data-bundle-component-remove
                            >
                                <i class="bi bi-trash" aria-hidden="true"></i>
                                <span class="visually-hidden">Usuń składnik</span>
                            </button>
                        </div>
                    @endforeach
                </div>
                <button class="btn btn-outline-primary mt-3" type="button" data-bundle-component-add>
                    <i class="bi bi-plus-lg" aria-hidden="true"></i>
                    Dodaj produkt
                </button>
                <template data-bundle-component-template>
                    <div class="bundle-component-row" data-bundle-component-row>
                        <div>
                            <label class="visually-hidden" data-component-product-label>Produkt</label>
                            <select class="form-select" data-component-product name="" required>
                                <option value="">Wybierz produkt</option>
                                @foreach ($componentProducts as $componentProduct)
                                    <option value="{{ $componentProduct->getKey() }}">
                                        {{ $componentProduct->name }} (ID {{ $componentProduct->getKey() }}){{ $componentProduct->is_active ? '' : ' - ukryty' }}
                                    </option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label class="visually-hidden" data-component-quantity-label>Ilość</label>
                            <div class="input-group">
                                <input class="form-control" data-component-quantity name="" type="number" min="1" max="2147483647" step="1" value="1" required>
                                <span class="input-group-text">szt.</span>
                            </div>
                        </div>
                        <button class="btn btn-outline-danger bundle-component-remove" type="button" title="Usuń składnik" data-bundle-component-remove>
                            <i class="bi bi-trash" aria-hidden="true"></i>
                            <span class="visually-hidden">Usuń składnik</span>
                        </button>
                    </div>
                </template>
            </section>
        @endif
        <section class="product-form-section">
            <h2>Opakowanie</h2>
            <div class="product-form-grid">
                <div class="product-form-field is-quarter">
                    <label class="product-form-label" for="product-weight">Waga</label>
                    <div class="input-group">
                        <input
                            class="form-control @error('package_weight_kg') is-invalid @enderror"
                            id="product-weight"
                            name="package_weight_kg"
                            type="text"
                            inputmode="decimal"
                            value="{{ old('package_weight_kg', $product->package_weight_kg) }}"
                        >
                        <span class="input-group-text">kg</span>
                        @error('package_weight_kg')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                </div>

                @foreach ([
                    'package_length_cm' => ['Długość', 'product-length'],
                    'package_width_cm' => ['Szerokość', 'product-width'],
                    'package_height_cm' => ['Wysokość', 'product-height'],
                ] as $field => [$label, $id])
                    <div class="product-form-field is-quarter">
                        <label class="product-form-label" for="{{ $id }}">{{ $label }}</label>
                        <div class="input-group">
                            <input
                                class="form-control @error($field) is-invalid @enderror"
                                id="{{ $id }}"
                                name="{{ $field }}"
                                type="text"
                                inputmode="decimal"
                                value="{{ old($field, $product->{$field}) }}"
                            >
                            <span class="input-group-text">cm</span>
                            @error($field)<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                    </div>
                @endforeach
            </div>
        </section>

        <div class="product-form-actions">
            <a class="btn btn-outline-secondary" href="{{ route('products.index', ['status' => 'all', 'tab' => $isBundle ? 'bundles' : 'products']) }}">Anuluj</a>
            <button class="btn btn-primary" type="submit">
                <i class="bi bi-check-lg" aria-hidden="true"></i>
                Zapisz
            </button>
        </div>
    </form>
</div>

<script>
    (() => {
        const form = document.querySelector('[data-product-form]');
        if (!form) {
            return;
        }

        const vatPreset = form.querySelector('[data-vat-preset]');
        const customVat = form.querySelector('[data-custom-vat]');
        const customVatInput = form.querySelector('[data-custom-vat-input]');

        const syncVat = () => {
            const custom = vatPreset?.value === 'custom';
            if (customVat) {
                customVat.hidden = !custom;
            }
            if (customVatInput) {
                customVatInput.required = custom;
            }
        };

        vatPreset?.addEventListener('change', syncVat);
        syncVat();

        const imageInput = form.querySelector('[data-product-image-input]');
        const imagePreview = form.querySelector('[data-product-image-preview]');
        const removeImage = form.querySelector('[data-product-remove-image]');

        imageInput?.addEventListener('change', () => {
            const file = imageInput.files?.[0];
            if (!file || !imagePreview) {
                return;
            }

            if (removeImage) {
                removeImage.checked = false;
            }

            const image = document.createElement('img');
            image.alt = 'Wybrane zdjęcie produktu';
            image.src = URL.createObjectURL(file);
            image.addEventListener('load', () => URL.revokeObjectURL(image.src), { once: true });
            imagePreview.replaceChildren(image);
        });

        removeImage?.addEventListener('change', () => {
            if (removeImage.checked && imageInput) {
                imageInput.value = '';
            }
        });

        const componentList = form.querySelector('[data-bundle-components]');
        const componentTemplate = form.querySelector('[data-bundle-component-template]');
        const addComponent = form.querySelector('[data-bundle-component-add]');
        let nextComponentIndex = componentList?.children.length ?? 0;

        const syncComponentRemoveButtons = () => {
            const rows = Array.from(componentList?.querySelectorAll('[data-bundle-component-row]') ?? []);
            rows.forEach((row) => {
                const button = row.querySelector('[data-bundle-component-remove]');
                if (button) {
                    button.disabled = rows.length <= 2;
                }
            });
        };

        const bindComponentRemove = (row) => {
            row.querySelector('[data-bundle-component-remove]')?.addEventListener('click', () => {
                row.remove();
                syncComponentRemoveButtons();
            });
        };

        componentList?.querySelectorAll('[data-bundle-component-row]').forEach(bindComponentRemove);
        addComponent?.addEventListener('click', () => {
            const source = componentTemplate?.content.firstElementChild;
            if (!source || !componentList) {
                return;
            }

            const row = source.cloneNode(true);
            const product = row.querySelector('[data-component-product]');
            const quantity = row.querySelector('[data-component-quantity]');
            const productLabel = row.querySelector('[data-component-product-label]');
            const quantityLabel = row.querySelector('[data-component-quantity-label]');
            const productId = `bundle-component-product-${nextComponentIndex}`;
            const quantityId = `bundle-component-quantity-${nextComponentIndex}`;

            product.name = `components[${nextComponentIndex}][product_id]`;
            product.id = productId;
            quantity.name = `components[${nextComponentIndex}][quantity]`;
            quantity.id = quantityId;
            productLabel.htmlFor = productId;
            quantityLabel.htmlFor = quantityId;
            nextComponentIndex += 1;

            componentList.append(row);
            bindComponentRemove(row);
            syncComponentRemoveButtons();
            product.focus();
        });
        syncComponentRemoveButtons();
    })();
</script>
@endsection
