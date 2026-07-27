<section class="invoice-series-form-section" aria-labelledby="invoice-series-seller-heading">
    <h3 id="invoice-series-seller-heading">Dane sprzedawcy</h3>
    <div class="row g-3">
        <div class="col-12">
            <label class="form-label" for="invoice-series-seller-name">Nazwa sprzedawcy</label>
            <input class="form-control form-control-sm {{ $invoiceHasError('seller_name') ? 'is-invalid' : '' }}" id="invoice-series-seller-name" name="seller_name" type="text" value="{{ $invoiceValue('seller_name') }}" maxlength="255">
            @if ($invoiceHasError('seller_name'))<div class="invalid-feedback">{{ $errors->first('seller_name') }}</div>@endif
        </div>
        <div class="col-md-4">
            <label class="form-label" for="invoice-series-seller-tax-id">NIP</label>
            <input class="form-control form-control-sm {{ $invoiceHasError('seller_tax_id') ? 'is-invalid' : '' }}" id="invoice-series-seller-tax-id" name="seller_tax_id" type="text" value="{{ $invoiceValue('seller_tax_id') }}" maxlength="32">
            @if ($invoiceHasError('seller_tax_id'))<div class="invalid-feedback">{{ $errors->first('seller_tax_id') }}</div>@endif
        </div>
        <div class="col-md-4">
            <label class="form-label" for="invoice-series-seller-regon">REGON</label>
            <input class="form-control form-control-sm {{ $invoiceHasError('seller_regon') ? 'is-invalid' : '' }}" id="invoice-series-seller-regon" name="seller_regon" type="text" value="{{ $invoiceValue('seller_regon') }}" maxlength="32">
            @if ($invoiceHasError('seller_regon'))<div class="invalid-feedback">{{ $errors->first('seller_regon') }}</div>@endif
        </div>
        <div class="col-md-4">
            <label class="form-label" for="invoice-series-seller-bdo">BDO</label>
            <input class="form-control form-control-sm {{ $invoiceHasError('seller_bdo') ? 'is-invalid' : '' }}" id="invoice-series-seller-bdo" name="seller_bdo" type="text" value="{{ $invoiceValue('seller_bdo') }}" maxlength="64">
            @if ($invoiceHasError('seller_bdo'))<div class="invalid-feedback">{{ $errors->first('seller_bdo') }}</div>@endif
        </div>
        <div class="col-md-6">
            <label class="form-label" for="invoice-series-seller-street">Ulica</label>
            <input class="form-control form-control-sm {{ $invoiceHasError('seller_street') ? 'is-invalid' : '' }}" id="invoice-series-seller-street" name="seller_street" type="text" value="{{ $invoiceValue('seller_street') }}" maxlength="255">
            @if ($invoiceHasError('seller_street'))<div class="invalid-feedback">{{ $errors->first('seller_street') }}</div>@endif
        </div>
        <div class="col-6 col-md-3">
            <label class="form-label" for="invoice-series-seller-building">Numer budynku</label>
            <input class="form-control form-control-sm {{ $invoiceHasError('seller_building_number') ? 'is-invalid' : '' }}" id="invoice-series-seller-building" name="seller_building_number" type="text" value="{{ $invoiceValue('seller_building_number') }}" maxlength="32">
            @if ($invoiceHasError('seller_building_number'))<div class="invalid-feedback">{{ $errors->first('seller_building_number') }}</div>@endif
        </div>
        <div class="col-6 col-md-3">
            <label class="form-label" for="invoice-series-seller-apartment">Numer lokalu</label>
            <input class="form-control form-control-sm {{ $invoiceHasError('seller_apartment_number') ? 'is-invalid' : '' }}" id="invoice-series-seller-apartment" name="seller_apartment_number" type="text" value="{{ $invoiceValue('seller_apartment_number') }}" maxlength="32">
            @if ($invoiceHasError('seller_apartment_number'))<div class="invalid-feedback">{{ $errors->first('seller_apartment_number') }}</div>@endif
        </div>
        <div class="col-md-3">
            <label class="form-label" for="invoice-series-seller-postal-code">Kod pocztowy</label>
            <input class="form-control form-control-sm {{ $invoiceHasError('seller_postal_code') ? 'is-invalid' : '' }}" id="invoice-series-seller-postal-code" name="seller_postal_code" type="text" value="{{ $invoiceValue('seller_postal_code') }}" maxlength="20">
            @if ($invoiceHasError('seller_postal_code'))<div class="invalid-feedback">{{ $errors->first('seller_postal_code') }}</div>@endif
        </div>
        <div class="col-md-5">
            <label class="form-label" for="invoice-series-seller-city">Miejscowość</label>
            <input class="form-control form-control-sm {{ $invoiceHasError('seller_city') ? 'is-invalid' : '' }}" id="invoice-series-seller-city" name="seller_city" type="text" value="{{ $invoiceValue('seller_city') }}" maxlength="120">
            @if ($invoiceHasError('seller_city'))<div class="invalid-feedback">{{ $errors->first('seller_city') }}</div>@endif
        </div>
        <div class="col-md-3">
            <label class="form-label" for="invoice-series-seller-province">Województwo</label>
            <input class="form-control form-control-sm {{ $invoiceHasError('seller_province') ? 'is-invalid' : '' }}" id="invoice-series-seller-province" name="seller_province" type="text" value="{{ $invoiceValue('seller_province') }}" maxlength="120">
            @if ($invoiceHasError('seller_province'))<div class="invalid-feedback">{{ $errors->first('seller_province') }}</div>@endif
        </div>
        <div class="col-md-1">
            <label class="form-label" for="invoice-series-seller-country">Kraj</label>
            <input class="form-control form-control-sm text-uppercase {{ $invoiceHasError('seller_country_code') ? 'is-invalid' : '' }}" id="invoice-series-seller-country" name="seller_country_code" type="text" value="{{ $invoiceValue('seller_country_code') }}" maxlength="2">
            @if ($invoiceHasError('seller_country_code'))<div class="invalid-feedback">{{ $errors->first('seller_country_code') }}</div>@endif
        </div>
        <div class="col-md-6">
            <label class="form-label" for="invoice-series-seller-email">Adres e-mail</label>
            <input class="form-control form-control-sm {{ $invoiceHasError('seller_email') ? 'is-invalid' : '' }}" id="invoice-series-seller-email" name="seller_email" type="email" value="{{ $invoiceValue('seller_email') }}" maxlength="255">
            @if ($invoiceHasError('seller_email'))<div class="invalid-feedback">{{ $errors->first('seller_email') }}</div>@endif
        </div>
        <div class="col-md-6">
            <label class="form-label" for="invoice-series-seller-phone">Telefon</label>
            <input class="form-control form-control-sm {{ $invoiceHasError('seller_phone') ? 'is-invalid' : '' }}" id="invoice-series-seller-phone" name="seller_phone" type="tel" value="{{ $invoiceValue('seller_phone') }}" maxlength="64">
            @if ($invoiceHasError('seller_phone'))<div class="invalid-feedback">{{ $errors->first('seller_phone') }}</div>@endif
        </div>
    </div>
</section>
