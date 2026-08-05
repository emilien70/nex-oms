<table class="brand-table" cellpadding="0" cellspacing="0" width="100%">
    <tr>
        <td class="heading-font seller-heading">{{ $document['header'] }}</td>
    </tr>
</table>
<br><br><br>
<table cellpadding="0" cellspacing="0" width="100%">
    <tr>
        <td width="3%"></td>
        <td
            width="{{ $document['type'] === 'correction' ? '30%' : '28%' }}"
            class="unicode-heading-font document-title{{ $document['type'] === 'correction' ? ' correction-document-title' : '' }}"
        >{{ $document['title'] }}</td>
        <td width="2%"></td>
        <td width="35%" class="number-box"><sub class="number-box-value">{{ $document['number'] }}</sub></td>
        <td width="{{ $document['type'] === 'correction' ? '30%' : '32%' }}" class="related-document" align="right">
            @if (! empty($document['source_invoice'] ?? null))
                do faktury {{ $document['source_invoice']['number'] }} z dnia<br>
                {{ $document['source_invoice']['issue_date'] }}
            @elseif (! empty($document['related_proforma_number'] ?? null))
                Faktura do faktury pro forma:<br>
                {{ $document['related_proforma_number'] }}
            @endif
        </td>
    </tr>
</table>
<br><br><br><br>
