<table class="allegro-order-fees">
    <thead><tr><th>Typ</th><th>Wartość netto</th><th>Wartość brutto</th></tr></thead>
    <tbody>
        @forelse ($link->fees_snapshot['rows'] ?? [] as $fee)
            <tr>
                <td>{{ $fee['name'] }}@if ($fee['asset'] === 'CREDIT') <span class="text-secondary">(uznanie)</span>@endif</td>
                <td>@if ($fee['net'] !== null){{ $fee['net'] }} {{ $fee['currency'] }}@else<span title="Allegro nie podało stawki VAT">...</span>@endif</td>
                <td>{{ $fee['gross'] }} {{ $fee['currency'] }}</td>
            </tr>
        @empty
            <tr><td colspan="3" class="text-secondary">{{ $link->fees_refreshed_at ? 'Brak opłat przypisanych do tego zamówienia w Allegro.' : 'Nie pobrano prowizji i opłat.' }}</td></tr>
        @endforelse
    </tbody>
</table>
@if ($link->account && ! $link->account->hasBillingScope())
    <div class="mt-2 small"><a href="{{ route('integrations.allegro.edit') }}">Połącz ponownie konto Allegro, aby odczytywać rozliczenia.</a></div>
@endif
