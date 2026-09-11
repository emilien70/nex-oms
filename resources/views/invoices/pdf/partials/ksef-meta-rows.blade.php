@if (data_get($document, 'ksef.number'))
    <tr><td>Numer KSeF:</td><td align="center" class="ksef-meta-value">{{ $document['ksef']['number'] }}</td></tr>
    <tr><td>Data przetworzenia w KSeF:</td><td align="center">{{ $document['ksef']['processed_at'] }}<br><span style="font-size: 7pt;">({{ $document['ksef']['processed_at_timezone'] }})</span></td></tr>
    <tr><td>Status KSeF:</td><td align="center">{{ $document['ksef']['status'] }}</td></tr>
@endif
