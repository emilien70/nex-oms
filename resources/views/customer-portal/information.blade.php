<!doctype html>
<html lang="pl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow,noarchive">
    <link rel="icon" href="data:,">
    <title>Informacje dla klientów | {{ $information['seller'] }}</title>
    <style nonce="{{ $nonce }}">
        * { box-sizing: border-box; }
        body { margin: 0; background: #fff; color: #26343c; font: 15px/1.6 system-ui, -apple-system, "Segoe UI", sans-serif; }
        .inner { width: min(100% - 36px, 720px); margin-inline: auto; }
        header { border-bottom: 1px solid #e1e6e9; padding-block: 18px; font-size: 18px; font-weight: 700; }
        main { padding-block: 26px 48px; }
        h1 { margin: 0 0 18px; font-size: 27px; line-height: 1.25; }
        h2 { margin: 0 0 10px; font-size: 17px; }
        section { padding-block: 20px; border-top: 1px solid #e1e6e9; }
        p, dl { margin: 0; }
        dt { font-weight: 600; }
        dd { margin: 0; min-width: 0; }
        dl { display: grid; grid-template-columns: minmax(0, 125px) minmax(0, 1fr); gap: 6px 14px; }
        a { color: #005ea2; overflow-wrap: anywhere; text-underline-offset: 3px; }
        a:focus-visible { outline: 3px solid #92c8ec; outline-offset: 4px; }
        .customer-information-links { display: flex; flex-wrap: wrap; gap: 8px 20px; }
        @media (max-width: 420px) { dl { grid-template-columns: minmax(0, 1fr); gap: 3px; } dd { margin-bottom: 9px; } }
    </style>
</head>
<body>
    <header><div class="inner">{{ $information['seller'] }}</div></header>
    <main class="inner">
        <h1>Informacje dla klientów</h1>
        <section>
            <h2>Dane sprzedawcy</h2>
            <dl>
                <dt>Nazwa</dt><dd>{{ $information['seller'] }}</dd>
                <dt>Adres</dt><dd>{{ $information['address'] }}</dd>
                <dt>NIP</dt><dd>{{ $information['tax_id'] }}</dd>
                <dt>REGON</dt><dd>{{ $information['regon'] }}</dd>
                <dt>E-mail</dt><dd><a href="mailto:{{ $information['email'] }}">{{ $information['email'] }}</a></dd>
                <dt>Telefon</dt><dd><a href="tel:{{ preg_replace('/[^+0-9]/', '', $information['phone']) }}">{{ $information['phone'] }}</a></dd>
            </dl>
        </section>
        <section>
            <h2>Płatność na portalu</h2>
            <p>Jeśli przy zamówieniu jest dostępny przycisk płatności, możesz zapłacić przez Paynow. Płatność obsługuje mElements S.A.</p>
        </section>
        <section>
            <h2>Dokumenty i zasady sprzedaży</h2>
            @include('customer-portal.information-links', ['includeInformationPage' => false])
        </section>
    </main>
</body>
</html>
