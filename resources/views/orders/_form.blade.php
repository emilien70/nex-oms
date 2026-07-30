@php
    $shippingAddress = $order->shippingAddressData();
    $billingAddress = $order->billingAddressData();
    $formItems = old('items', $itemRows);
    $formItems = array_slice(array_pad($formItems, 5, []), 0, 5);
    $countryCatalog = app(\App\Support\CountryCatalog::class);
    $shippingCountryCode = $countryCatalog->normalize(old('shipping_country_code', $shippingAddress?->country_code));
    $billingCountryCode = $countryCatalog->normalize(old('billing_country_code', $billingAddress?->country_code));

    $dateValue = fn ($value) => $value ? $value->format('Y-m-d\TH:i') : null;
@endphp

<form method="POST" action="{{ $action }}">
    @csrf
    @if ($method !== 'POST')
        @method($method)
    @endif

    <div class="card border-0 shadow-sm mb-3">
        <div class="card-body">
            <h2 class="h5 mb-3">Dane zam&oacute;wienia</h2>
            <div class="row g-3">
                <div class="col-12 col-md-4">
                    <label class="form-label" for="source">&#377;r&oacute;d&#322;o</label>
                    <select id="source" name="source" class="form-select">
                        @foreach ($sourceOptions as $source => $label)
                            <option value="{{ $source }}" @selected(old('source', $order->source ?? 'manual') === $source)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-12 col-md-4">
                    <label class="form-label" for="external_id">Numer w sklepie / transakcji</label>
                    <input id="external_id" type="text" name="external_id" class="form-control" value="{{ old('external_id', $order->external_id) }}">
                </div>
                <div class="col-12 col-md-4">
                    <label class="form-label" for="status">Status</label>
                    <select id="status" name="status" class="form-select">
                        @foreach ($statuses as $status => $label)
                            <option value="{{ $status }}" @selected(old('status', $order->status ?? 'new') === $status)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-12 col-md-4">
                    <label class="form-label" for="purchased_at">Data zakupu</label>
                    <input id="purchased_at" type="datetime-local" name="purchased_at" class="form-control" value="{{ old('purchased_at', $dateValue($order->purchased_at)) }}">
                </div>
                <div class="col-12">
                    <label class="form-label" for="notes">Uwagi</label>
                    <textarea id="notes" name="notes" class="form-control" rows="3">{{ old('notes', $order->notes) }}</textarea>
                </div>
            </div>
        </div>
    </div>

    <div class="card border-0 shadow-sm mb-3">
        <div class="card-body">
            <h2 class="h5 mb-3">Dane kontaktowe w zam&oacute;wieniu</h2>
            <div class="row g-3">
                <div class="col-12 col-md-4">
                    <label class="form-label" for="login">Login klienta</label>
                    <input id="login" type="text" name="login" class="form-control" value="{{ old('login', $order->customer_login) }}">
                </div>
                <div class="col-12 col-md-4">
                    <label class="form-label" for="email">E-mail</label>
                    <input id="email" type="email" name="email" class="form-control" value="{{ old('email', $order->customer_email) }}">
                </div>
                <div class="col-12 col-md-4">
                    <label class="form-label" for="phone">Telefon</label>
                    <input id="phone" type="text" name="phone" class="form-control" value="{{ old('phone', $order->customer_phone) }}" placeholder="+48 501 294 368">
                </div>
            </div>
        </div>
    </div>

    <div class="row g-3">
        <div class="col-12 col-xl-6">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <h2 class="h5 mb-3">Adres dostawy</h2>
                    <div class="row g-3">
                        <div class="col-12">
                            <label class="form-label" for="shipping_name">Imi&#281; i Nazwisko</label>
                            <input id="shipping_name" type="text" name="shipping_name" class="form-control" value="{{ old('shipping_name', $shippingAddress?->name) }}">
                        </div>
                        <div class="col-12">
                            <label class="form-label" for="shipping_company_name">Firma</label>
                            <input id="shipping_company_name" type="text" name="shipping_company_name" class="form-control" value="{{ old('shipping_company_name', $shippingAddress?->company_name) }}">
                        </div>
                        <div class="col-12 col-md-6">
                            <label class="form-label" for="shipping_street">Ulica</label>
                            <input id="shipping_street" type="text" name="shipping_street" class="form-control" value="{{ old('shipping_street', $shippingAddress?->street) }}">
                        </div>
                        <div class="col-6 col-md-3">
                            <label class="form-label" for="shipping_building_number">Nr bud.</label>
                            <input id="shipping_building_number" type="text" name="shipping_building_number" class="form-control" value="{{ old('shipping_building_number', $shippingAddress?->building_number) }}">
                        </div>
                        <div class="col-6 col-md-3">
                            <label class="form-label" for="shipping_apartment_number">Nr lok.</label>
                            <input id="shipping_apartment_number" type="text" name="shipping_apartment_number" class="form-control" value="{{ old('shipping_apartment_number', $shippingAddress?->apartment_number) }}">
                        </div>
                        <div class="col-6">
                            <label class="form-label" for="shipping_postal_code">Kod pocztowy</label>
                            <input id="shipping_postal_code" type="text" name="shipping_postal_code" class="form-control" value="{{ old('shipping_postal_code', $shippingAddress?->postal_code) }}">
                        </div>
                        <div class="col-6">
                            <label class="form-label" for="shipping_city">Miasto</label>
                            <input id="shipping_city" type="text" name="shipping_city" class="form-control" value="{{ old('shipping_city', $shippingAddress?->city) }}">
                        </div>
                        <div class="col-6">
                            <label class="form-label" for="shipping_province">Wojew&oacute;dztwo</label>
                            <input id="shipping_province" type="text" name="shipping_province" class="form-control" value="{{ old('shipping_province', $shippingAddress?->province) }}">
                        </div>
                        <div class="col-6">
                            <label class="form-label" for="shipping_country_code">Kraj</label>
                            <select id="shipping_country_code" name="shipping_country_code" class="form-select @error('shipping_country_code') is-invalid @enderror" required>
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
                </div>
            </div>
        </div>

        <div class="col-12 col-xl-6">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <h2 class="h5 mb-3">Dane do faktury</h2>
                    <div class="row g-3">
                        <div class="col-12">
                            <label class="form-label" for="billing_name">Imi&#281; i Nazwisko</label>
                            <input id="billing_name" type="text" name="billing_name" class="form-control" value="{{ old('billing_name', $billingAddress?->name) }}">
                        </div>
                        <div class="col-12 col-md-8">
                            <label class="form-label" for="billing_company_name">Firma</label>
                            <input id="billing_company_name" type="text" name="billing_company_name" class="form-control" value="{{ old('billing_company_name', $billingAddress?->company_name) }}">
                        </div>
                        <div class="col-12 col-md-4">
                            <label class="form-label" for="billing_tax_id">NIP</label>
                            <input id="billing_tax_id" type="text" name="billing_tax_id" class="form-control" value="{{ old('billing_tax_id', $billingAddress?->tax_id) }}">
                        </div>
                        <div class="col-12 col-md-6">
                            <label class="form-label" for="billing_street">Ulica</label>
                            <input id="billing_street" type="text" name="billing_street" class="form-control" value="{{ old('billing_street', $billingAddress?->street) }}">
                        </div>
                        <div class="col-6 col-md-3">
                            <label class="form-label" for="billing_building_number">Nr bud.</label>
                            <input id="billing_building_number" type="text" name="billing_building_number" class="form-control" value="{{ old('billing_building_number', $billingAddress?->building_number) }}">
                        </div>
                        <div class="col-6 col-md-3">
                            <label class="form-label" for="billing_apartment_number">Nr lok.</label>
                            <input id="billing_apartment_number" type="text" name="billing_apartment_number" class="form-control" value="{{ old('billing_apartment_number', $billingAddress?->apartment_number) }}">
                        </div>
                        <div class="col-6">
                            <label class="form-label" for="billing_postal_code">Kod pocztowy</label>
                            <input id="billing_postal_code" type="text" name="billing_postal_code" class="form-control" value="{{ old('billing_postal_code', $billingAddress?->postal_code) }}">
                        </div>
                        <div class="col-6">
                            <label class="form-label" for="billing_city">Miasto</label>
                            <input id="billing_city" type="text" name="billing_city" class="form-control" value="{{ old('billing_city', $billingAddress?->city) }}">
                        </div>
                        <div class="col-6">
                            <label class="form-label" for="billing_province">Wojew&oacute;dztwo</label>
                            <input id="billing_province" type="text" name="billing_province" class="form-control" value="{{ old('billing_province', $billingAddress?->province) }}">
                        </div>
                        <div class="col-4">
                            <label class="form-label" for="billing_country_code">Kraj</label>
                            <select id="billing_country_code" name="billing_country_code" class="form-select @error('billing_country_code') is-invalid @enderror" required>
                                <option value="">&mdash; Wybierz kraj &mdash;</option>
                                @foreach ($countries as $code => $name)
                                    <option value="{{ $code }}" @selected($billingCountryCode === $code)>{{ $name }}</option>
                                @endforeach
                            </select>
                            @error('billing_country_code')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>
                        <div class="col-8">
                            <label class="form-label" for="billing_phone">Telefon</label>
                            <input id="billing_phone" type="text" name="billing_phone" class="form-control" value="{{ old('billing_phone', $billingAddress?->phone) }}" placeholder="+48 501 294 368">
                        </div>
                        <div class="col-12">
                            <label class="form-label" for="billing_email">E-mail</label>
                            <input id="billing_email" type="email" name="billing_email" class="form-control" value="{{ old('billing_email', $billingAddress?->email) }}">
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="card border-0 shadow-sm my-3">
        <div class="card-body">
            <h2 class="h5 mb-3">P&#322;atno&#347;&#263;</h2>
            <div class="row g-3">
                <div class="col-6 col-md-3">
                    <label class="form-label" for="currency">Waluta</label>
                    <input id="currency" type="text" name="currency" class="form-control" value="{{ old('currency', $order->currency ?? 'PLN') }}">
                </div>
                <div class="col-6 col-md-3">
                    <label class="form-label" for="total_gross">Kwota brutto</label>
                    <input id="total_gross" type="number" step="0.01" min="0" name="total_gross" class="form-control" value="{{ old('total_gross', $order->total_gross ?? 0) }}">
                </div>
                <div class="col-6 col-md-3">
                    <label class="form-label" for="paid_amount">Zap&#322;acono</label>
                    <input id="paid_amount" type="number" step="0.01" min="0" name="paid_amount" class="form-control" value="{{ old('paid_amount', $order->paid_amount ?? 0) }}">
                </div>
                <div class="col-6 col-md-3">
                    <label class="form-label" for="delivery_cost_gross">Koszt dostawy</label>
                    <input id="delivery_cost_gross" type="number" step="0.01" min="0" name="delivery_cost_gross" class="form-control" value="{{ old('delivery_cost_gross', $order->delivery_cost_gross ?? 0) }}">
                </div>
                <div class="col-12 col-md-6">
                    <label class="form-label" for="shipping_method">Spos&oacute;b wysy&#322;ki</label>
                    <input id="shipping_method" type="text" name="shipping_method" class="form-control" value="{{ old('shipping_method', $order->shipping_method) }}">
                </div>
                <div class="col-12 col-md-3">
                    <label class="form-label" for="cash_on_delivery">Pobranie</label>
                    <select id="cash_on_delivery" name="cash_on_delivery" class="form-select">
                        <option value="0" @selected(! old('cash_on_delivery', $order->cash_on_delivery ?? false))>Nie</option>
                        <option value="1" @selected((bool) old('cash_on_delivery', $order->cash_on_delivery ?? false))>Tak</option>
                    </select>
                </div>
                <div class="col-6 col-md-3">
                    <label class="form-label" for="payment_status">Status p&#322;atno&#347;ci</label>
                    <select id="payment_status" name="payment_status" class="form-select">
                        @foreach ($paymentStatusOptions as $paymentStatus => $label)
                            <option value="{{ $paymentStatus }}" @selected(old('payment_status', $order->payment_status ?? 'unpaid') === $paymentStatus)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-12 col-md-6">
                    <label class="form-label" for="payment_method">Metoda p&#322;atno&#347;ci</label>
                    <input id="payment_method" type="text" name="payment_method" class="form-control" value="{{ old('payment_method', $order->payment_method) }}">
                </div>
                <div class="col-12 col-md-6">
                    <label class="form-label" for="paid_at">Data p&#322;atno&#347;ci</label>
                    <input id="paid_at" type="datetime-local" name="paid_at" class="form-control" value="{{ old('paid_at', $dateValue($order->paid_at)) }}">
                </div>
                <div class="col-12"><hr><h3 class="h6 mb-0">Odbi&oacute;r w punkcie</h3></div>
                <div class="col-12 col-md-6">
                    <label class="form-label" for="pickup_point_name">Nazwa punktu</label>
                    <input id="pickup_point_name" type="text" name="pickup_point_name" class="form-control" value="{{ old('pickup_point_name', $order->pickup_point_name) }}">
                </div>
                <div class="col-12 col-md-6">
                    <label class="form-label" for="pickup_point_id">ID punktu</label>
                    <input id="pickup_point_id" type="text" name="pickup_point_id" class="form-control" value="{{ old('pickup_point_id', $order->pickup_point_id) }}">
                </div>
                <div class="col-12">
                    <label class="form-label" for="pickup_point_address">Adres punktu</label>
                    <input id="pickup_point_address" type="text" name="pickup_point_address" class="form-control" value="{{ old('pickup_point_address', $order->pickup_point_address) }}">
                </div>
                <div class="col-6">
                    <label class="form-label" for="pickup_point_postal_code">Kod pocztowy</label>
                    <input id="pickup_point_postal_code" type="text" name="pickup_point_postal_code" class="form-control" value="{{ old('pickup_point_postal_code', $order->pickup_point_postal_code) }}">
                </div>
                <div class="col-6">
                    <label class="form-label" for="pickup_point_city">Miasto</label>
                    <input id="pickup_point_city" type="text" name="pickup_point_city" class="form-control" value="{{ old('pickup_point_city', $order->pickup_point_city) }}">
                </div>
            </div>
        </div>
    </div>

    <div class="card border-0 shadow-sm mb-3">
        <div class="card-body">
            <h2 class="h5 mb-3">Produkty</h2>
            <div class="table-responsive">
                <table class="table align-middle">
                    <thead>
                        <tr>
                            <th>Nazwa produktu</th>
                            <th>Ilo&#347;&#263;</th>
                            <th>Cena brutto</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($formItems as $index => $item)
                            <tr>
                                <td><input type="text" name="items[{{ $index }}][product_name]" class="form-control" value="{{ $item['product_name'] ?? '' }}"></td>
                                <td><input type="number" min="1" name="items[{{ $index }}][quantity]" class="form-control" value="{{ $item['quantity'] ?? 1 }}"></td>
                                <td><input type="number" step="0.01" min="0" name="items[{{ $index }}][unit_price_gross]" class="form-control" value="{{ $item['unit_price_gross'] ?? 0 }}"></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="text-secondary small">Zapisane zostan&#261; tylko wiersze z nazw&#261; produktu.</div>
        </div>
    </div>

    <div class="d-flex gap-2">
        <button type="submit" class="btn btn-primary">{{ $submitLabel }}</button>
        <a class="btn btn-outline-secondary" href="{{ route('orders.index') }}">Anuluj</a>
    </div>
</form>
