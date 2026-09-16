<tbody class="summary">
<tr class="summary-heading"><td colspan="{{ $columns }}">{{ $heading }}</td></tr>
@foreach ($bucket['coverage'] as $section => $coverage)
    <tr class="coverage"><td colspan="{{ $columns }}">
        {{ $presenter->section($section) }}: uwzględniono {{ $coverage['included_count'] }} z {{ $bucket['document_count'] }}; pominięto {{ $coverage['excluded_count'] }}.
        @if (! $coverage['complete'])<span class="partial">Suma częściowa.</span> Dokumenty: {{ implode(', ', array_map(fn ($id) => $labels[$id] ?? 'ID '.$id, $coverage['excluded_ids'])) }}.@endif
    </td></tr>
@endforeach
@include('invoices.sales-register.amounts', ['label' => $bucket['coverage']['totals']['complete'] ? 'Razem' : 'Razem — suma częściowa', 'amounts' => $bucket['totals'], 'vatLabel' => '', 'rowClass' => ''])
<tr class="note"><td colspan="{{ $columns }}">Podsumowanie stawek VAT</td></tr>
@foreach ($bucket['vat_groups'] as $group)
    @include('invoices.sales-register.amounts', ['label' => 'W tym', 'amounts' => $group, 'vatLabel' => $group['label'], 'rowClass' => ''])
@endforeach
@include('invoices.sales-register.amounts', ['label' => $bucket['coverage']['totals']['complete'] ? 'Suma' : 'Suma częściowa', 'amounts' => $bucket['totals'], 'vatLabel' => '', 'rowClass' => 'total'])
@include('invoices.sales-register.amounts', ['label' => 'w tym koszty wysyłek'.($bucket['coverage']['shipping']['complete'] ? '' : ' — suma częściowa'), 'amounts' => $bucket['shipping']['totals'], 'vatLabel' => '', 'rowClass' => ''])
@foreach ($bucket['shipping']['vat_groups'] as $group)
    @include('invoices.sales-register.amounts', ['label' => 'Wysyłka wg VAT', 'amounts' => $group, 'vatLabel' => $group['label'], 'rowClass' => ''])
@endforeach
@if ($converted ?? false)<tr class="note"><td colspan="{{ $columns }}">Wysyłka w PLN jest częścią sumy; dla dokumentów walutowych obliczona z utrwalonych pozycji i historycznego kursu dokumentu.</td></tr>@endif
</tbody>
