<!doctype html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    @include('invoices.pdf.partials.styles')
    <style>
        .offline-heading { font-size: 19pt; }
        .offline-mark { margin-bottom: 12px; }
        .offline-items { border-collapse: collapse; }
        .offline-items th { background-color: #eeeeee; border: 1px solid #c8c8c8; font-size: 7.5pt; padding: 3px; text-align: center; }
        .offline-items td { border: 1px solid #c8c8c8; font-size: 7.5pt; padding: 3px; }
        .offline-details td { border-bottom: 1px solid #c9c9c9; padding: 3px 4px; }
    </style>
</head>
<body>
    <div class="ksef-test-mark offline-mark">{{ $document['test_mark'] }}</div>

    <table class="brand-table" cellpadding="0" cellspacing="0" width="100%">
        <tr><td class="heading-font seller-heading">{{ $document['seller']['name'] }}</td></tr>
    </table>
    <br><br>
    <table cellpadding="0" cellspacing="0" width="100%">
        <tr>
            <td width="35%" class="unicode-heading-font offline-heading">Faktura VAT</td>
            <td width="35%" class="number-box">{{ $document['number'] }}</td>
            <td width="30%"></td>
        </tr>
    </table>
    <br><br><br>

    <table cellpadding="0" cellspacing="0" width="100%">
        <tr>
            <td width="47%">
                <table class="offline-details" cellpadding="2" cellspacing="0" width="100%">
                    <tr><td width="55%">Data wystawienia:</td><td>{{ $document['issue_date'] }}</td></tr>
                    @if ($document['sale_date'])
                        <tr><td>Data sprzedaży:</td><td>{{ $document['sale_date'] }}</td></tr>
                    @endif
                    @if ($document['place_of_issue'])
                        <tr><td>Miejsce wystawienia:</td><td>{{ $document['place_of_issue'] }}</td></tr>
                    @endif
                </table>
            </td>
            <td width="6%"></td>
            <td width="47%">
                <table class="offline-details" cellpadding="2" cellspacing="0" width="100%">
                    @if ($document['payment']['method'] ?? null)
                        <tr><td width="48%">Sposób płatności:</td><td>{{ $document['payment']['method'] }}</td></tr>
                    @endif
                    @if ($document['payment']['due_date'] ?? null)
                        <tr><td>Termin płatności:</td><td>{{ date('d.m.Y', strtotime($document['payment']['due_date'])) }}</td></tr>
                    @endif
                    @if ($document['order_number'])
                        <tr><td>Numer zamówienia:</td><td>{{ $document['order_number'] }}</td></tr>
                    @endif
                </table>
            </td>
        </tr>
    </table>
    <br><br><br>

    @include('invoices.pdf.partials.parties')

    <table class="offline-items" cellpadding="2" cellspacing="0" width="98%" align="center">
        <thead>
            <tr>
                <th width="43%">Nazwa towaru/usługi</th>
                <th width="8%">JM</th>
                <th width="12%">Ilość</th>
                <th width="14%">Jedn. netto</th>
                <th width="14%">Wart. netto</th>
                <th width="9%">VAT</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($document['lines'] as $line)
                <tr nobr="true">
                    <td width="43%">{{ $line['name'] }}@if($line['gtu'])<br><small>{{ $line['gtu'] }}</small>@endif</td>
                    <td width="8%" align="center">{{ $line['unit_name'] }}</td>
                    <td width="12%" align="right">{{ $line['quantity'] }}</td>
                    <td width="14%" align="right">{{ $line['unit_price_net'] }} {{ $document['currency'] }}</td>
                    <td width="14%" align="right">{{ $line['total_net'] }} {{ $document['currency'] }}</td>
                    <td width="9%" align="center">{{ $line['vat'] }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <br><br>
    <table class="summary" cellpadding="1" cellspacing="0" width="55%" align="right">
        <tr>
            <td width="20%" class="summary-heading">VAT</td>
            <td width="27%" class="summary-heading">Netto</td>
            <td width="26%" class="summary-heading">VAT</td>
            <td width="27%" class="summary-heading">Brutto</td>
        </tr>
        @foreach ($document['tax_rows'] as $tax)
            <tr>
                <td>{{ $tax['vat'] }}</td>
                <td>{{ $tax['net'] }}</td>
                <td>{{ $tax['vat_amount'] }}</td>
                <td>{{ $tax['gross'] }}</td>
            </tr>
        @endforeach
    </table>

    <br><br><br>
    <table class="final-details" cellpadding="0" cellspacing="0" width="100%">
        <tr>
            <td width="15%" class="muted-label">Razem:</td>
            <td width="2%"></td>
            <td width="38%" class="grand-total">{{ $document['total_gross'] }} {{ $document['currency'] }}</td>
            <td width="45%"></td>
        </tr>
        <tr>
            <td class="muted-label">Słownie:</td>
            <td></td>
            <td colspan="2">{{ $document['amount_in_words'] }}</td>
        </tr>
    </table>

    @if ($document['payment']['bank_account'] ?? null)
        <br><br>
        <div>Rachunek bankowy: {{ $document['payment']['bank_account'] }}</div>
        @if ($document['payment']['bank_name'] ?? null)<div>Bank: {{ $document['payment']['bank_name'] }}</div>@endif
        @if ($document['payment']['bank_swift'] ?? null)<div>SWIFT: {{ $document['payment']['bank_swift'] }}</div>@endif
    @endif

    @if ($document['additional_descriptions'])
        <br><br>
        @foreach ($document['additional_descriptions'] as $description)
            <div><strong>{{ $description['key'] }}:</strong> {!! nl2br(e($description['value'])) !!}</div>
        @endforeach
    @endif
</body>
</html>
