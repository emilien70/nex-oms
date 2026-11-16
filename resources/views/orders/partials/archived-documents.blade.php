@php
    $documents = $order->invoices->filter(fn ($invoice) => $invoice->isIssued() && filled($invoice->number))
        ->groupBy(fn ($invoice) => $invoice->document_type->value);
@endphp
@foreach (['invoice' => 'Faktura:', 'proforma' => 'Pro forma:', 'correction' => 'Korekta:'] as $type => $label)
    @if ($type === 'invoice' || $documents->has($type))
        <div class="management-meta-label">{{ $label }}</div>
        <div class="management-meta-value">
            <div class="management-issued-invoice-actions flex-wrap">
                @forelse ($documents->get($type, []) as $invoice)
                    @php
                        $offline = $invoice->ksefOfflineIssuances->sortByDesc('id')->first();
                        $pdfUrl = $offline
                            ? route('invoices.ksef.offline-issuances.invoice-pdf', [$invoice, $offline])
                            : route('invoices.pdf', $invoice);
                    @endphp
                    <a class="btn btn-sm btn-outline-secondary management-issued-invoice-number" href="{{ $pdfUrl }}" target="_blank" rel="noopener" title="Otwórz PDF">
                        <i class="bi bi-file-earmark-text" aria-hidden="true"></i> {{ $invoice->number }}
                    </a>
                @empty
                    <span class="nex-empty">...</span>
                @endforelse
            </div>
        </div>
    @endif
@endforeach
