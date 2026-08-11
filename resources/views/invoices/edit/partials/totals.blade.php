<section class="invoice-edit-card">
    <div class="invoice-edit-card-body d-flex flex-wrap justify-content-end gap-4">
        <div><span class="text-muted">Netto</span><strong class="d-block">{{ $moneyFormatter->format($invoice->total_net) }} {{ $invoice->currency }}</strong></div>
        <div><span class="text-muted">VAT</span><strong class="d-block">{{ $moneyFormatter->format($invoice->total_vat) }} {{ $invoice->currency }}</strong></div>
        <div><span class="text-muted">Brutto</span><strong class="d-block">{{ $moneyFormatter->format($invoice->total_gross) }} {{ $invoice->currency }}</strong></div>
        <div><span class="text-muted">Pozostało</span><strong class="d-block">{{ $moneyFormatter->format($invoice->amount_due) }} {{ $invoice->currency }}</strong></div>
    </div>
</section>
