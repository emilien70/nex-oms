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

    @if ($document['buyer_change'])
        <div class="unicode-heading-font correction-section correction-buyer-change-title">Dane nabywcy:</div>
        <table class="correction-buyer-change" cellpadding="3" cellspacing="0" width="98%" align="center">
            <tr>
                <td width="50%" class="correction-buyer-change-heading">Było:</td>
                <td width="50%" class="correction-buyer-change-heading">Powinno być:</td>
            </tr>
            <tr>
                <td width="50%" class="correction-buyer-change-details">
                    @foreach ($document['buyer_change']['before']['lines'] as $line)
                        {{ $line }}<br>
                    @endforeach
                </td>
                <td width="50%" class="correction-buyer-change-details">
                    @foreach ($document['buyer_change']['after']['lines'] as $line)
                        {{ $line }}<br>
                    @endforeach
                </td>
            </tr>
        </table>
        <br><br>
    @endif

    <div class="unicode-heading-font correction-section correction-items-section">Było:</div>
    @include('invoices.pdf.partials.items-table', ['rows' => $document['before_items']])
    <br><br>
    <div class="unicode-heading-font correction-section correction-items-section">Powinno być:</div>
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
        @if ($document['pln_conversion'])
            @foreach ($document['tax_row_pairs'] as $pair)
                <tr>
                    <td width="50%" align="right">W tym ({{ $document['currency'] }}):</td>
                    <td width="15%">{{ $pair['source']['net'] }}</td>
                    <td width="5%" align="center">{{ $pair['source']['vat'] }}</td>
                    <td width="15%">{{ $pair['source']['vat_amount'] }}</td>
                    <td width="15%">{{ $pair['source']['gross'] }}</td>
                </tr>
                <tr>
                    <td width="50%" align="right">W tym (PLN):</td>
                    <td width="15%">{{ $pair['converted']['net'] }}</td>
                    <td width="5%" align="center">{{ $pair['converted']['vat'] }}</td>
                    <td width="15%">{{ $pair['converted']['vat_amount'] }}</td>
                    <td width="15%">{{ $pair['converted']['gross'] }}</td>
                </tr>
            @endforeach
        @else
            @foreach ($document['difference_tax_rows'] as $tax)
                <tr>
                    <td width="50%" align="right">W tym:</td>
                    <td width="15%">{{ $tax['net'] }}<br>{{ $document['currency'] }}</td>
                    <td width="5%" align="center">{{ $tax['vat'] }}</td>
                    <td width="15%">{{ $tax['vat_amount'] }}<br>{{ $document['currency'] }}</td>
                    <td width="15%">{{ $tax['gross'] }}<br>{{ $document['currency'] }}</td>
                </tr>
            @endforeach
        @endif
        <tr>
            <td width="50%" align="right">Razem{{ $document['pln_conversion'] ? ' ('.$document['currency'].')' : '' }}:</td>
            <td width="15%">{{ $document['difference_totals']['net'] }}<br>{{ $document['currency'] }}</td>
            <td width="5%"></td>
            <td width="15%">{{ $document['difference_totals']['vat'] }}<br>{{ $document['currency'] }}</td>
            <td width="15%">{{ $document['difference_totals']['gross'] }}<br>{{ $document['currency'] }}</td>
        </tr>
        @if ($document['pln_conversion'])
            <tr>
                <td width="50%" align="right">Razem (PLN):</td>
                <td width="15%">{{ $document['pln_conversion']['totals']['net'] }}<br>PLN</td>
                <td width="5%"></td>
                <td width="15%">{{ $document['pln_conversion']['totals']['vat'] }}<br>PLN</td>
                <td width="15%">{{ $document['pln_conversion']['totals']['gross'] }}<br>PLN</td>
            </tr>
        @endif
        @foreach (['net', 'vat', 'gross'] as $component)
            <tr>
                <td width="50%" class="correction-adjustment-cell" align="right">{{ $document['difference_labels'][$component] }}:</td>
                <td width="15%" class="correction-adjustment-cell" align="right">
                    {{ $document['difference_magnitudes'][$component] }} {{ $document['currency'] }}
                    @if ($document['pln_difference_magnitudes'])
                        <br>{{ $document['pln_difference_magnitudes'][$component] }} PLN
                    @endif
                </td>
                <td width="35%" class="plain"></td>
            </tr>
        @endforeach
    </table>

    <br><br><br>
    <table class="final-details" cellpadding="0" cellspacing="0" width="100%">
        <tr>
            <td width="15%" class="muted-label grand-total-label">Razem:</td>
            <td width="2%"></td>
            <td width="38%" class="grand-total">
                {{ $document['difference_totals']['gross'] }} {{ $document['currency'] }}
                @if ($document['pln_conversion'])
                    <br>{{ $document['pln_conversion']['totals']['gross'] }} PLN
                @endif
            </td>
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
