<!doctype html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    @include('invoices.pdf.partials.styles')
</head>
<body>
    @include('invoices.pdf.partials.header')

    <div align="right">
        do faktury {{ $document['source_invoice']['number'] }} z dnia {{ $document['source_invoice']['issue_date'] }}
    </div>
    <br>
    <table cellpadding="0" cellspacing="0" width="100%">
        <tr>
            <td width="48%">
                <table class="meta-table" cellpadding="2" cellspacing="0" width="100%">
                    <tr><td width="60%">Data sprzedaży:</td><td width="40%" align="left">{{ $document['sale_date'] }}</td></tr>
                    <tr><td>Data wystawienia:</td><td align="left">{{ $document['issue_date'] }}</td></tr>
                    <tr><td>Powód wystawienia:</td><td align="left">{{ $document['reason'] }}</td></tr>
                </table>
            </td>
            <td width="7%"></td>
            <td width="45%">
                <table class="meta-table" cellpadding="2" cellspacing="0" width="100%">
                    <tr><td width="42%">Miejsce wystawienia:</td><td width="58%" align="center">{{ $document['place_of_issue'] ?: '-' }}</td></tr>
                    <tr><td>Sposób płatności:</td><td align="center">{{ $document['payment_lines'][0] ?? '-' }}</td></tr>
                </table>
            </td>
        </tr>
    </table>
    <br><br>

    @include('invoices.pdf.partials.parties')

    <div class="unicode-heading-font correction-section">Było:</div>
    @include('invoices.pdf.partials.items-table', ['rows' => $document['before_items']])
    <br>
    <div class="unicode-heading-font correction-section">Powinno być:</div>
    @include('invoices.pdf.partials.items-table', ['rows' => $document['after_items']])

    <br>
    <div class="unicode-heading-font correction-section">Podsumowanie:</div>
    <table class="summary" cellpadding="0" cellspacing="0" width="52%" align="right">
        <tr><td width="30%"></td><td width="23%"><strong>Wart. netto</strong></td><td width="23%"><strong>VAT</strong></td><td width="24%"><strong>Wart. brutto</strong></td></tr>
        <tr><td>W tym</td><td>{{ $document['difference_totals']['net'] }}</td><td>{{ $document['difference_totals']['vat'] }}</td><td>{{ $document['difference_totals']['gross'] }}</td></tr>
        <tr><td><strong>Razem</strong></td><td>{{ $document['difference_totals']['net'] }}</td><td>{{ $document['difference_totals']['vat'] }}</td><td>{{ $document['difference_totals']['gross'] }}</td></tr>
    </table>

    <br><br>
    <table class="difference" cellpadding="0" cellspacing="0" width="100%">
        <tr><td width="72%">{{ $document['difference_labels']['net'] }}</td><td width="28%" align="right">{{ $document['difference_totals']['net'] }} {{ $document['currency'] }}</td></tr>
        <tr><td>{{ $document['difference_labels']['vat'] }}</td><td align="right">{{ $document['difference_totals']['vat'] }} {{ $document['currency'] }}</td></tr>
        <tr><td><strong>{{ $document['difference_labels']['gross'] }}</strong></td><td align="right"><strong>{{ $document['difference_totals']['gross'] }} {{ $document['currency'] }}</strong></td></tr>
    </table>

    <br><br>
    <table cellpadding="0" cellspacing="0" width="100%">
        <tr>
            <td width="16%" class="muted-label">Razem:</td>
            <td width="39%" class="grand-total">{{ $document['difference_totals']['gross'] }} {{ $document['currency'] }}</td>
            <td width="45%"></td>
        </tr>
        <tr>
            <td class="muted-label">Słownie:</td>
            <td colspan="2">{{ $document['amount_in_words'] }}</td>
        </tr>
    </table>

    @if ($document['issuer_name'])
        <br><br><div align="right">Osoba upoważniona do wystawienia dokumentu<br>{{ $document['issuer_name'] }}</div>
    @endif
</body>
</html>
