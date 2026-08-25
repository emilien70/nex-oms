<!doctype html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    @include('invoices.pdf.partials.styles')
</head>
<body>
    @include('invoices.pdf.partials.header')

    <table cellpadding="0" cellspacing="0" width="100%">
        <tr>
            <td width="45%">
                <table class="meta-table" cellpadding="2" cellspacing="0" width="100%">
                    <tr><td width="60%">Data sprzedaży:</td><td width="40%" align="left">{{ $document['sale_date'] }}</td></tr>
                    <tr><td>Data wystawienia:</td><td align="left">{{ $document['issue_date'] }}</td></tr>
                    @if ($document['payment_due_date'])
                        <tr><td>Termin płatności:</td><td align="left">{{ $document['payment_due_date'] }}</td></tr>
                    @endif
                </table>
            </td>
            <td width="10%"></td>
            <td width="45%">
                <table class="meta-table" cellpadding="2" cellspacing="0" width="100%">
                    <tr><td width="42%">Miejsce wystawienia:</td><td width="58%" align="center">{{ $document['place_of_issue'] ?: '-' }}</td></tr>
                    <tr>
                        <td>Sposób płatności:</td>
                        <td align="center">{{ $document['payment_method'] ?: '-' }}</td>
                    </tr>
                    <tr><td>Numer zamówienia:</td><td align="center">{{ $document['order_number'] ?: '-' }}</td></tr>
                    @if ($document['ksef'])
                        <tr><td>Numer KSeF:</td><td align="center" class="ksef-meta-value">{{ $document['ksef']['number'] }}</td></tr>
                        <tr><td>Data przetworzenia w KSeF:</td><td align="center">{{ $document['ksef']['processed_at'] ?: '-' }}</td></tr>
                        <tr><td>Status KSeF:</td><td align="center">{{ $document['ksef']['status'] }}</td></tr>
                    @endif
                </table>
            </td>
        </tr>
    </table>
    <br><br><br>
    <span style="font-size: 3pt; line-height: 3pt;"><br></span>

    @include('invoices.pdf.partials.parties')
    @include('invoices.pdf.partials.items-table')
    @include('invoices.pdf.partials.summary')

    <br><br><br>
    <table class="final-details" cellpadding="0" cellspacing="0" width="100%">
        <tr>
            <td width="15%" class="muted-label grand-total-label">Razem:</td>
            <td width="2%"></td>
            <td width="38%" class="grand-total">{{ $document['totals']['gross'] }} {{ $document['currency'] }}</td>
            <td width="45%"></td>
        </tr>
    </table>

    <br><br>
    <table class="final-details" cellpadding="0" cellspacing="0" width="100%">
        <tr>
            <td width="15%" class="muted-label">Słownie:</td>
            <td width="2%"></td>
            <td width="83%" class="final-value">{{ $document['amount_in_words'] }}</td>
        </tr>
    </table>

    @if ($document['pln_conversion'])
        <br><br>
        <table class="final-details exchange-rate" cellpadding="0" cellspacing="0" width="100%">
            <tr>
                <td width="15%" class="muted-label">Kurs waluty:</td>
                <td width="2%"></td>
                <td width="38%" class="final-value">
                    {{ $document['pln_conversion']['rate_text'] }}<br>
                    {{ $document['pln_conversion']['effective_date'] }} ({{ $document['pln_conversion']['table_number'] }})
                </td>
                <td width="45%"></td>
            </tr>
        </table>
    @endif

    @if ($document['payment_identifier'])
        <br><br><br>
        <table class="final-details" cellpadding="0" cellspacing="0" width="100%">
            <tr>
                <td width="15%" class="muted-label">Numer płatności</td>
                <td width="2%"></td>
                <td width="38%" class="final-value">{{ $document['payment_identifier'] }}</td>
                <td width="45%"></td>
            </tr>
        </table>
    @endif

    <br><br><br>
    <table cellpadding="0" cellspacing="0" width="100%">
        <tr>
            <td width="58%"></td>
            <td width="42%" align="right" class="issuer-block">
                @if ($document['issuer_name'])
                    Osoba upoważniona do wystawienia dokumentu<br>
                    {{ $document['issuer_name'] }}
                @endif
            </td>
        </tr>
    </table>

    @if ($document['additional_information'])
        <br><br>
        <div class="additional-information">{!! nl2br(e($document['additional_information'])) !!}</div>
    @endif

</body>
</html>
