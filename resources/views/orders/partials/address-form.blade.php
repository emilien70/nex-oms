@php
    $showCountry = $showCountry ?? true;
    $showProvince = $showProvince ?? false;
    $showPhone = $showPhone ?? true;
    $showEmail = $showEmail ?? true;
    $taxIdLast = $taxIdLast ?? false;
    $countryCatalog = app(\App\Support\CountryCatalog::class);
    $countries = $countries ?? $countryCatalog->all();
    $countryField = $prefix.'_country_code';
    $selectedCountryCode = $countryCatalog->normalize(old($countryField, $address?->country_code));
@endphp

<div class="row g-2">
    <div class="col-12">
        <label class="form-label">Imi&#281; i Nazwisko</label>
        <input type="text" name="{{ $prefix }}_name" class="form-control form-control-sm" value="{{ old($prefix . '_name', $address?->name) }}">
    </div>
    <div class="col-12">
        <label class="form-label">Firma</label>
        <input type="text" name="{{ $prefix }}_company_name" class="form-control form-control-sm" value="{{ old($prefix . '_company_name', $address?->company_name) }}">
    </div>
    @if ($showTaxId && ! $taxIdLast)
        <div class="col-12">
            <label class="form-label">NIP</label>
            <input type="text" name="billing_tax_id" class="form-control form-control-sm" value="{{ old('billing_tax_id', $address?->tax_id) }}">
        </div>
    @endif
    <div class="col-12">
        <label class="form-label">Ulica</label>
        <input type="text" name="{{ $prefix }}_street" class="form-control form-control-sm" value="{{ old($prefix . '_street', $address?->street) }}">
    </div>
    <div class="col-6">
        <label class="form-label">Numer budynku</label>
        <input type="text" name="{{ $prefix }}_building_number" class="form-control form-control-sm" value="{{ old($prefix . '_building_number', $address?->building_number) }}">
    </div>
    <div class="col-6">
        <label class="form-label">Numer lokalu</label>
        <input type="text" name="{{ $prefix }}_apartment_number" class="form-control form-control-sm" value="{{ old($prefix . '_apartment_number', $address?->apartment_number) }}">
    </div>
    <div class="col-6">
        <label class="form-label">Kod pocztowy</label>
        <input type="text" name="{{ $prefix }}_postal_code" class="form-control form-control-sm" value="{{ old($prefix . '_postal_code', $address?->postal_code) }}">
    </div>
    <div class="col-6">
        <label class="form-label">Miasto</label>
        <input type="text" name="{{ $prefix }}_city" class="form-control form-control-sm" value="{{ old($prefix . '_city', $address?->city) }}">
    </div>
    @if ($showProvince)
        <div class="col-6">
            <label class="form-label">Wojew&oacute;dztwo</label>
            <input type="text" name="{{ $prefix }}_province" class="form-control form-control-sm" value="{{ old($prefix . '_province', $address?->province) }}">
        </div>
    @endif
    @if ($showCountry)
        <div class="col-{{ $showProvince ? '6' : '4' }}">
            <label class="form-label">Kraj</label>
            <select name="{{ $countryField }}" class="form-select form-select-sm @error($countryField) is-invalid @enderror" required>
                <option value="">&mdash; Wybierz kraj &mdash;</option>
                @foreach ($countries as $code => $name)
                    <option value="{{ $code }}" @selected($selectedCountryCode === $code)>{{ $name }}</option>
                @endforeach
            </select>
            @error($countryField)
                <div class="invalid-feedback">{{ $message }}</div>
            @enderror
        </div>
    @endif
    @if ($showPhone)
        <div class="col-{{ $showCountry ? '8' : '6' }}">
            <label class="form-label">Telefon</label>
            <input type="text" name="{{ $prefix }}_phone" class="form-control form-control-sm" value="{{ old($prefix . '_phone', $address?->phone) }}">
        </div>
    @endif
    @if ($showEmail)
        <div class="col-{{ $showPhone ? '12' : '6' }}">
            <label class="form-label">E-mail</label>
            <input type="email" name="{{ $prefix }}_email" class="form-control form-control-sm" value="{{ old($prefix . '_email', $address?->email) }}">
        </div>
    @endif
    @if ($showTaxId && $taxIdLast)
        <div class="col-12">
            <label class="form-label">NIP</label>
            <input type="text" name="billing_tax_id" class="form-control form-control-sm" value="{{ old('billing_tax_id', $address?->tax_id) }}">
        </div>
    @endif
</div>
