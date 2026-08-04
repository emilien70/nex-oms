<section class="invoice-edit-card">
    <div class="invoice-edit-card-body invoice-technical-grid">
        <div><span>Seria</span>{{ $invoice->series_name_snapshot }}</div>
        <div><span>Numer</span>{{ $invoice->number }}</div>
        <div><span>Numer kolejny</span>{{ $invoice->sequence_number }}</div>
        <div><span>Okres numeracji</span>{{ $invoice->numbering_period_key }}</div>
        <div><span>Waluta</span>{{ $invoice->currency }}</div>
        <div><span>Wystawiono</span>{{ $invoice->issued_at?->format('d.m.Y H:i') }}</div>
    </div>
</section>
