<!doctype html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    @include('invoices.pdf.partials.styles')
    <style>
        .confirmation-title { font-size: 18pt; font-weight: bold; text-align: center; }
        .confirmation-meta td { border-bottom: 1px solid #c9c9c9; font-size: 10pt; padding: 7px; }
    </style>
</head>
<body>
    <div class="ksef-test-mark">{{ $document['test_mark'] }}</div>
    <br><br>
    <div class="confirmation-title">POTWIERDZENIE TRANSAKCJI</div>
    <br><br><br>

    @include('invoices.pdf.partials.parties')

    <br><br>
    <table class="confirmation-meta" cellpadding="2" cellspacing="0" width="70%" align="center">
        <tr>
            <td width="45%">Numer faktury:</td>
            <td width="55%" align="right">{{ $document['number'] }}</td>
        </tr>
        <tr>
            <td>Kwota należności ogółem:</td>
            <td align="right">{{ $document['total_gross'] }} {{ $document['currency'] }}</td>
        </tr>
    </table>
</body>
</html>
