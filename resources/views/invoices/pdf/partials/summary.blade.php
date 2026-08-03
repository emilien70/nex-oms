@if ($document['pln_conversion'] ?? null)
    <table class="summary" cellpadding="1" cellspacing="0" width="98%" align="center">
        <tr>
            <td width="48%" class="plain" style="border: 1px solid #ffffff;"></td>
            <td width="14%" class="plain" style="border: 1px solid #ffffff;"></td>
            <td width="11%" class="summary-heading" align="center">Netto</td>
            <td width="5%" class="summary-heading"></td>
            <td width="10%" class="summary-heading" align="center">VAT</td>
            <td width="12%" class="summary-heading" align="center">Brutto</td>
        </tr>
        <tr>
            <td width="48%" class="plain" style="border: 1px solid #ffffff;"></td>
            <td width="14%" class="plain" style="border: 1px solid #ffffff;">Razem ({{ $document['currency'] }}):</td>
            <td width="11%">{{ $document['totals']['net'] }}</td>
            <td width="5%"></td>
            <td width="10%">{{ $document['totals']['vat'] }}</td>
            <td width="12%">{{ $document['totals']['gross'] }}</td>
        </tr>
        <tr>
            <td width="48%" class="plain" style="border: 1px solid #ffffff;"></td>
            <td width="14%" class="plain" style="border: 1px solid #ffffff;">Razem (PLN):</td>
            <td width="11%" class="summary-conversion-value">{{ $document['pln_conversion']['totals']['net'] }}</td>
            <td width="5%" class="summary-conversion-value"></td>
            <td width="10%" class="summary-conversion-value">{{ $document['pln_conversion']['totals']['vat'] }}</td>
            <td width="12%" class="summary-conversion-value">{{ $document['pln_conversion']['totals']['gross'] }}</td>
        </tr>
    </table>
    @if ($document['tax_row_pairs'])
        <br><br>
        <table class="summary" cellpadding="1" cellspacing="0" width="98%" align="center">
            @foreach ($document['tax_row_pairs'] as $pair)
                <tr>
                    <td width="48%" class="plain" style="border: 1px solid #ffffff;"></td>
                    <td width="14%" class="plain" style="border: 1px solid #ffffff;">W tym ({{ $document['currency'] }}):</td>
                    <td width="11%">{{ $pair['source']['net'] }}</td>
                    <td width="5%" align="center">{{ $pair['source']['vat'] }}</td>
                    <td width="10%">{{ $pair['source']['vat_amount'] }}</td>
                    <td width="12%">{{ $pair['source']['gross'] }}</td>
                </tr>
                <tr>
                    <td width="48%" class="plain" style="border: 1px solid #ffffff;"></td>
                    <td width="14%" class="plain" style="border: 1px solid #ffffff;">W tym (PLN):</td>
                    <td width="11%" class="summary-conversion-value">{{ $pair['converted']['net'] }}</td>
                    <td width="5%" class="summary-conversion-value" align="center">{{ $pair['converted']['vat'] }}</td>
                    <td width="10%" class="summary-conversion-value">{{ $pair['converted']['vat_amount'] }}</td>
                    <td width="12%" class="summary-conversion-value">{{ $pair['converted']['gross'] }}</td>
                </tr>
            @endforeach
        </table>
    @endif
@else
<table class="summary" cellpadding="1" cellspacing="0" width="98%" align="center">
    <tr>
        <td width="55%" class="plain" style="border: 1px solid #ffffff;"></td>
        <td width="7%" class="plain" style="border: 1px solid #ffffff;"></td>
        <td width="11%" class="summary-heading" align="center">Netto</td>
        <td width="5%" class="summary-heading"></td>
        <td width="10%" class="summary-heading" align="center">VAT</td>
        <td width="12%" class="summary-heading" align="center">Brutto</td>
    </tr>
    <tr>
        <td width="55%" class="plain" style="border: 1px solid #ffffff;"></td>
        <td width="7%" class="plain" style="border: 1px solid #ffffff;">Razem:</td>
        <td width="11%">{{ $document['totals']['net'] }}<br>{{ $document['currency'] }}</td>
        <td width="5%"></td>
        <td width="10%">{{ $document['totals']['vat'] }}<br>{{ $document['currency'] }}</td>
        <td width="12%">{{ $document['totals']['gross'] }}<br>{{ $document['currency'] }}</td>
    </tr>
</table>
@if ($document['tax_rows'])
    <br><br>
    <table class="summary" cellpadding="1" cellspacing="0" width="98%" align="center">
        @foreach ($document['tax_rows'] as $tax)
            <tr>
                <td width="55%" class="plain" style="border: 1px solid #ffffff;"></td>
                <td width="7%" class="plain" style="border: 1px solid #ffffff;">W tym:</td>
                <td width="11%">{{ $tax['net'] }}<br>{{ $document['currency'] }}</td>
                <td width="5%" align="center">{{ $tax['vat'] }}</td>
                <td width="10%">{{ $tax['vat_amount'] }}<br>{{ $document['currency'] }}</td>
                <td width="12%">{{ $tax['gross'] }}<br>{{ $document['currency'] }}</td>
            </tr>
        @endforeach
    </table>
@endif
@endif
