@extends('layouts.app')

@section('title', ($correction ? 'Edycja korekty' : 'Tworzenie korekty').' - NEX-OMS')

@section('content')
    @php
        $isEditing = $correction !== null;
        $formItems = old('items', $items->all());
        $formBuyer = old('buyer', $buyer);
        $changeItems = (bool) old('change_items', $defaultChangeItems);
        $changeBuyer = (bool) old('change_buyer', $defaultChangeBuyer);
        $selectedReason = old('reason', $defaults['reason']);
    @endphp

    <style>
        .correction-page { margin:-1.5rem; min-height:100vh; padding:18px 10px 28px; background:#f4f6f8; color:#4e565f; font-size:13px; }
        .correction-page-header { align-items:flex-start; display:flex; justify-content:space-between; margin-bottom:18px; }
        .correction-page h1 { color:#20242a; font-size:28px; font-weight:500; margin:0 0 4px; }
        .correction-document-actions { border:1px solid #cfd5dc; border-radius:22px; flex-wrap:nowrap; max-width:100%; }
        .correction-document-actions .btn { align-items:center; background:#fff; border:0; border-left:1px solid #cfd5dc; border-radius:0; color:#4e565f; display:inline-flex; font-size:13px; justify-content:center; min-height:40px; padding:0 12px; white-space:nowrap; }
        .correction-document-actions > :first-child { border-left:0; border-radius:21px 0 0 21px; }
        .correction-document-actions > :last-child { border-radius:0 21px 21px 0; }
        .correction-document-actions .btn:hover,.correction-document-actions .btn:focus { background:#f8fafc; color:#20242a; }
        .correction-document-actions .btn:disabled { background:#fff; color:#94a3b8; opacity:1; }
        .correction-document-actions .dropdown-toggle-split { min-width:38px; padding:0 10px; }
        .correction-document-actions .dropdown-menu { border-color:#cfd5dc; font-size:13px; }
        .correction-card { background:#fff; border:1px solid #dfe4ea; border-radius:7px; box-shadow:0 1px 3px rgba(15,23,42,.08); margin-bottom:16px; padding:24px 30px; }
        .correction-card-title { align-items:center; color:#20242a; display:flex; font-size:18px; font-weight:600; gap:12px; margin-bottom:28px; }
        .correction-card-title::before { background:#0d83dd; border-radius:50%; content:""; height:9px; width:9px; }
        .correction-fields { margin-left:300px; max-width:600px; }
        .correction-field { align-items:center; display:grid; grid-template-columns:160px minmax(0,400px); gap:18px; margin-bottom:14px; }
        .correction-field > label { font-size:12px; margin:0; text-align:right; }
        .correction-field .form-control,.correction-field .form-select { border-color:#cfd6df; font-size:13px; min-height:40px; }
        .correction-field-with-help { align-items:start; }
        .correction-field-with-help > label { align-items:center; display:flex; justify-content:flex-end; min-height:40px; }
        .correction-field-textarea { align-items:start; }
        .correction-field-textarea > label { padding-top:8px; }
        .correction-field textarea { min-height:145px; }
        .correction-help { color:#64748b; font-size:12px; margin-top:5px; }
        .correction-divider { border-top:1px solid #cfd6df; margin:20px 0; }
        .correction-option { align-items:flex-start; display:grid; grid-template-columns:160px minmax(0,500px); gap:18px; margin-bottom:16px; }
        .correction-option-label { font-size:12px; padding-top:3px; text-align:right; }
        .correction-items-card { background:#fff; border:1px solid #dfe4ea; border-radius:7px; box-shadow:0 1px 3px rgba(15,23,42,.08); margin-bottom:16px; overflow:hidden; }
        .correction-items-title { color:#20242a; font-size:18px; font-weight:600; margin:0 0 12px; }
        .correction-items-table { color:#4e565f; font-size:12px; margin:0; }
        .correction-items-table th { color:#20242a; font-size:10px; font-weight:600; padding:12px; text-transform:uppercase; }
        .correction-items-table td { border-color:#d7dde5; padding:8px 12px; vertical-align:middle; }
        .correction-items-table .correction-item-select { width:40px; }
        .correction-items-table .correction-item-quantity { width:80px; }
        .correction-items-table .correction-item-price { width:110px; }
        .correction-items-table .correction-item-vat { width:90px; }
        .correction-items-table .correction-item-action { width:55px; }
        .correction-items-table th:nth-child(n+3),.correction-items-table td:nth-child(n+3) { white-space:nowrap; }
        .correction-item-editor td { background:#f8fafc; padding:12px 20px; }
        .correction-item-editor-grid { align-items:end; display:grid; gap:10px; grid-template-columns:minmax(220px,25%) 85px 120px 100px auto; justify-content:start; }
        .correction-item-editor-grid label { display:block; font-size:11px; margin-bottom:4px; }
        .correction-icon-button { align-items:center; background:#fff; border:1px solid #e6eaf0; border-radius:50%; color:#4e565f; display:inline-flex; height:32px; justify-content:center; padding:0; width:32px; }
        .correction-icon-button:hover { background:#f1f7fd; color:#0875d1; }
        .correction-items-actions { display:flex; flex-wrap:wrap; gap:10px; padding:14px; }
        .correction-pill-button { align-items:center; border-radius:21px; display:inline-flex; font-size:13px; min-height:40px; padding:0 20px; }
        .correction-copy-button { background:#fff; border:1px solid #f07a18; color:#e76517; justify-content:center; }
        .correction-copy-button:hover,.correction-copy-button:focus { background:#e76517; border-color:#e76517; color:#fff; }
        .correction-buyer-grid { display:grid; grid-template-columns:1fr 1fr; gap:24px; }
        .correction-buyer-fields { display:flex; flex-direction:column; gap:12px; }
        .correction-buyer-field { align-items:center; display:grid; gap:16px; grid-template-columns:190px minmax(0,1fr); }
        .correction-buyer-field label { font-size:12px; margin:0; text-align:right; }
        .correction-buyer-field .form-control,.correction-buyer-field .form-select { border-color:#cfd6df; font-size:13px; min-height:40px; }
        .correction-current-data h3 { color:#20242a; font-size:18px; font-weight:600; margin-bottom:8px; }
        .correction-current-data dl { display:grid; grid-template-columns:40% 60%; margin:0 0 12px; }
        .correction-current-data dt,.correction-current-data dd { border-bottom:1px solid #cfd5dc; min-height:28px; padding:5px 8px; }
        .correction-current-data dt { font-weight:500; }
        .correction-current-data dd { margin:0; }
        .correction-page-actions { display:flex; gap:10px; justify-content:flex-end; }
        [hidden] { display:none !important; }
        @media(max-width:991.98px){.correction-page{margin:-1rem;padding:12px}.correction-page-header{align-items:flex-start;flex-direction:column;gap:12px}.correction-document-actions{max-width:100%;overflow-x:auto}.correction-fields{margin-left:0;max-width:none}.correction-field,.correction-option{grid-template-columns:1fr;gap:4px}.correction-field>label,.correction-option-label{text-align:left}.correction-field-with-help>label{justify-content:flex-start}.correction-buyer-grid{grid-template-columns:1fr}.correction-buyer-field{grid-template-columns:1fr;gap:4px}.correction-buyer-field label{text-align:left}.correction-item-editor-grid{grid-template-columns:1fr 1fr}.correction-page h1{font-size:22px}}
    </style>

    <main class="correction-page">
        <header class="correction-page-header">
            <div>
                <h1>{{ $isEditing ? 'Edycja korekty' : 'Tworzenie korekty' }}</h1>
                <div>dla zamówienia <a href="{{ route('orders.show', $order) }}">{{ $sourceInvoice->order_reference_snapshot }}</a></div>
            </div>
            @if ($isEditing)
                <div class="btn-group correction-document-actions" role="group" aria-label="Akcje Korekty" data-correction-edit-actions>
                    <a class="btn" href="{{ route('invoices.pdf', $correction) }}" target="_blank" rel="noopener" data-correction-print-button>
                        <i class="bi bi-printer me-1"></i>Drukuj
                    </a>
                    <div class="btn-group" role="group">
                        <button class="btn dropdown-toggle dropdown-toggle-split" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                            <span class="visually-hidden">Opcje drukowania</span>
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end">
                            <li><a class="dropdown-item" href="{{ route('invoices.pdf', $correction) }}" target="_blank" rel="noopener">Otwórz dokument PDF</a></li>
                            <li><a class="dropdown-item" href="{{ route('invoices.pdf', $correction) }}" download>Pobierz dokument PDF</a></li>
                        </ul>
                    </div>
                    <button class="btn" type="button" disabled title="Wgrywanie dokumentów nie jest jeszcze dostępne." aria-label="Wgrywanie dokumentów nie jest jeszcze dostępne"><i class="bi bi-paperclip me-1"></i>Wgraj</button>
                    <button class="btn" type="button" disabled title="Integracja KSeF nie jest jeszcze dostępna." aria-label="Integracja KSeF nie jest jeszcze dostępna"><i class="bi bi-eraser-fill me-1"></i>Przekaż do KSeF</button>
                    <button class="btn" type="button" disabled title="Usuwanie Korekt nie jest jeszcze dostępne." aria-label="Usuwanie Korekt nie jest jeszcze dostępne"><i class="bi bi-trash me-1"></i>Usuń</button>
                    <a class="btn" href="{{ route('invoices.pdf', $correction) }}"><i class="bi bi-reply me-1"></i>Powrót</a>
                </div>
            @else
                <a class="btn btn-outline-secondary rounded-pill" href="{{ route('invoices.edit', $sourceInvoice) }}"><i class="bi bi-reply me-1"></i>Powrót</a>
            @endif
        </header>

        @if ($errors->any())
            <div class="alert alert-danger">
                <strong>{{ $isEditing ? 'Nie udało się zapisać Korekty.' : 'Nie udało się utworzyć Korekty.' }}</strong>
                <ul class="mb-0 mt-1">
                    @foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach
                </ul>
            </div>
        @endif

        <form method="POST" action="{{ $isEditing ? route('invoices.corrections.update', $correction) : route('invoices.corrections.store', $sourceInvoice) }}" data-correction-form>
            @csrf
            @if ($isEditing)
                @method('PATCH')
                <input type="hidden" name="expected_lock_version" value="{{ $correction->lock_version }}">
            @endif

            <section class="correction-card">
                <h2 class="correction-card-title">Faktura korygująca</h2>
                <div class="correction-fields">
                    <div class="correction-field">
                        <label for="correctionReason">Powód wystawienia</label>
                        <select class="form-select" id="correctionReason" name="reason" required data-correction-reason>
                            @foreach ($reasons as $reason)
                                <option value="{{ $reason->value }}" @selected($selectedReason === $reason->value)>{{ $reason->label() }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="correction-field" data-other-reason @if ($selectedReason !== 'other') hidden @endif>
                        <label for="correctionOtherReason">Inny powód</label>
                        <input class="form-control" id="correctionOtherReason" name="other_reason" value="{{ old('other_reason', $defaults['other_reason']) }}" maxlength="1000">
                    </div>
                    <div class="correction-field">
                        <label for="correctionSeries">Seria numeracji</label>
                        <select class="form-select" id="correctionSeries" name="correction_series_id" required data-correction-series @disabled($isEditing)>
                            @foreach ($correctionSeries as $series)
                                <option value="{{ $series->id }}" @selected((int) old('correction_series_id', $selectedSeries->id) === $series->id)>{{ $series->name }}</option>
                            @endforeach
                        </select>
                        @if ($isEditing)
                            <input type="hidden" name="correction_series_id" value="{{ $selectedSeries->id }}">
                        @endif
                    </div>
                    <div class="correction-field">
                        <label for="correctionIssueDate">Data wystawienia</label>
                        <input class="form-control" id="correctionIssueDate" type="date" name="issue_date" value="{{ old('issue_date', $defaults['issue_date']) }}" required>
                    </div>
                    <div class="correction-field">
                        <label for="correctionSaleDate">Data sprzedaży</label>
                        <input class="form-control" id="correctionSaleDate" type="date" name="sale_date" value="{{ old('sale_date', $defaults['sale_date']) }}" required>
                    </div>
                    <div class="correction-field">
                        <label for="correctionPaymentMethod">Sposób płatności</label>
                        <input class="form-control" id="correctionPaymentMethod" name="payment_method" value="{{ old('payment_method', $defaults['payment_method']) }}" maxlength="255">
                    </div>
                </div>

                <div class="correction-divider"></div>

                <div class="correction-fields">
                    <div class="correction-field correction-field-with-help">
                        <label for="correctionIssuer">Wystawiający</label>
                        <div>
                            <input class="form-control" id="correctionIssuer" name="issuer_name" value="{{ old('issuer_name', $defaults['issuer_name']) }}" maxlength="255">
                            <div class="correction-help">Osoba upoważniona do wystawienia faktury.</div>
                        </div>
                    </div>
                    <div class="correction-field correction-field-textarea">
                        <label for="correctionInformation">Informacje</label>
                        <div>
                            <textarea class="form-control" id="correctionInformation" name="additional_information" maxlength="5000">{{ old('additional_information', $defaults['additional_information']) }}</textarea>
                            <div class="correction-help">Dodatkowy tekst wyświetlany na dole Korekty.</div>
                        </div>
                    </div>
                    <div class="correction-option">
                        <div class="correction-option-label">Zmiana pozycji</div>
                        <div>
                            <div class="form-check"><input class="form-check-input" id="changeCorrectionItems" type="checkbox" name="change_items" value="1" @checked($changeItems) data-change-items><label class="form-check-label" for="changeCorrectionItems">Korekcie uległy pozycje faktury</label></div>
                            <div class="correction-help">Po zaznaczeniu tej opcji możliwa będzie aktualizacja pozycji Faktury korygującej.</div>
                        </div>
                    </div>
                    <div class="correction-option">
                        <div class="correction-option-label">Zmiana danych</div>
                        <div>
                            <div class="form-check"><input class="form-check-input" id="changeCorrectionBuyer" type="checkbox" name="change_buyer" value="1" @checked($changeBuyer) data-change-buyer><label class="form-check-label" for="changeCorrectionBuyer">Korekcie uległy inne dane na fakturze</label></div>
                            <div class="correction-help">Po zaznaczeniu tej opcji możliwa będzie aktualizacja danych nabywcy.</div>
                        </div>
                    </div>
                </div>
            </section>

            <section data-items-section @if (! $changeItems) hidden @endif>
                <h2 class="correction-items-title">Wszystkie pozycje po korekcie</h2>
                <div class="correction-items-card">
                    <div class="table-responsive">
                        <table class="table correction-items-table">
                            <colgroup><col class="correction-item-select"><col><col class="correction-item-quantity"><col class="correction-item-price"><col class="correction-item-vat"><col class="correction-item-action"><col class="correction-item-action"></colgroup>
                            <thead><tr><th style="width:40px"><input class="form-check-input" type="checkbox" data-select-all-items aria-label="Zaznacz wszystkie pozycje"></th><th>Nazwa</th><th class="text-end">Ilość</th><th class="text-end">Cena</th><th class="text-end">Stawka VAT</th><th class="text-center">Edytuj</th><th class="text-center">Usuń</th></tr></thead>
                            <tbody data-correction-items></tbody>
                        </table>
                    </div>
                    <div class="px-3 py-2 text-secondary" data-items-count></div>
                </div>
                <div class="correction-items-actions">
                    <button class="btn btn-primary correction-pill-button" type="button" data-add-item><i class="bi bi-plus-lg me-2"></i>Dodaj pozycję faktury</button>
                    <button class="correction-pill-button correction-copy-button" type="button" data-copy-order-items><i class="bi bi-arrow-repeat me-2"></i>Skopiuj aktualne produkty z zamówienia</button>
                    <button class="correction-pill-button correction-copy-button" type="button" data-return-selected><i class="bi bi-arrow-counterclockwise me-2"></i>Zwrot zaznaczonych</button>
                </div>
            </section>

            <section class="correction-card" data-buyer-section @if (! $changeBuyer) hidden @endif>
                <h2 class="correction-card-title">Dane nabywcy</h2>
                <div class="correction-buyer-grid">
                    <div class="correction-buyer-fields">
                        @foreach ([
                            'name' => 'Imię i nazwisko', 'company_name' => 'Firma', 'street' => 'Ulica',
                            'building_number' => 'Numer budynku', 'apartment_number' => 'Numer lokalu',
                            'postal_code' => 'Kod pocztowy', 'city' => 'Miasto', 'tax_id' => 'NIP',
                        ] as $field => $label)
                            <div class="correction-buyer-field"><label for="buyer_{{ $field }}">{{ $label }}</label><input class="form-control" id="buyer_{{ $field }}" name="buyer[{{ $field }}]" value="{{ old('buyer.'.$field, $formBuyer[$field] ?? '') }}" maxlength="255" data-buyer-field="{{ $field }}"></div>
                        @endforeach
                        <div class="correction-buyer-field">
                            <label for="buyer_country_code">Kraj</label>
                            <select class="form-select" id="buyer_country_code" name="buyer[country_code]" data-buyer-field="country_code">
                                <option value="">— Wybierz kraj —</option>
                                @foreach ($countries as $code => $name)<option value="{{ $code }}" @selected(old('buyer.country_code', $formBuyer['country_code'] ?? '') === $code)>{{ $name }}</option>@endforeach
                            </select>
                        </div>
                    </div>
                    <div class="correction-current-data">
                        <h3>Aktualne dane w zamówieniu <a href="{{ route('orders.show', $order) }}">{{ $sourceInvoice->order_reference_snapshot }}</a>:</h3>
                        <dl>
                            <dt>Imię i nazwisko:</dt><dd>{{ $currentBuyer['name'] ?: '—' }}</dd>
                            <dt>Firma:</dt><dd>{{ $currentBuyer['company_name'] ?: '—' }}</dd>
                            <dt>Ulica:</dt><dd>{{ $currentBuyer['street'] ?: '—' }}</dd>
                            <dt>Numer budynku:</dt><dd>{{ $currentBuyer['building_number'] ?: '—' }}</dd>
                            <dt>Numer lokalu:</dt><dd>{{ $currentBuyer['apartment_number'] ?: '—' }}</dd>
                            <dt>Kod pocztowy:</dt><dd>{{ $currentBuyer['postal_code'] ?: '—' }}</dd>
                            <dt>Miasto:</dt><dd>{{ $currentBuyer['city'] ?: '—' }}</dd>
                            <dt>Kraj:</dt><dd>{{ $currentBuyer['country_name'] ?: '—' }}</dd>
                            <dt>NIP:</dt><dd>{{ $currentBuyer['tax_id'] ?: '—' }}</dd>
                        </dl>
                        <button class="btn btn-outline-secondary correction-pill-button" type="button" data-copy-current-buyer>Skopiuj aktualne dane do Korekty</button>
                    </div>
                </div>
            </section>

            <div class="correction-page-actions">
                <button class="btn btn-primary rounded-pill px-4" type="submit">{{ $isEditing ? 'Zapisz' : 'Stwórz korektę' }}</button>
                <a class="btn btn-outline-secondary rounded-pill px-4" href="{{ $isEditing ? route('invoices.pdf', $correction) : route('invoices.edit', $sourceInvoice) }}">Anuluj</a>
            </div>
        </form>
    </main>

    <template id="correctionItemTemplate">
        <tr data-item-row>
            <td><input class="form-check-input" type="checkbox" data-item-select aria-label="Zaznacz pozycję"></td>
            <td data-item-display="name"></td><td class="text-end" data-item-display="quantity"></td><td class="text-end" data-item-display="unit_price_gross"></td><td class="text-end" data-item-display="vat_rate"></td>
            <td class="text-center"><button class="correction-icon-button" type="button" data-edit-item title="Edytuj pozycję"><i class="bi bi-pencil"></i></button></td>
            <td class="text-center"><button class="correction-icon-button" type="button" data-remove-item title="Usuń pozycję"><i class="bi bi-x-lg"></i></button></td>
        </tr>
        <tr class="correction-item-editor" data-item-editor hidden><td colspan="7"><div class="correction-item-editor-grid">
            <div><label>Nazwa produktu</label><input class="form-control form-control-sm" data-item-field="name" required maxlength="255"></div>
            <div><label>Ilość</label><input class="form-control form-control-sm" type="number" min="0" step="1" inputmode="numeric" data-item-field="quantity" required></div>
            <div><label>Cena brutto</label><input class="form-control form-control-sm" type="number" min="0" step="0.01" inputmode="decimal" data-item-field="unit_price_gross" required></div>
            <div><label>VAT (%)</label><input class="form-control form-control-sm" inputmode="decimal" data-item-field="vat_rate"></div>
            <button class="btn btn-sm btn-primary" type="button" data-close-item>Zapisz</button>
            <input type="hidden" data-item-field="source_item_id"><input type="hidden" data-item-field="order_item_id"><input type="hidden" data-item-field="line_type"><input type="hidden" data-item-field="position"><input type="hidden" data-item-field="description"><input type="hidden" data-item-field="unit_name"><input type="hidden" data-item-field="vat_code">
        </div></td></tr>
    </template>

    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const initialItems = @json($formItems);
            const currentOrderItems = @json($currentOrderItems);
            const currentBuyer = @json($currentBuyer);
            const itemsBody = document.querySelector('[data-correction-items]');
            const template = document.getElementById('correctionItemTemplate');
            let nextItemKey = 0;

            const updateCount = () => {
                const count = itemsBody?.querySelectorAll('[data-item-row]').length ?? 0;
                const countBox = document.querySelector('[data-items-count]');
                if (countBox) countBox.textContent = `${count} z ${count} pozycji`;
            };
            const refreshDisplay = (editor) => {
                const row = editor.previousElementSibling;
                ['name', 'quantity', 'unit_price_gross', 'vat_rate'].forEach((field) => {
                    const display = row.querySelector(`[data-item-display="${field}"]`);
                    const input = editor.querySelector(`[data-item-field="${field}"]`);
                    if (!display || !input) return;
                    display.textContent = field === 'unit_price_gross'
                        ? `${input.value} {{ $sourceInvoice->currency }}`
                        : field === 'vat_rate' ? `${input.value || '—'}${input.value ? '%' : ''}` : input.value;
                });
            };
            const addItem = (item, open = false) => {
                const key = nextItemKey++;
                const fragment = template.content.cloneNode(true);
                const row = fragment.querySelector('[data-item-row]');
                const editor = fragment.querySelector('[data-item-editor]');
                editor.querySelectorAll('[data-item-field]').forEach((input) => {
                    const field = input.dataset.itemField;
                    input.name = `items[${key}][${field}]`;
                    input.value = item[field] ?? ({ line_type:'custom', position:key + 1, unit_name:'szt.' }[field] ?? '');
                });
                row.querySelector('[data-edit-item]').addEventListener('click', () => { editor.hidden = !editor.hidden; });
                row.querySelector('[data-remove-item]').addEventListener('click', () => { row.remove(); editor.remove(); updateCount(); });
                editor.querySelector('[data-close-item]').addEventListener('click', () => { refreshDisplay(editor); editor.hidden = true; });
                editor.querySelectorAll('[data-item-field]').forEach((input) => input.addEventListener('input', () => refreshDisplay(editor)));
                itemsBody.append(fragment);
                refreshDisplay(editor);
                editor.hidden = !open;
                updateCount();
            };
            const replaceItems = (newItems) => {
                itemsBody.innerHTML = '';
                nextItemKey = 0;
                newItems.forEach((item) => addItem(item));
            };
            const currentFormItems = () => Array.from(itemsBody.querySelectorAll('[data-item-editor]')).map((editor) => {
                const item = {};
                editor.querySelectorAll('[data-item-field]').forEach((input) => { item[input.dataset.itemField] = input.value; });

                return item;
            });

            replaceItems(initialItems);
            document.querySelector('[data-change-items]')?.addEventListener('change', (event) => { document.querySelector('[data-items-section]').hidden = !event.target.checked; });
            document.querySelector('[data-change-buyer]')?.addEventListener('change', (event) => { document.querySelector('[data-buyer-section]').hidden = !event.target.checked; });
            document.querySelector('[data-correction-reason]')?.addEventListener('change', (event) => { document.querySelector('[data-other-reason]').hidden = event.target.value !== 'other'; });
            document.querySelector('[data-add-item]')?.addEventListener('click', () => addItem({ name:'', quantity:1, unit_price_gross:'0.00', vat_rate:'23.00', line_type:'custom', unit_name:'szt.', position:nextItemKey + 1 }, true));
            document.querySelector('[data-copy-order-items]')?.addEventListener('click', () => {
                const nonProductItems = currentFormItems().filter((item) => item.line_type !== 'product');
                replaceItems([...currentOrderItems, ...nonProductItems]);
            });
            document.querySelector('[data-return-selected]')?.addEventListener('click', () => {
                itemsBody.querySelectorAll('[data-item-row]').forEach((row) => {
                    if (!row.querySelector('[data-item-select]').checked) return;
                    const editor = row.nextElementSibling;
                    editor.querySelector('[data-item-field="quantity"]').value = '0';
                    refreshDisplay(editor);
                });
            });
            document.querySelector('[data-select-all-items]')?.addEventListener('change', (event) => { itemsBody.querySelectorAll('[data-item-select]').forEach((checkbox) => { checkbox.checked = event.target.checked; }); });
            document.querySelector('[data-copy-current-buyer]')?.addEventListener('click', () => {
                Object.entries(currentBuyer).forEach(([field, value]) => {
                    const input = document.querySelector(`[data-buyer-field="${field}"]`);
                    if (input) input.value = value ?? '';
                });
            });
            @if (! $isEditing)
                document.querySelector('[data-correction-series]')?.addEventListener('change', (event) => {
                    const url = new URL(@json(route('invoices.corrections.create', $sourceInvoice)), window.location.origin);
                    url.searchParams.set('series_id', event.target.value);
                    window.location.assign(url.toString());
                });
            @endif
        });
    </script>
@endsection
