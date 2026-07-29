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
                </table>
            </td>
            <td width="10%"></td>
            <td width="45%">
                <table class="meta-table" cellpadding="2" cellspacing="0" width="100%">
                    <tr><td width="42%">Miejsce wystawienia:</td><td width="58%" align="center">{{ $document['place_of_issue'] ?: '-' }}</td></tr>
                    <tr>
                        <td>Sposób płatności:</td>
                        <td align="center">
                            @forelse ($document['payment_lines'] as $line)
                                {{ $line }}@if (! $loop->last)<br>@endif
                            @empty
                                -
                            @endforelse
                        </td>
                    </tr>
                    <tr><td>Numer zamówienia:</td><td align="center">{{ $document['order_number'] ?: '-' }}</td></tr>
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
            <td width="15%" class="muted-label">Razem:</td>
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
