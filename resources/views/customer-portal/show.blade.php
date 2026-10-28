@php
    $money = static function ($value): string {
        [$whole, $fraction] = array_pad(explode('.', (string) $value, 2), 2, '00');
        return preg_replace('/\B(?=(\d{3})+(?!\d))/', ' ', $whole).','.str_pad(substr($fraction, 0, 2), 2, '0');
    };
@endphp
<!doctype html>
<html lang="pl">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow,noarchive"><link rel="icon" href="data:,">
    <title>{{ $data ? 'Zamówienie '.$data['number'].' - '.$data['storeName'] : 'Strona zamówienia' }}</title>
    <style nonce="{{ $nonce }}">
        *{box-sizing:border-box}body{margin:0;background:#fff;color:#26343c;font:15px/1.55 system-ui,-apple-system,"Segoe UI",sans-serif;--accent:{{ $data['accent'] ?? '#0075c9' }}}
        a{color:var(--accent);text-underline-offset:3px}a:focus-visible{outline:3px solid #92c8ec;outline-offset:4px}header{border-bottom:1px solid #e1e6e9}.inner{width:min(100% - 40px,1040px);margin:auto}
        .brandbar{min-height:86px;display:flex;align-items:center;justify-content:space-between;gap:20px}.brand{font-size:21px;font-weight:700;color:#24343d;overflow-wrap:anywhere}.logo{max-width:210px;max-height:60px;object-fit:contain}.service{font-size:13px;color:#77838a;text-align:right}
        main{padding:34px 0 56px}.eyebrow{color:#7a858c;font-size:12px;font-weight:600;text-transform:uppercase}h1{margin:5px 0 4px;font-size:28px;line-height:1.3}h2{font-size:18px;font-weight:650;margin:0 0 18px}p{margin:0 0 12px}.muted{color:#74818a}.date{font-size:13px;color:#74818a}
        .preview{padding:12px 20px;background:#fff1d8;border-bottom:1px solid #efd4a0;color:#795014;text-align:center;font-weight:600}.stage{margin:28px 0 30px;padding:22px 24px;background:#f2f7fa;border-left:4px solid var(--accent)}.stage strong{display:block;font-size:22px;font-weight:650;overflow-wrap:anywhere}.stage p{margin:6px 0 0;color:#52636f;white-space:pre-line;overflow-wrap:anywhere}
        .layout{display:grid;grid-template-columns:minmax(0,1fr) 300px;gap:42px}.layout>*{min-width:0}section{padding:0 0 26px;margin-bottom:26px;border-bottom:1px solid #e3e8eb}.item{display:grid;grid-template-columns:56px minmax(0,1fr) auto;gap:15px;padding:17px 0;border-top:1px solid #e8ecef;align-items:start}.item img{width:56px;height:56px;object-fit:contain}.item.no-image{grid-template-columns:minmax(0,1fr) auto}.item-name{font-weight:600;overflow-wrap:anywhere}.item-meta{font-size:13px;color:#74818a;margin-top:5px}.price{white-space:nowrap;font-weight:650}.totals{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:8px 14px;margin:18px 0 0}.totals dt{color:#6b7982}.totals dd{margin:0;white-space:nowrap;text-align:right}.totals .total{border-top:1px solid #e3e8eb;padding-top:12px;font-weight:700;font-size:17px;color:#26343c}
        .payment-state{display:inline-block;padding:3px 9px;background:#edf2f5;color:#4c606c;border-radius:4px;font-size:12px;font-weight:650;margin-bottom:14px}.payment-state.paid{background:#e5f3e9;color:#227343}.detail{margin:0 0 13px}.detail small{display:block;color:#75828b;font-size:12px}.detail span{overflow-wrap:anywhere}.shipment{padding:15px 0;border-top:1px solid #e6ebee}.shipment strong{font-size:14px}.tracking{display:block;margin:4px 0 8px;overflow-wrap:anywhere}.action{display:inline-block;text-decoration:none;border:1px solid #cbd5dc;border-radius:5px;padding:8px 12px;font-size:13px;font-weight:600;min-height:38px}.document{padding:15px 0;border-top:1px solid #e6ebee;display:flex;align-items:center;justify-content:space-between;gap:14px}.document>div{min-width:0;overflow-wrap:anywhere}.document small{display:block;font-size:12px;color:#75828b}.notice{white-space:pre-line;overflow-wrap:anywhere;color:#536671}.contact a{display:block;overflow-wrap:anywhere;margin-bottom:5px}.empty{color:#74818a;font-size:14px}.footer{border-top:1px solid #e1e6e9;padding:20px 0;color:#859098;font-size:12px}.unavailable{max-width:600px;padding:56px 0 100px}.unavailable h1{margin:0 0 14px;font-size:26px}.unavailable p{color:#697b85}
        .payment-actions{display:grid;gap:10px;margin-top:20px}.payment-actions form{margin:0}.payment-actions button{width:100%;font-family:inherit;line-height:1.4;color:var(--accent);background:#fff;cursor:pointer;white-space:normal}.payment-actions .primary{background:var(--accent);border-color:var(--accent);color:#fff}.payment-message{font-size:13px;margin-top:16px}.payment-error{color:#a1323d}.sandbox{display:block;font-size:12px;color:#8a5a16;margin-top:12px}button:focus-visible{outline:3px solid #92c8ec;outline-offset:3px}
        @media(max-width:760px){.layout{grid-template-columns:minmax(0,1fr);gap:0}.brandbar{min-height:74px}.logo{max-width:165px;max-height:52px}.service{max-width:110px}main{padding-top:24px}.stage{padding:18px;margin:22px 0 28px}.stage strong{font-size:20px}h1{font-size:25px}.item{grid-template-columns:48px minmax(0,1fr);gap:12px}.item img{width:48px;height:48px}.item .price{grid-column:2}.item.no-image{grid-template-columns:minmax(0,1fr) auto}.item.no-image .price{grid-column:auto}.document{align-items:flex-start}.inner{width:calc(100% - 36px)}}
        .verification{max-width:760px;padding:24px 0 48px}.verification h1{font-size:22px;margin-bottom:18px}.verification form{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:12px;align-items:end}.verification label{display:block;font-size:14px;margin-bottom:8px}.verification input{width:100%;min-width:0;border:1px solid #bfcbd3;border-radius:4px;padding:10px 12px;font:inherit}.verification button{background:var(--accent);color:#fff;border-color:var(--accent);font-family:inherit;cursor:pointer}.verification .payment-error{margin:14px 0}.verification .back{display:inline-block;margin-top:20px}.personal{overflow-wrap:anywhere}@media(max-width:480px){.verification form{grid-template-columns:minmax(0,1fr)}.verification button{width:100%}}
        .personal-lines>span,.personal-lines>a{display:block}.personal-lines>a{width:fit-content;max-width:100%}
    </style>
</head>
<body>
    @if($data['preview'] ?? false)
        <div class="preview">Podgląd administratora — nie udostępnia zamówienia klientowi</div>
    @endif
    <header><div class="inner brandbar"><div class="brand">
        @if($data['logoUrl'] ?? null)
            <img class="logo" src="{{ $data['logoUrl'] }}" alt="{{ $data['storeName'] }}">
        @else
            {{ $data['storeName'] ?? 'NEX-OMS' }}
        @endif
    </div><div class="service">Informacje o zamówieniu</div></div></header>
    <main class="inner">
        @if(!$data)
            <div class="unavailable"><h1>Strona zamówienia jest niedostępna</h1><p>Link jest nieprawidłowy, wygasł lub został wyłączony. Skontaktuj się ze sprzedawcą.</p></div>
        @elseif($data['challenge'])
            <div class="verification"><h1>Zamówienie {{ $data['number'] }}</h1>
                <form method="POST" action="{{ $data['challenge']['action'] }}">
                    @csrf
                    @if($data['challenge']['invoice'])
                        <input type="hidden" name="invoice" value="{{ $data['challenge']['invoice'] }}">
                    @endif
                    <div><label for="portal-value">
                        @if($data['challenge']['method'] === 'email')
                            Podaj adres e-mail z zamówienia.
                        @elseif($data['challenge']['method'] === 'name_company')
                            Podaj imię i nazwisko albo nazwę firmy z zamówienia.
                        @else
                            Podaj adres e-mail, imię i nazwisko albo nazwę firmy z zamówienia.
                        @endif
                    </label><input id="portal-value" name="portal_value" type="text" maxlength="254" autocomplete="off" autocapitalize="none" spellcheck="false" required></div>
                    <button type="submit" class="action">{{ $data['challenge']['invoice'] ? 'Pobierz dokument' : 'Wyświetl pełne dane' }}</button>
                </form>
                @if($data['challenge']['error'])
                    <p class="payment-error" role="alert">{{ $data['challenge']['error'] }}</p>
                @endif
                <a class="back" href="{{ $data['challenge']['back'] }}">Wróć do zamówienia</a>
            </div>
        @else
            <div class="eyebrow">Twoje zamówienie</div><h1>Zamówienie {{ $data['number'] }}</h1><div class="date">Złożone {{ $data['date'] }}</div>
            <div class="stage"><strong>{{ $data['stage'] }}</strong><p>{{ $data['stageMessage'] }}</p></div>
            <div class="layout"><div>
                <section><h2>Produkty</h2>
                    @forelse($data['items'] as $item)
                        <div class="item {{ $item['image'] ? '' : 'no-image' }}">@if($item['image'])<img src="{{ $item['image'] }}" alt="" loading="lazy">@endif<div><div class="item-name">{{ $item['name'] }}</div><div class="item-meta">{{ $item['quantity'] }} szt. × {{ $money($item['price']) }} {{ $data['currency'] }}</div></div><div class="price">{{ $money($item['total']) }} {{ $data['currency'] }}</div></div>
                    @empty<p class="empty">Pozycje zamówienia nie są jeszcze dostępne.</p>@endforelse
                    <dl class="totals"><dt>Dostawa</dt><dd>{{ $money($data['delivery']) }} {{ $data['currency'] }}</dd><dt class="total">Razem</dt><dd class="total">{{ $money($data['total']) }} {{ $data['currency'] }}</dd></dl>
                </section>
                <section><h2>Przesyłki</h2>@if($data['shippingMethod'])<p class="muted">{{ $data['shippingMethod'] }}</p>@endif
                    @forelse($data['shipments'] as $shipment)
                        <div class="shipment"><div class="muted">{{ $shipment['carrier'] }}</div><strong>{{ $shipment['status'] }}</strong><span class="tracking">{{ $shipment['number'] }}</span>
                            @if($shipment['tracking'])
                                <a class="action" href="{{ $shipment['tracking'] }}" target="_blank" rel="noopener noreferrer">Śledź przesyłkę</a>
                            @endif
                            @if($shipment['date'])
                                <div class="date">{{ $shipment['date'] }}</div>
                            @endif
                        </div>
                    @empty
                        <p class="empty">Numer przesyłki pojawi się po jej przygotowaniu.</p>
                    @endforelse
                </section>
                <section><h2>Dokumenty</h2>@forelse($data['documents'] as $document)<div class="document"><div><strong>{{ $document['number'] }}</strong><small>{{ $document['type'] }} · {{ $document['date'] }}</small></div><a class="action" href="{{ $document['url'] }}">Pobierz PDF</a></div>@empty<p class="empty">Nie ma jeszcze dokumentów dostępnych do pobrania.</p>@endforelse</section>
            </div><aside>
                <section><h2>Płatność</h2><span class="payment-state {{ $data['paymentLabel'] === 'Opłacone' ? 'paid' : '' }}">{{ $data['paymentLabel'] }}</span><div class="detail"><small>Sposób płatności</small><span>{{ $data['paymentMethod'] }}</span></div><dl class="totals"><dt>Wpłacono</dt><dd>{{ $money($data['paid']) }} {{ $data['currency'] }}</dd><dt>Pozostało</dt><dd>{{ $money($data['remaining']) }} {{ $data['currency'] }}</dd></dl>
                    @if($data['paynow']['transactionId'])
                        <div class="detail payment-message"><small>ID transakcji</small><span>{{ $data['paynow']['transactionId'] }}</span></div>
                    @endif
                    @if($data['paynow']['message'])
                        <p class="payment-message">{{ $data['paynow']['message'] }}</p>
                    @endif
                    @if(session('portal_payment_error') && !$data['preview'])
                        <p class="payment-message payment-error" role="alert">{{ session('portal_payment_error') }}</p>
                    @endif
                    @if($data['paynow']['isTest'])
                        <span class="sandbox">Płatność testowa — PayNow Sandbox</span>
                    @endif
                    <div class="payment-actions">
                        @foreach($data['paynow']['actions'] as $action)
                            <form method="POST" action="{{ $action['url'] }}">@csrf<input type="hidden" name="intent" value="{{ $action['intent'] }}"><button type="submit" class="action {{ $action['primary'] ? 'primary' : '' }}">{{ $action['label'] }}</button></form>
                        @endforeach
                    </div>
                </section>
                @foreach(['address' => 'Adres dostawy', 'buyer' => 'Dane nabywcy'] as $field => $label)
                    @if($data[$field])
                        @php
                            $personal = $data[$field];
                            $streetLine = \App\Support\AddressLineFormatter::formatAddressLine($personal->street, $personal->building_number, $personal->apartment_number);
                            $postalCity = \App\Support\AddressLineFormatter::formatPostalCity($personal->postal_code, $personal->city);
                            $localityLine = implode(', ', array_filter([$postalCity, $personal->country_name], fn ($value) => $value !== null && $value !== ''));
                        @endphp
                        <section class="personal"><h2>{{ $label }}</h2><p class="personal-lines">
                            @if($personal->name !== '')<span>{{ $personal->name }}</span>@endif
                            @if($personal->company_name !== '')<span>{{ $personal->company_name }}</span>@endif
                            @if($personal->point_id !== '')<span>ID punktu: {{ $personal->point_id }}</span>@endif
                            @if($streetLine !== null)<span>{{ $streetLine }}</span>@endif
                            @if($localityLine !== '')<span>{{ $localityLine }}</span>@endif
                            @if($personal->tax_id !== '')<span>NIP: {{ $personal->tax_id }}</span>@endif
                            @if($field === 'address' && $personal->phone)
                                @if($personal->phone_url)
                                    <a href="{{ $personal->phone_url }}">{{ $personal->phone }}</a>
                                @else
                                    <span>{{ $personal->phone }}</span>
                                @endif
                            @endif
                        </p>
                        @if($field === 'buyer' && $data['detailsUrl'])
                            <a class="action" href="{{ $data['detailsUrl'] }}">Wyświetl pełne dane</a>
                        @endif
                        </section>
                    @endif
                @endforeach
                @if($data['message'])<section><h2>Informacja od sprzedawcy</h2><p class="notice">{{ $data['message'] }}</p></section>@endif
                @if($data['contactEmail'] || $data['contactPhone'])
                    <section class="contact"><h2>Kontakt</h2>
                        @if($data['contactEmail'])
                            <a href="mailto:{{ $data['contactEmail'] }}">{{ $data['contactEmail'] }}</a>
                        @endif
                        @if($data['contactPhone'])
                            <a href="tel:{{ preg_replace('/[^0-9+]/','',$data['contactPhone']) }}">{{ $data['contactPhone'] }}</a>
                        @endif
                    </section>
                @endif
            </aside></div>
        @endif
    </main><footer class="footer"><div class="inner">{{ $data['storeName'] ?? 'NEX-OMS' }} · Informacje o zamówieniu</div></footer>
    @if($data['paynow']['actions'] ?? [])
        <script nonce="{{ $nonce }}">
            document.querySelectorAll('.payment-actions form').forEach(form => form.addEventListener('submit', () => {
                document.querySelectorAll('.payment-actions button').forEach(button => button.disabled = true);
            }));
            window.addEventListener('pageshow', () => {
                document.querySelectorAll('.payment-actions button').forEach(button => button.disabled = false);
            });
        </script>
    @endif
</body>
</html>
