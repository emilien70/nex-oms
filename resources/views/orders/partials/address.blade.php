@php
    $showTaxId = $showTaxId ?? (bool) ($address?->tax_id);
    $showCountry = $showCountry ?? true;
    $showProvince = $showProvince ?? false;
    $showPhone = $showPhone ?? true;
    $showEmail = $showEmail ?? true;
    $taxIdLast = $taxIdLast ?? false;
    $streetLine = trim(($address?->street ?? '') . ' ' . ($address?->building_number ?? ''));
    if ($address?->apartment_number) {
        $streetLine .= '/' . $address->apartment_number;
    }
    $cityLine = trim(($address?->postal_code ?? '') . ' ' . ($address?->city ?? ''));
    $rows = [
        'Imię i Nazwisko' => $address?->name,
        'Firma' => $address?->company_name,
    ];
    if ($showTaxId && ! $taxIdLast) {
        $rows['NIP'] = $address?->tax_id;
    }
    $rows += [
        'Adres' => $streetLine ?: null,
        'Kod i miasto' => $cityLine ?: null,
    ];
    if ($showProvince) {
        $rows['Wojewodztwo'] = $address?->province;
    }
    if ($showCountry) {
        $rows['Kraj'] = $address?->country_code;
    }
    if ($showPhone) {
        $rows['Telefon'] = $address?->phone;
    }
    if ($showEmail) {
        $rows['E-mail'] = $address?->email;
    }
    if ($showTaxId && $taxIdLast) {
        $rows['NIP'] = $address?->tax_id;
    }
@endphp

<address class="mb-0">
    <div class="nex-address-grid">
        @foreach ($rows as $label => $value)
            <div class="inline-field-row">
                <div class="nex-label">{{ $label }}</div>
                <div class="nex-value {{ $value ? '' : 'nex-empty' }}">{{ $value ?: '-' }}<span class="inline-pencil">&#9998;</span></div>
            </div>
        @endforeach
    </div>
</address>
