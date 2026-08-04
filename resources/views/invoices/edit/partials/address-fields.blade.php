@php($compoundAddress = $compoundAddress ?? false)
@php($hideContact = $hideContact ?? false)
<div class="{{ ($vertical ?? false) ? 'invoice-edit-address-list' : 'invoice-edit-form-grid' }}">
    <div><label>Imię i nazwisko</label><input class="form-control" name="name" value="{{ $snapshot['name'] ?? '' }}"></div>
    <div><label>Firma</label><input class="form-control" name="company_name" value="{{ $snapshot['company_name'] ?? '' }}"></div>
    @if($compoundAddress)
        <div><label>Adres</label><input class="form-control" name="address_line" value="{{ \App\Support\AddressLineFormatter::formatAddressLine($snapshot['street'] ?? null, $snapshot['building_number'] ?? null, $snapshot['apartment_number'] ?? null) }}"></div>
    @else
        <div><label>Ulica</label><input class="form-control" name="street" value="{{ $snapshot['street'] ?? '' }}"></div>
        <div><label>Numer budynku</label><input class="form-control" name="building_number" value="{{ $snapshot['building_number'] ?? '' }}"></div>
        <div><label>Numer lokalu</label><input class="form-control" name="apartment_number" value="{{ $snapshot['apartment_number'] ?? '' }}"></div>
    @endif
    <div><label>Kod pocztowy</label><input class="form-control" name="postal_code" value="{{ $snapshot['postal_code'] ?? '' }}"></div>
    <div><label>Miasto</label><input class="form-control" name="city" value="{{ $snapshot['city'] ?? '' }}"></div>
    <div><label>Województwo</label><input class="form-control" name="province" value="{{ $snapshot['province'] ?? '' }}"></div>
    <div><label>Kraj</label><select class="form-select" name="country_code"><option value="">— Wybierz kraj —</option>@foreach($countries as $code=>$name)<option value="{{ $code }}" @selected(($snapshot['country_code'] ?? null)===$code)>{{ $name }}</option>@endforeach</select></div>
    @unless($hideTaxId ?? false)<div><label>NIP</label><input class="form-control" name="tax_id" value="{{ $snapshot['tax_id'] ?? '' }}"></div>@endunless
    @unless($hideContact)
        <div><label>E-mail</label><input class="form-control" type="email" name="email" value="{{ $snapshot['email'] ?? '' }}"></div>
        <div><label>Telefon</label><input class="form-control" name="phone" value="{{ $snapshot['phone'] ?? '' }}"></div>
    @endunless
</div>
@if($hideContact)
    <input type="hidden" name="email" value="{{ $snapshot['email'] ?? '' }}">
    <input type="hidden" name="phone" value="{{ $snapshot['phone'] ?? '' }}">
@endif
