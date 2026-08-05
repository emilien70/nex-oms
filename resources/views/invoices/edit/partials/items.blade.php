<section class="invoice-edit-card">
    <header class="invoice-edit-card-header"><h2 class="fs-6 mb-0">Pozycje Faktury</h2></header>
    <div class="alert alert-danger invoice-edit-error m-3 mb-0" data-form-error hidden></div>
    <div class="table-responsive">
        <table class="table table-sm invoice-items-table">
            <colgroup><col><col class="invoice-item-quantity"><col class="invoice-item-price"><col class="invoice-item-vat"><col class="invoice-item-action"><col class="invoice-item-action"></colgroup>
            <thead><tr><th>Nazwa</th><th class="text-end">Ilość</th><th class="text-end">Cena</th><th class="text-end">Stawka VAT</th><th class="text-center">Edytuj</th><th class="text-center">Usuń</th></tr></thead>
            <tbody>
            @foreach($invoice->items as $item)
                <tr class="invoice-item-row" data-invoice-item-row="{{ $item->id }}">
                    <td><div>{{ $item->name }}</div>@if($item->description)<small class="text-muted">{{ $item->description }}</small>@endif</td>
                    <td class="text-end">{{ rtrim(rtrim($item->quantity, '0'), '.') }}</td>
                    <td class="text-end">{{ number_format((float) $item->unit_price_gross, 2, ',', ' ') }} {{ $invoice->currency }}</td>
                    <td class="text-end">{{ $item->vat_code ?: rtrim(rtrim($item->vat_rate, '0'), '.').'%' }}</td>
                    <td class="text-center">
                        <button
                            class="invoice-icon-button invoice-edit-item-button"
                            type="button"
                            title="Edytuj pozycję"
                            data-bs-toggle="collapse"
                            data-bs-target="#invoiceItemEdit{{ $item->id }}"
                            aria-expanded="false"
                            aria-controls="invoiceItemEdit{{ $item->id }}"
                        ><i class="bi bi-pencil"></i></button>
                    </td>
                    <td class="text-center">
                        <form method="POST" action="{{ route('invoices.items.destroy', [$invoice, $item]) }}" data-invoice-ajax-form onsubmit="return confirm('Czy na pewno usunąć tę pozycję Faktury?')">
                            @csrf @method('DELETE')
                            <input type="hidden" name="expected_lock_version" value="{{ $invoice->lock_version }}" data-lock-version-input>
                            <div class="alert alert-danger invoice-edit-error" data-form-error hidden></div>
                            <button class="invoice-icon-button invoice-delete-item-button" type="submit" title="Usuń pozycję"><i class="bi bi-x-lg"></i></button>
                        </form>
                    </td>
                </tr>
                <tr class="collapse invoice-item-editor-row" id="invoiceItemEdit{{ $item->id }}" data-invoice-item-editor>
                    <td colspan="6">
                        <div class="invoice-item-inline-form">
                            <form method="POST" action="{{ route('invoices.items.update', [$invoice, $item]) }}" data-invoice-ajax-form data-item-edit-form>
                                @csrf
                                @method('PATCH')
                                <input type="hidden" name="expected_lock_version" value="{{ $invoice->lock_version }}" data-lock-version-input>
                                <input type="hidden" name="description" value="{{ $item->description }}">
                                <input type="hidden" name="position" value="{{ $item->position }}">
                                <input type="hidden" name="vat_code" value="{{ $item->vat_code }}">
                                <input type="hidden" name="unit_name" value="{{ $item->unit_name }}">
                                <div class="alert alert-danger invoice-edit-error" data-form-error hidden></div>
                                <div class="row g-2 align-items-end">
                                    <div class="col-12 col-lg-5">
                                        <label class="form-label">Nazwa produktu</label>
                                        <input type="text" name="name" class="form-control form-control-sm" value="{{ $item->name }}" required maxlength="255">
                                    </div>
                                    <div class="col-6 col-lg-1">
                                        <label class="form-label">Ilość</label>
                                        <input type="number" name="quantity" class="form-control form-control-sm" value="{{ rtrim(rtrim($item->quantity, '0'), '.') }}" min="0" step="1" inputmode="numeric" required>
                                    </div>
                                    <div class="col-6 col-lg-2">
                                        <label class="form-label">Cena brutto</label>
                                        <input type="number" name="unit_price_gross" class="form-control form-control-sm" value="{{ number_format((float) $item->unit_price_gross, 2, '.', '') }}" min="0" step="0.01" inputmode="decimal" required>
                                    </div>
                                    <div class="col-6 col-lg-1">
                                        <label class="form-label">Waluta</label>
                                        <input type="text" class="form-control form-control-sm" value="{{ $invoice->currency }}" readonly aria-label="Waluta dokumentu">
                                    </div>
                                    <div class="col-6 col-lg-1">
                                        <label class="form-label">VAT (%)</label>
                                        <input type="text" name="vat_rate" class="form-control form-control-sm" value="{{ $item->vat_rate }}" inputmode="decimal">
                                    </div>
                                    <div class="col-12 col-lg-2 d-flex justify-content-end">
                                        <button type="submit" class="btn btn-sm btn-primary invoice-item-inline-save">Zapisz</button>
                                    </div>
                                </div>
                            </form>
                        </div>
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
    <div class="invoice-edit-card-body d-flex flex-wrap gap-2">
        <button class="invoice-add-item-button" type="button" data-add-invoice-item data-next-position="{{ $invoice->items->count() + 1 }}" data-lock-version="{{ $invoice->lock_version }}"><i class="bi bi-plus-lg me-1"></i>Dodaj pozycję Faktury</button>
        <form method="POST" action="{{ route('invoices.items.copy-from-order', $invoice) }}" data-invoice-ajax-form>
            @csrf
            <input type="hidden" name="expected_lock_version" value="{{ $invoice->lock_version }}" data-lock-version-input>
            <div class="alert alert-danger invoice-edit-error" data-form-error hidden></div>
            <button class="invoice-copy-products-button" type="submit" data-copy-order-items><i class="bi bi-arrow-repeat me-1"></i>Skopiuj aktualne produkty z zamówienia</button>
        </form>
    </div>
</section>
