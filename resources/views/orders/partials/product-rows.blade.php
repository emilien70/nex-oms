@php
    $currencyOptions = $currencyOptions ?? app(\App\Support\CurrencyCatalog::class)->all();
@endphp
@forelse ($order->items as $item)
    <tr>
        <td><span class="product-thumb" aria-hidden="true">&#9633;</span></td>
        <td><div class="product-name">{{ $item->product_name }}</div></td>
        <td class="product-metric">{{ $item->quantity }}</td>
        <td class="product-metric">{{ number_format((float) $item->unit_price_gross, 2, ',', ' ') }} {{ $item->currency ?? $order->currency }}</td>
        <td class="product-metric">{{ $item->vat_rate !== null ? number_format((float) $item->vat_rate, 2, ',', ' ') . '%' : '-' }}</td>
        <td class="product-metric">{{ $item->weight !== null ? number_format((float) $item->weight, 3, ',', ' ') : '-' }}</td>
        <td class="product-metric">
            @if ($item->created_at)
                <div class="product-date-stack">{{ $item->created_at->format('Y-m-d') }}<br>{{ $item->created_at->format('H:i') }}</div>
            @else
                -
            @endif
        </td>
        <td class="text-end product-actions product-actions-column">
            <div class="dropdown">
                <button class="btn btn-sm btn-light border" type="button" data-bs-toggle="dropdown" data-bs-boundary="viewport" aria-expanded="false">&#8942;</button>
                <ul class="dropdown-menu dropdown-menu-end">
                    <li><button class="dropdown-item" type="button" data-bs-toggle="collapse" data-bs-target="#productEdit{{ $item->id }}">Edytuj</button></li>
                    <li>
                        <form method="POST" action="{{ route('order-items.destroy', $item) }}" data-order-ajax-form data-confirm="Usunac produkt z zamowienia?">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="dropdown-item text-danger">Usu&#324;</button>
                        </form>
                    </li>
                </ul>
            </div>
        </td>
    </tr>
    <tr class="collapse" id="productEdit{{ $item->id }}">
        <td colspan="8">
            <div class="product-inline-form">
                <form method="POST" action="{{ route('order-items.update', $item) }}" data-order-ajax-form>
                    @csrf
                    @method('PATCH')
                    <div class="row g-2">
                        <div class="col-12 col-lg-4">
                            <label class="form-label">Nazwa produktu</label>
                            <input type="text" name="product_name" class="form-control form-control-sm" value="{{ old('product_name', $item->product_name) }}" required>
                        </div>
                        <div class="col-6 col-lg-2">
                            <label class="form-label">Ilo&#347;&#263;</label>
                            <input type="number" min="1" name="quantity" class="form-control form-control-sm" value="{{ old('quantity', $item->quantity) }}" required>
                        </div>
                        <div class="col-6 col-lg-2">
                            <label class="form-label">Cena jednostkowa</label>
                            <input type="number" step="0.01" min="0" name="unit_price_gross" class="form-control form-control-sm" value="{{ old('unit_price_gross', $item->unit_price_gross) }}" required>
                        </div>
                        <div class="col-6 col-lg-1">
                            <label class="form-label">Waluta</label>
                            @php
                                $itemCurrency = strtoupper((string) old('currency', $item->currency ?? $order->currency));
                                $hasHistoricalItemCurrency = $itemCurrency !== '' && ! array_key_exists($itemCurrency, $currencyOptions);
                            @endphp
                            <select name="currency" class="form-select form-select-sm" required>
                                @if ($hasHistoricalItemCurrency)
                                    <option value="{{ $itemCurrency }}" selected disabled class="text-secondary">
                                        {{ $itemCurrency }}
                                    </option>
                                @endif
                                @foreach ($currencyOptions as $currencyCode)
                                    <option value="{{ $currencyCode }}" @selected($itemCurrency === $currencyCode)>
                                        {{ $currencyCode }}
                                    </option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-6 col-lg-1">
                            <label class="form-label">VAT (%)</label>
                            <input type="number" step="1" min="0" max="100" name="vat_rate" class="form-control form-control-sm" value="{{ old('vat_rate', $item->vat_rate === null ? '' : rtrim(rtrim($item->vat_rate, '0'), '.')) }}">
                        </div>
                        <div class="col-6 col-lg-1">
                            <label class="form-label">Waga</label>
                            <input type="number" step="0.001" min="0" name="weight" class="form-control form-control-sm" value="{{ old('weight', $item->weight) }}">
                        </div>
                        <div class="col-12 col-lg-1 d-flex align-items-end justify-content-end">
                            <button type="submit" class="btn btn-sm btn-primary">Zapisz</button>
                        </div>
                    </div>
                </form>
            </div>
        </td>
    </tr>
@empty
    <tr>
        <td colspan="8" class="text-secondary">Brak pozycji zam&oacute;wienia.</td>
    </tr>
@endforelse
