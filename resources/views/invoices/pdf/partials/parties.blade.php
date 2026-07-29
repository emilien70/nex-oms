<table class="party-table" cellpadding="0" cellspacing="0" width="100%">
    <tr>
        <td width="15%" class="party-title seller-party-title">
            <table cellpadding="0" cellspacing="0" width="100%">
                <tr><td width="25%"></td><td width="75%">Sprzedawca:</td></tr>
            </table>
        </td>
        <td width="34%" class="party-details seller-party-details">
            <table cellpadding="0" cellspacing="0" width="100%">
                <tr>
                    <td width="5%"></td>
                    <td width="95%">
                        @foreach ($document['seller']['lines'] as $line)
                            {{ $line }}<br>
                        @endforeach
                    </td>
                </tr>
            </table>
        </td>
        <td width="3%"></td>
        <td width="12%" class="party-title buyer-party-title">Nabywca:</td>
        <td width="36%" class="party-details">
            <table cellpadding="0" cellspacing="0" width="100%">
                <tr>
                    <td width="100%">
                        @foreach ($document['buyer']['lines'] as $line)
                            {{ $line }}<br>
                        @endforeach
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
<br><br>
