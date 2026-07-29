@php($rows = $rows ?? $document['items'])
<table class="items" cellpadding="2" cellspacing="0" width="98%" align="center">
    <thead>
        <tr>
            <th width="42%">Nazwa towaru/usługi</th>
            <th width="3%">JM</th>
            <th width="11%">Jedn. netto</th>
            <th width="6%">Ilość</th>
            <th width="11%">Wart. netto</th>
            <th width="5%">VAT</th>
            <th width="10%">Wart. VAT</th>
            <th width="12%">Wart. brutto</th>
        </tr>
    </thead>
    <tbody>
        @foreach ($rows as $item)
            <tr nobr="true">
                <td width="42%" class="name">{{ $item['name'] }}</td>
                <td width="3%" align="center">{{ rtrim((string) $item['unit_name'], ' .') }}</td>
                <td width="11%" class="number">{{ $item['unit_price_net'] }} {{ $document['currency'] }}</td>
                <td width="6%" class="number">{{ $item['quantity'] }}</td>
                <td width="11%" class="number">{{ $item['total_net'] }} {{ $document['currency'] }}</td>
                <td width="5%" align="center">{{ $item['vat'] }}</td>
                <td width="10%" class="number">{{ $item['total_vat'] }} {{ $document['currency'] }}</td>
                <td width="12%" class="number">{{ $item['total_gross'] }} {{ $document['currency'] }}</td>
            </tr>
        @endforeach
    </tbody>
</table>
