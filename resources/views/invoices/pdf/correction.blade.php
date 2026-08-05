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
                    <tr><td>Powód wystawienia:</td><td align="left">{{ $document['reason'] }}</td></tr>
                </table>
            </td>
            <td width="10%"></td>
            <td width="45%">
                <table class="meta-table" cellpadding="2" cellspacing="0" width="100%">
                    <tr><td width="42%">Miejsce wystawienia:</td><td width="58%" align="center">{{ $document['place_of_issue'] ?: '-' }}</td></tr>
                    <tr><td>Sposób płatności:</td><td align="center">{{ $document['payment_method'] ?: '-' }}</td></tr>
                </table>
            </td>
        </tr>
    </table>
    <br><br><br>
    <span style="font-size: 3pt; line-height: 3pt;"><br></span>

    @include('invoices.pdf.partials.parties')

    <div class="unicode-heading-font correction-section">Było:</div>
    @include('invoices.pdf.partials.items-table', ['rows' => $document['before_items']])
    <br><br>
    <div class="unicode-heading-font correction-section">Powinno być:</div>
    @include('invoices.pdf.partials.items-table', ['rows' => $document['after_items']])

    <br><br>
    <div class="unicode-heading-font correction-section">Podsumowanie:</div>
    <table class="summary correction-summary" cellpadding="1" cellspacing="0" width="98%" align="center">
        <tr>
            <td width="50%" class="summary-heading"></td>
            <td width="15%" class="summary-heading" align="center">Wart. netto</td>
            <td width="5%" class="summary-heading"></td>
            <td width="15%" class="summary-heading" align="center">VAT</td>
            <td width="15%" class="summary-heading" align="center">Wart. brutto</td>
        </tr>
        <tr>
            <td width="50%" align="right">W tym:</td>
            <td width="15%">{{ $document['difference_totals']['net'] }}<br>{{ $document['currency'] }}</td>
            <td width="5%" align="center">{{ $document['difference_vat_label'] }}</td>
            <td width="15%">{{ $document['difference_totals']['vat'] }}<br>{{ $document['currency'] }}</td>
            <td width="15%">{{ $document['difference_totals']['gross'] }}<br>{{ $document['currency'] }}</td>
        </tr>
        <tr>
            <td width="50%" align="right">Razem:</td>
            <td width="15%">{{ $document['difference_totals']['net'] }}<br>{{ $document['currency'] }}</td>
            <td width="5%"></td>
            <td width="15%">{{ $document['difference_totals']['vat'] }}<br>{{ $document['currency'] }}</td>
            <td width="15%">{{ $document['difference_totals']['gross'] }}<br>{{ $document['currency'] }}</td>
        </tr>
    </table>

    <br>
    <table class="correction-adjustment-summary" cellpadding="1" cellspacing="0" width="65%">
        <tr><td width="77%" align="right">{{ $document['difference_labels']['net'] }}:</td><td width="23%" align="right">{{ $document['difference_magnitudes']['net'] }} {{ $document['currency'] }}</td></tr>
        <tr><td align="right">{{ $document['difference_labels']['vat'] }}:</td><td align="right">{{ $document['difference_magnitudes']['vat'] }} {{ $document['currency'] }}</td></tr>
        <tr><td align="right">{{ $document['difference_labels']['gross'] }}:</td><td align="right">{{ $document['difference_magnitudes']['gross'] }} {{ $document['currency'] }}</td></tr>
    </table>

    <br><br><br>
    <table class="final-details" cellpadding="0" cellspacing="0" width="100%">
        <tr>
            <td width="15%" class="muted-label grand-total-label">Razem:</td>
            <td width="2%"></td>
            <td width="38%" class="grand-total">{{ $document['difference_totals']['gross'] }} {{ $document['currency'] }}</td>
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

    @if ($document['issuer_name'])
        <br><br><br>
        <table cellpadding="0" cellspacing="0" width="100%">
            <tr>
                <td width="58%"></td>
                <td width="42%" align="right" class="issuer-block">
                    Osoba upoważniona do wystawienia dokumentu<br>
                    {{ $document['issuer_name'] }}
                </td>
            </tr>
        </table>
    @endif

    @if ($document['additional_information'])
        <br><br>
        <div class="additional-information">{!! nl2br(e($document['additional_information'])) !!}</div>
    @endif
</body>
</html>
