<section class="invoice-series-form-section" aria-labelledby="invoice-series-vat-heading">
    <h3 id="invoice-series-vat-heading">VAT i pozycje</h3>
    <div class="row g-3">
        <div class="col-md-6">
            <label class="form-label" for="invoice-series-vat-source">Źródło stawki VAT</label>
            <select class="form-select form-select-sm {{ $invoiceHasError('vat_rate_source') ? 'is-invalid' : '' }}" id="invoice-series-vat-source" name="vat_rate_source" data-invoice-control required>
                @foreach ($vatRateSources as $option)
                    <option value="{{ $option->value }}" @selected($invoiceValue('vat_rate_source') === $option->value)>{{ $option->label() }}</option>
                @endforeach
            </select>
            @if ($invoiceHasError('vat_rate_source'))<div class="invalid-feedback">{{ $errors->first('vat_rate_source') }}</div>@endif
        </div>
        <div class="col-md-6" data-invoice-dependent="default-vat-rate">
            <label class="form-label" for="invoice-series-default-vat">Domyślna stawka VAT (%)</label>
            <input class="form-control form-control-sm {{ $invoiceHasError('default_vat_rate') ? 'is-invalid' : '' }}" id="invoice-series-default-vat" name="default_vat_rate" type="number" min="0" max="100" step="0.01" value="{{ $invoiceValue('default_vat_rate') }}">
            @if ($invoiceHasError('default_vat_rate'))<div class="invalid-feedback">{{ $errors->first('default_vat_rate') }}</div>@endif
        </div>
        <div class="col-md-4 d-flex align-items-end">
            <div class="form-check mb-1">
                <input type="hidden" name="include_shipping" value="0">
                <input class="form-check-input {{ $invoiceHasError('include_shipping') ? 'is-invalid' : '' }}" id="invoice-series-include-shipping" name="include_shipping" type="checkbox" value="1" data-invoice-control @checked((bool) $invoiceValue('include_shipping'))>
                <label class="form-check-label" for="invoice-series-include-shipping">Uwzględnij koszt dostawy</label>
                @if ($invoiceHasError('include_shipping'))<div class="invalid-feedback">{{ $errors->first('include_shipping') }}</div>@endif
            </div>
        </div>
        <div class="col-md-4" data-invoice-dependent="shipping-vat-settings">
            <label class="form-label" for="invoice-series-shipping-vat-mode">VAT kosztu dostawy</label>
            <select class="form-select form-select-sm {{ $invoiceHasError('shipping_vat_mode') ? 'is-invalid' : '' }}" id="invoice-series-shipping-vat-mode" name="shipping_vat_mode" data-invoice-control required>
                @foreach ($shippingVatModes as $option)
                    <option value="{{ $option->value }}" @selected($invoiceValue('shipping_vat_mode') === $option->value)>{{ $option->label() }}</option>
                @endforeach
            </select>
            @if ($invoiceHasError('shipping_vat_mode'))<div class="invalid-feedback">{{ $errors->first('shipping_vat_mode') }}</div>@endif
        </div>
        <div class="col-md-4" data-invoice-dependent="shipping-vat-rate">
            <label class="form-label" for="invoice-series-shipping-vat">Stała stawka VAT dostawy (%)</label>
            <input class="form-control form-control-sm {{ $invoiceHasError('default_shipping_vat_rate') ? 'is-invalid' : '' }}" id="invoice-series-shipping-vat" name="default_shipping_vat_rate" type="number" min="0" max="100" step="0.01" value="{{ $invoiceValue('default_shipping_vat_rate') }}">
            @if ($invoiceHasError('default_shipping_vat_rate'))<div class="invalid-feedback">{{ $errors->first('default_shipping_vat_rate') }}</div>@endif
        </div>
        <div class="col-md-4">
            <label class="form-label" for="invoice-series-unit-price-mode">Cena jednostkowa</label>
            <select class="form-select form-select-sm {{ $invoiceHasError('unit_price_mode') ? 'is-invalid' : '' }}" id="invoice-series-unit-price-mode" name="unit_price_mode" required>
                @foreach ($unitPriceModes as $option)
                    <option value="{{ $option->value }}" @selected($invoiceValue('unit_price_mode') === $option->value)>{{ $option->label() }}</option>
                @endforeach
            </select>
            @if ($invoiceHasError('unit_price_mode'))<div class="invalid-feedback">{{ $errors->first('unit_price_mode') }}</div>@endif
        </div>
        <div class="col-md-4 d-flex align-items-end">
            <div class="form-check mb-1">
                <input type="hidden" name="skip_zero_price_items" value="0">
                <input class="form-check-input {{ $invoiceHasError('skip_zero_price_items') ? 'is-invalid' : '' }}" id="invoice-series-skip-zero" name="skip_zero_price_items" type="checkbox" value="1" @checked((bool) $invoiceValue('skip_zero_price_items'))>
                <label class="form-check-label" for="invoice-series-skip-zero">Pomijaj pozycje z ceną 0</label>
                @if ($invoiceHasError('skip_zero_price_items'))<div class="invalid-feedback">{{ $errors->first('skip_zero_price_items') }}</div>@endif
            </div>
        </div>
        <div class="col-md-4 d-flex align-items-end">
            <div class="form-check mb-1">
                <input type="hidden" name="show_vat_column" value="0">
                <input class="form-check-input {{ $invoiceHasError('show_vat_column') ? 'is-invalid' : '' }}" id="invoice-series-show-vat" name="show_vat_column" type="checkbox" value="1" @checked((bool) $invoiceValue('show_vat_column'))>
                <label class="form-check-label" for="invoice-series-show-vat">Pokaż kolumnę VAT</label>
                @if ($invoiceHasError('show_vat_column'))<div class="invalid-feedback">{{ $errors->first('show_vat_column') }}</div>@endif
            </div>
        </div>
    </div>
</section>
