<section class="invoice-edit-card">
    <header class="invoice-edit-card-header"><h2 class="fs-6 mb-0">Pozycje Faktury</h2></header>
    <div class="alert alert-danger invoice-edit-error m-3 mb-0" data-form-error hidden></div>
    <div class="table-responsive">
        <table class="table table-sm invoice-items-table">
            <thead><tr><th>Nazwa</th><th class="text-end">Ilość</th><th class="text-end">Cena brutto</th><th class="text-center">VAT</th><th class="text-end">Wartość brutto</th><th class="text-center">Edytuj</th><th class="text-center">Usuń</th></tr></thead>
            <tbody>
            @foreach($invoice->items as $item)
                <tr>
                    <td><div>{{ $item->name }}</div>@if($item->description)<small class="text-muted">{{ $item->description }}</small>@endif</td>
                    <td class="text-end">{{ rtrim(rtrim($item->quantity, '0'), '.') }} {{ $item->unit_name }}</td>
                    <td class="text-end">{{ number_format((float) $item->unit_price_gross, 2, ',', ' ') }} {{ $invoice->currency }}</td>
                    <td class="text-center">{{ $item->vat_code ?: rtrim(rtrim($item->vat_rate, '0'), '.').'%' }}</td>
                    <td class="text-end">{{ number_format((float) $item->total_gross, 2, ',', ' ') }} {{ $invoice->currency }}</td>
                    <td class="text-center"><button class="btn btn-sm btn-outline-secondary invoice-icon-button" type="button" title="Edytuj pozycję" data-edit-invoice-item data-url="{{ route('invoices.items.update', [$invoice, $item]) }}" data-lock-version="{{ $invoice->lock_version }}" data-item="{{ json_encode(["name"=>$item->name,"description"=>$item->description,"unit_name"=>$item->unit_name,"quantity"=>$item->quantity,"unit_price_gross"=>$item->unit_price_gross,"vat_rate"=>$item->vat_rate,"vat_code"=>$item->vat_code,"position"=>$item->position]) }}"><i class="bi bi-pencil"></i></button></td>
                    <td class="text-center">
                        <form method="POST" action="{{ route('invoices.items.destroy', [$invoice, $item]) }}" data-invoice-ajax-form onsubmit="return confirm('Czy na pewno usunąć tę pozycję Faktury?')">
                            @csrf @method('DELETE')
                            <input type="hidden" name="expected_lock_version" value="{{ $invoice->lock_version }}" data-lock-version-input>
                            <div class="alert alert-danger invoice-edit-error" data-form-error hidden></div>
                            <button class="btn btn-sm btn-outline-secondary invoice-icon-button" type="submit" title="Usuń pozycję"><i class="bi bi-x-lg"></i></button>
                        </form>
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
    <div class="invoice-edit-card-body d-flex flex-wrap gap-2">
        <button class="btn btn-sm btn-primary" type="button" data-add-invoice-item data-next-position="{{ $invoice->items->count() + 1 }}" data-lock-version="{{ $invoice->lock_version }}"><i class="bi bi-plus-lg me-1"></i>Dodaj pozycję Faktury</button>
        <form method="POST" action="{{ route('invoices.items.copy-from-order', $invoice) }}" data-invoice-ajax-form>
            @csrf
            <input type="hidden" name="expected_lock_version" value="{{ $invoice->lock_version }}" data-lock-version-input>
            <div class="alert alert-danger invoice-edit-error" data-form-error hidden></div>
            <button class="btn btn-sm btn-outline-warning" type="submit" data-copy-order-items><i class="bi bi-arrow-repeat me-1"></i>Skopiuj aktualne produkty z zamówienia</button>
        </form>
    </div>
</section>
