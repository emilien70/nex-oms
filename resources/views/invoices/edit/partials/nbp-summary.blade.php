@if($nbp)
    <section class="invoice-edit-card">
        <header class="invoice-edit-card-header"><h2 class="fs-6 mb-0">Przeliczenie podatkowe NBP</h2><span class="badge text-bg-light">tylko do odczytu</span></header>
        <div class="invoice-edit-card-body invoice-technical-grid">
            <div><span>Kurs</span>{{ $nbp['rate_text'] }}</div>
            <div><span>Data publikacji</span>{{ $nbp['effective_date'] }}</div>
            <div><span>Tabela</span>{{ $nbp['table_number'] }}</div>
            <div><span>Brutto w PLN</span>{{ number_format((float) $nbp['totals']['gross'], 2, ',', ' ') }} PLN</div>
        </div>
    </section>
@endif
