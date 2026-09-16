<tr class="{{ $rowClass }}">
    <td colspan="5">{{ $label }}</td>
    <td class="money">{{ $presenter->money($amounts['net'] ?? null, $currency) }}</td><td>{{ $vatLabel }}</td>
    <td class="money">{{ $presenter->money($amounts['vat'] ?? null, $currency) }}</td>
    <td class="money">{{ $presenter->money($amounts['gross'] ?? null, $currency) }}</td>
    <td colspan="{{ $columns - 9 }}"></td>
</tr>
