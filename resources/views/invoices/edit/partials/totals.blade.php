<section class="invoice-edit-card">
    <div class="invoice-edit-card-body d-flex flex-wrap justify-content-end gap-4">
        <div><span class="text-muted">Netto</span><strong class="d-block">{{ number_format((float) $invoice->total_net, 2, ',', ' ') }} {{ $invoice->currency }}</strong></div>
        <div><span class="text-muted">VAT</span><strong class="d-block">{{ number_format((float) $invoice->total_vat, 2, ',', ' ') }} {{ $invoice->currency }}</strong></div>
        <div><span class="text-muted">Brutto</span><strong class="d-block">{{ number_format((float) $invoice->total_gross, 2, ',', ' ') }} {{ $invoice->currency }}</strong></div>
        <div><span class="text-muted">Pozostało</span><strong class="d-block">{{ number_format((float) $invoice->amount_due, 2, ',', ' ') }} {{ $invoice->currency }}</strong></div>
    </div>
</section>
