@extends('layouts.app')
@section('title', 'Strony informacyjne - NEX-OMS')
@section('content')
<style>
    .portal-settings{max-width:1180px;margin:0 auto}.portal-settings h1{font-size:23px;margin:0}.portal-settings .heading{display:flex;align-items:center;justify-content:space-between;gap:16px;margin-bottom:24px;flex-wrap:wrap}.portal-settings .preview-form{display:flex;gap:8px;align-items:center}.portal-settings .preview-form input{width:125px}.portal-settings section{border-top:1px solid #dce2e8;padding:22px 0;margin-bottom:8px}.portal-settings h2{font-size:17px;margin:0 0 20px}.portal-settings .field{display:grid;grid-template-columns:230px minmax(0,600px);gap:20px;align-items:start;margin-bottom:16px}.portal-settings .field>label{padding-top:6px;color:#667684}.portal-settings .status-table{width:100%;min-width:650px}.portal-settings .status-table td,.portal-settings .status-table th{padding:12px 10px;vertical-align:middle;border-bottom:1px solid #e0e6eb}.portal-settings .status-table th{font-size:12px;color:#78858e;font-weight:600}.portal-settings .status-table .public-fields{min-width:200px}.portal-settings .savebar{display:flex;justify-content:flex-end;padding:18px 0;border-top:1px solid #dce2e8}.portal-settings .flags{display:flex;flex-wrap:wrap;gap:16px 24px}.portal-settings .flag{display:flex;align-items:center;gap:8px}.portal-settings .color-input{width:52px;height:36px}.portal-settings .table-scroll{overflow-x:auto}@media(max-width:650px){.portal-settings .field{grid-template-columns:1fr;gap:5px}.portal-settings .field>label{padding:0}.portal-settings .heading{align-items:flex-start}.portal-settings .preview-form{flex-wrap:wrap}}
</style>
<div class="portal-settings">
    <div class="heading"><h1>Strony informacyjne</h1><form class="preview-form" method="GET" action="{{ route('customer-portal.preview-select') }}" target="_blank"><label for="portal-preview-order" class="visually-hidden">Numer zamówienia</label><input class="form-control form-control-sm" type="number" min="1" name="order" id="portal-preview-order" placeholder="Nr zamówienia" required><button class="btn btn-outline-secondary btn-sm" type="submit"><i class="bi bi-eye" aria-hidden="true"></i> Podgląd</button></form></div>
    @if(!$ready)<div class="alert alert-warning">Przed konfiguracją wykonaj migrację PORTAL.1.</div>@else
    @if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
    @if($errors->any())<div class="alert alert-danger" role="alert">@foreach($errors->all() as $error)<div>{{ $error }}</div>@endforeach</div>@endif
    <form method="POST" action="{{ route('customer-portal.settings.update') }}" enctype="multipart/form-data">
        @csrf @method('PUT')<input type="hidden" name="expected_revision" value="{{ $settings->exists ? $settings->revision : 0 }}">
        <section><h2>Ustawienia ogólne</h2>
            <div class="field"><label for="portal-enabled">Udostępnianie stron</label><div class="form-check form-switch pt-1"><input type="hidden" name="is_enabled" value="0"><input class="form-check-input" type="checkbox" role="switch" id="portal-enabled" name="is_enabled" value="1" @checked(old('is_enabled',$settings->is_enabled))></div></div>
            <div class="field"><label for="portal-name">Nazwa sklepu</label><input class="form-control form-control-sm" id="portal-name" name="store_name" maxlength="120" value="{{ old('store_name',$settings->store_name) }}" required></div>
            <div class="field"><label for="portal-days">Dostępność od daty zakupu</label><div class="input-group input-group-sm"><input class="form-control" id="portal-days" name="availability_days" type="number" min="1" max="365" value="{{ old('availability_days',$settings->availability_days) }}" required><span class="input-group-text">dni</span></div></div>
            <div class="field"><label for="portal-color">Kolor przewodni</label><input type="color" class="form-control form-control-color color-input" id="portal-color" name="accent_color" value="{{ old('accent_color',$settings->accent_color) }}"></div>
            <div class="field"><label for="portal-logo">Logo</label><div><input class="form-control form-control-sm" type="file" accept="image/jpeg,image/png,image/webp" id="portal-logo" name="logo">@if($settings->logo_path)<label class="flag mt-2"><input class="form-check-input mt-0" type="checkbox" name="remove_logo" value="1">Usuń obecne logo</label>@endif</div></div>
        </section>
        <section><h2>Domena i ochrona danych</h2>
            @if($privacyReady)
                <input type="hidden" name="privacy_settings_present" value="1">
                <div class="field"><label for="portal-domain">Domena strony dla klientów</label><input class="form-control form-control-sm" id="portal-domain" name="customer_domain" maxlength="253" placeholder="klient.multi-click.pl" value="{{ old('customer_domain', $settings->customer_domain) }}"></div>
                <div class="field"><label>Adres strony</label><span class="text-break">{{ $portalBaseUrl }}/222/a7Q2m9K4xP</span></div>
                @foreach(['mask_personal_data' => 'Maskuj dane przed weryfikacją', 'verify_documents' => 'Wymagaj weryfikacji do pobierania dokumentów'] as $field => $label)
                    <div class="field"><label for="portal-{{ $field }}">{{ $label }}</label><div class="form-check form-switch pt-1"><input type="hidden" name="{{ $field }}" value="0"><input class="form-check-input" type="checkbox" role="switch" id="portal-{{ $field }}" name="{{ $field }}" value="1" @checked(old($field, $settings->{$field} ?? true))></div></div>
                @endforeach
                <div class="field"><label for="portal-verification-method">Akceptowane dane</label><select class="form-select form-select-sm" id="portal-verification-method" name="verification_method">
                    @foreach(['any' => 'E-mail lub imię i nazwisko / firma', 'email' => 'Adres e-mail', 'name_company' => 'Imię i nazwisko / firma'] as $value => $label)
                        <option value="{{ $value }}" @selected(old('verification_method', $settings->verificationMethod()) === $value)>{{ $label }}</option>
                    @endforeach
                </select></div>
            @else
                <div class="alert alert-warning">Ochrona danych i własna domena wymagają migracji PORTAL.2B.</div>
            @endif
        </section>
        <section><h2>Kontakt i treść</h2>
            <div class="field"><label for="portal-email">E-mail kontaktowy</label><input class="form-control form-control-sm" type="email" id="portal-email" name="contact_email" value="{{ old('contact_email',$settings->contact_email) }}"></div>
            <div class="field"><label for="portal-phone">Telefon kontaktowy</label><input class="form-control form-control-sm" id="portal-phone" name="contact_phone" maxlength="60" value="{{ old('contact_phone',$settings->contact_phone) }}"></div>
            <div class="field"><label for="portal-message">Informacja dla klienta</label><textarea class="form-control form-control-sm" id="portal-message" name="customer_message" maxlength="2000" rows="4">{{ old('customer_message',$settings->customer_message) }}</textarea></div>
        </section>
        <section><h2>Widoczne informacje</h2><div class="flags">
            @foreach(['show_images'=>'Miniatury produktów','show_address'=>'Adres dostawy i dane nabywcy','show_invoices'=>'Faktury VAT','show_corrections'=>'Zamknięte korekty','show_proformas'=>'Aktualna pro forma'] as $field=>$label)
                <input type="hidden" name="{{ $field }}" value="0"><label class="flag"><input class="form-check-input mt-0" type="checkbox" name="{{ $field }}" value="1" @checked(old($field,$settings->{$field}))>{{ $label }}</label>
            @endforeach
        </div></section>
        <section><h2>Płatności PayNow</h2>
            @if($paymentsReady)
                <input type="hidden" name="paynow_settings_present" value="1">
                @foreach(['paynow_enabled' => 'Płatności w portalu', 'paynow_retry_enabled' => 'Ponawianie nieudanych płatności'] as $field => $label)
                    <div class="field"><label for="portal-{{ $field }}">{{ $label }}</label><div class="form-check form-switch pt-1"><input type="hidden" name="{{ $field }}" value="0"><input class="form-check-input" type="checkbox" role="switch" id="portal-{{ $field }}" name="{{ $field }}" value="1" @checked(old($field, $settings->{$field} ?? false))></div></div>
                @endforeach
                <div class="field"><label>Źródła zamówień</label><div class="flags">
                    @foreach(['manual' => 'Ręczne', 'prestashop' => 'PrestaShop'] as $source => $label)
                        <label class="flag"><input class="form-check-input mt-0" type="checkbox" name="paynow_sources[]" value="{{ $source }}" @checked(in_array($source, old('paynow_settings_present') ? old('paynow_sources', []) : ($settings->paynow_sources ?? ['manual']), true))>{{ $label }}</label>
                    @endforeach
                </div></div>
            @else
                <div class="alert alert-warning">Płatności wymagają migracji PORTAL.2.</div>
            @endif
        </section>
        <section><h2>Etapy widoczne dla klienta</h2><div class="table-scroll"><table class="status-table"><thead><tr><th>Status OMS</th><th class="text-center">Pokaż</th><th>Nazwa dla klienta</th><th>Komunikat</th></tr></thead><tbody>
            @foreach($statuses as $index=>$status) @php($mapping=$mappings->get($status->id))
                <tr><td><input type="hidden" name="statuses[{{ $index }}][id]" value="{{ $status->id }}">{{ $status->short_name ?: $status->status }}</td><td class="text-center"><input type="hidden" name="statuses[{{ $index }}][is_visible]" value="0"><input class="form-check-input" aria-label="Udostępnij etap {{ $status->short_name }}" type="checkbox" name="statuses[{{ $index }}][is_visible]" value="1" @checked(old("statuses.$index.is_visible",$mapping?->is_visible))></td><td class="public-fields"><input class="form-control form-control-sm" aria-label="Nazwa publiczna {{ $status->short_name }}" name="statuses[{{ $index }}][public_name]" maxlength="120" value="{{ old("statuses.$index.public_name",$mapping?->public_name) }}"></td><td class="public-fields"><textarea class="form-control form-control-sm" aria-label="Komunikat publiczny {{ $status->short_name }}" name="statuses[{{ $index }}][public_message]" maxlength="1000" rows="2">{{ old("statuses.$index.public_message",$mapping?->public_message) }}</textarea></td></tr>
            @endforeach
        </tbody></table></div></section>
        <div class="savebar"><button class="btn btn-primary btn-sm" type="submit"><i class="bi bi-check-lg" aria-hidden="true"></i> Zapisz</button></div>
    </form>
    <section><h2>Link konkretnego zamówienia</h2>
        <form method="GET" action="{{ route('customer-portal.settings') }}" class="preview-form mb-3"><label for="portal-managed-order">Numer zamówienia</label><input type="number" class="form-control form-control-sm" id="portal-managed-order" name="order" min="1" value="{{ $managedPortalOrder?->id }}" required><button type="submit" class="btn btn-outline-secondary btn-sm">Wybierz</button></form>
        @if($managedPortalOrder)
            <p>Zamówienie <a href="{{ route('orders.show', $managedPortalOrder) }}">{{ $managedPortalOrder->id }}</a></p>
            @if($managedPortalData['url'])
                <p class="text-break"><a href="{{ $managedPortalData['url'] }}" target="_blank" rel="noopener noreferrer">{{ $managedPortalData['url'] }}</a></p>
            @endif
            @if(!$managedPortalData['active'])
                <p class="text-secondary">{{ $managedPortalData['reason'] }}</p>
            @endif
            <div class="d-flex flex-wrap gap-2">
                @if($settings->is_enabled && !$managedPortalData['active'])
                    <form method="POST" action="{{ route('customer-portal.issue', $managedPortalOrder) }}" onsubmit="return confirm('Włączyć dostęp do strony tego zamówienia pod tym samym adresem?');">@csrf<input type="hidden" name="confirm" value="1"><input type="hidden" name="activate" value="1"><input type="hidden" name="expected_generation" value="{{ $managedPortalLink?->generation ?? 0 }}"><button class="btn btn-outline-primary btn-sm" type="submit"><i class="bi bi-toggle-on" aria-hidden="true"></i> Włącz dostęp</button></form>
                @endif
                @if($managedPortalLink && !$managedPortalLink->revoked_at)
                    <form method="POST" action="{{ route('customer-portal.revoke', $managedPortalOrder) }}" onsubmit="return confirm('Wyłączyć dostęp do strony tego zamówienia? Adres pozostanie bez zmian.');">@csrf<input type="hidden" name="confirm" value="1"><input type="hidden" name="expected_generation" value="{{ $managedPortalLink->generation }}"><button class="btn btn-outline-danger btn-sm" type="submit"><i class="bi bi-toggle-off" aria-hidden="true"></i> Wyłącz dostęp</button></form>
                @endif
            </div>
        @endif
    </section>
    @endif
</div>
@endsection
