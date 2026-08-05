@extends('layouts.app')

@section('title', 'Edycja faktury '.$invoice->number.' - NEX-OMS')

@section('content')
    <style>
        .invoice-edit-page { margin: -1.5rem; padding: 20px; background: #f4f6f8; min-height: 100vh; color: #374151; font-size: 13px; }
        .invoice-edit-card { background: #fff; border: 1px solid #dfe4ea; border-radius: 7px; box-shadow: 0 1px 3px rgba(15,23,42,.07); margin-bottom: 14px; }
        .invoice-edit-card-header { display:flex; align-items:center; justify-content:space-between; border-bottom:1px solid #e5e7eb; padding:12px 16px; }
        .invoice-edit-card-body { padding:16px; }
        .invoice-edit-title { color:#20242a; font-size:28px; font-weight:600; line-height:1.2; margin:0; }
        .invoice-edit-subtitle { color:#64748b; font-size:14px; margin-top:6px; }
        .invoice-document-actions { border:1px solid #cfd5dc; border-radius:22px; flex-wrap:nowrap; max-width:100%; }
        .invoice-document-actions .btn { align-items:center; background:#fff; border:0; border-left:1px solid #cfd5dc; border-radius:0; color:#4e565f; display:inline-flex; font-size:13px; justify-content:center; min-height:40px; padding:0 12px; white-space:nowrap; }
        .invoice-document-actions > :first-child { border-left:0; border-radius:21px 0 0 21px; }
        .invoice-document-actions > :last-child { border-radius:0 21px 21px 0; }
        .invoice-document-actions .btn:hover,.invoice-document-actions .btn:focus { background:#f8fafc; color:#20242a; }
        .invoice-document-actions .btn:disabled { background:#fff; color:#94a3b8; opacity:1; }
        .invoice-document-actions .dropdown-toggle-split { min-width:38px; padding:0 10px; }
        .invoice-document-actions .dropdown-menu { border-color:#cfd5dc; font-size:13px; }
        .invoice-edit-banner { border-left:3px solid #0d6efd; background:#eef6ff; padding:12px 15px; margin-bottom:14px; }
        .invoice-edit-form-grid { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:10px 14px; }
        .invoice-edit-form-grid .full { grid-column:1/-1; }
        .invoice-edit-form-grid label { color:#64748b; display:block; font-size:12px; margin-bottom:3px; }
        .invoice-edit-form-grid .form-control,.invoice-edit-form-grid .form-select { font-size:13px; min-height:34px; padding:5px 8px; }
        .invoice-edit-address-list { display:flex; flex-direction:column; gap:10px; }
        .invoice-edit-address-list > div { display:grid; grid-template-columns:220px minmax(0,1fr); align-items:center; gap:14px; }
        .invoice-edit-address-list label { color:#4e565f; font-size:12px; margin:0; text-align:right; }
        .invoice-edit-address-list .form-control,.invoice-edit-address-list .form-select { font-size:13px; min-height:40px; padding:7px 12px; }
        .invoice-edit-address-actions { margin-left:234px; }
        .invoice-edit-error { font-size:12px; margin-bottom:10px; padding:7px 9px; }
        .invoice-current-data { background:#fff; border-left:1px solid #e5e7eb; padding:16px; height:100%; }
        .invoice-current-data-title { color:#20242a; font-size:18px; font-weight:600; margin-bottom:8px; }
        .invoice-current-data dl { display:grid; grid-template-columns:40% 60%; gap:0; margin:0; }
        .invoice-current-data dt,.invoice-current-data dd { border-bottom:1px solid #cfd5dc; min-height:28px; padding:5px 8px; }
        .invoice-current-data dt { color:#4e565f; font-weight:500; }
        .invoice-current-data dd { color:#4e565f; margin:0; overflow-wrap:anywhere; }
        .invoice-copy-current-button { align-items:center; background:#fff; border:1px solid #cfd5dc; border-radius:20px; color:#374151; display:inline-flex; font-size:13px; justify-content:center; min-height:40px; padding:0 20px; }
        .invoice-copy-current-button:hover,.invoice-copy-current-button:focus { background:#f8fafc; border-color:#b8c0ca; color:#20242a; }
        .invoice-save-button { align-items:center; background:#087fe5; border:1px solid #087fe5; border-radius:20px; color:#fff; display:inline-flex; font-size:13px; font-weight:500; justify-content:center; min-height:40px; min-width:80px; padding:0 20px; }
        .invoice-save-button:hover,.invoice-save-button:focus { background:#0672cf; border-color:#0672cf; color:#fff; }
        .invoice-copy-products-button { align-items:center; background:#fff; border:1px solid #f07a18; border-radius:20px; color:#e76517; display:inline-flex; font-size:13px; justify-content:center; min-height:40px; padding:0 20px; }
        .invoice-copy-products-button:hover,.invoice-copy-products-button:focus { background:#fff8f2; border-color:#df6810; color:#d85d0d; }
        .invoice-add-item-button { align-items:center; background:#087fe5; border:1px solid #087fe5; border-radius:20px; color:#fff; display:inline-flex; font-size:13px; font-weight:500; justify-content:center; min-height:40px; padding:0 20px; }
        .invoice-add-item-button:hover,.invoice-add-item-button:focus { background:#0672cf; border-color:#0672cf; color:#fff; }
        .invoice-items-table { margin:0; font-size:13px; }
        .invoice-items-table th { color:#4e565f; font-size:10px; font-weight:600; text-transform:uppercase; white-space:nowrap; }
        .invoice-items-table td { vertical-align:middle; }
        .invoice-items-table th:first-child,.invoice-items-table td:first-child { padding-left:10px; }
        .invoice-items-table .invoice-item-quantity { width:80px; }
        .invoice-items-table .invoice-item-price { width:110px; }
        .invoice-items-table .invoice-item-vat { width:90px; }
        .invoice-items-table .invoice-item-action { width:55px; }
        .invoice-item-row.is-editing > td { background:#dceeff; }
        .invoice-item-editor-row > td { background:#fff; padding:10px 14px 14px; }
        .invoice-item-inline-form { background:#f8fafc; border:1px solid #dfe4ea; border-radius:12px; padding:14px; }
        .invoice-item-inline-form .form-label { color:#374151; font-size:12px; margin-bottom:5px; }
        .invoice-item-inline-form .form-control { font-size:13px; min-height:32px; }
        .invoice-item-inline-form .form-control[readonly] { background:#fff; color:#4e565f; }
        .invoice-item-inline-save { min-height:32px; min-width:58px; }
        .invoice-icon-button { width:30px; height:30px; display:inline-flex; align-items:center; justify-content:center; padding:0; }
        .invoice-edit-item-button { background:#fff; border:1px solid #edf0f3; border-radius:50%; color:#4e565f; }
        .invoice-edit-item-button:hover,.invoice-edit-item-button:focus { background:#f8fafc; border-color:#dfe4ea; color:#20242a; }
        .invoice-delete-item-button { background:#fff; border:1px solid #edf0f3; border-radius:50%; color:#64748b; }
        .invoice-delete-item-button:hover,.invoice-delete-item-button:focus { background:#f8fafc; border-color:#dfe4ea; color:#20242a; }
        .invoice-details-card-header { border-bottom:0; gap:12px; justify-content:flex-start; padding:18px 28px 10px; }
        .invoice-details-card-header::before { background:#087fe5; border-radius:50%; content:""; flex:0 0 10px; height:10px; width:10px; }
        .invoice-details-section { border-top:1px solid #d7dde4; padding:18px 28px; }
        .invoice-details-section-first { border-top:0; padding-top:14px; }
        .invoice-details-section-last { padding-bottom:32px; }
        .invoice-details-fields { margin-left:16%; max-width:800px; }
        .invoice-details-field { align-items:center; display:grid; gap:14px; grid-template-columns:170px minmax(0,380px); margin-bottom:10px; }
        .invoice-details-field > label { color:#4e565f; font-size:12px; margin:0; text-align:right; }
        .invoice-details-field-with-help { align-items:start; }
        .invoice-details-field-with-help > label { padding-top:10px; }
        .invoice-details-field .form-control,.invoice-details-field .form-select { background-color:#fff; border-color:#cfd5dc; color:#4e565f; font-size:13px; min-height:38px; padding:7px 12px; }
        .invoice-details-field .form-control[readonly],.invoice-details-field .form-select:disabled { background-color:#fff; color:#4e565f; opacity:1; }
        .invoice-details-field-textarea { align-items:start; }
        .invoice-details-field-textarea > label { padding-top:9px; }
        .invoice-details-field textarea.form-control { line-height:1.45; min-height:136px; resize:vertical; }
        .invoice-details-help { color:#64748b; font-size:12px; line-height:1.35; margin-top:5px; }
        .invoice-details-actions { margin-left:184px; padding-top:2px; }
        @media(max-width:991.98px){.invoice-edit-title{font-size:22px}.invoice-edit-form-grid{grid-template-columns:1fr}.invoice-edit-address-list>div{grid-template-columns:1fr;gap:3px}.invoice-edit-address-list label{text-align:left}.invoice-edit-address-actions{margin-left:0}.invoice-current-data{border-left:0;border-top:1px solid #e5e7eb}.invoice-edit-page{margin:-1rem;padding:12px}.invoice-details-section{padding:16px}.invoice-details-fields{margin-left:0;max-width:none}.invoice-details-field{grid-template-columns:1fr;gap:4px}.invoice-details-field>label{text-align:left}.invoice-details-field-with-help>label,.invoice-details-field-textarea>label{padding-top:0}.invoice-details-actions{margin-left:0}}
    </style>

    <main class="invoice-edit-page" data-invoice-edit-page>
        <div class="invoice-edit-banner" role="note">
            Dane Faktury i jej pozycje są zapisane niezależnie od zamówienia. Późniejsze zmiany zamówienia nie zmienią tego dokumentu. Aktualne dane zamówienia można skopiować ręcznie.
        </div>

        <div class="d-flex flex-wrap align-items-start justify-content-between gap-3 mb-3">
            <div>
                <h1 class="invoice-edit-title">Faktura VAT {{ $invoice->number }}</h1>
                <div class="invoice-edit-subtitle">dla zamówienia <a href="{{ route('orders.show', $order) }}">{{ $invoice->order_reference_snapshot }}</a></div>
            </div>
            <div class="btn-group invoice-document-actions" role="group" aria-label="Akcje Faktury">
                <a class="btn" href="{{ route('invoices.pdf', $invoice) }}" target="_blank" rel="noopener" data-invoice-print-button>
                    <i class="bi bi-printer me-1"></i>Drukuj
                </a>
                <div class="btn-group" role="group">
                    <button class="btn dropdown-toggle dropdown-toggle-split" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                        <span class="visually-hidden">Opcje drukowania</span>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end">
                        <li><a class="dropdown-item" href="{{ route('invoices.pdf', $invoice) }}" target="_blank" rel="noopener">Otwórz dokument PDF</a></li>
                        <li><a class="dropdown-item" href="{{ route('invoices.pdf', $invoice) }}" download>Pobierz dokument PDF</a></li>
                    </ul>
                </div>
                <button class="btn" type="button" disabled aria-label="Wgraj dokument"><i class="bi bi-paperclip me-1"></i>Wgraj</button>
                <button class="btn" type="button" disabled aria-label="Wystaw korektę"><i class="bi bi-eraser-fill me-1"></i>Korekta</button>
                <button class="btn" type="button" data-bs-toggle="modal" data-bs-target="#deleteInvoiceFromEditModal" aria-label="Usuń Fakturę"><i class="bi bi-trash me-1"></i>Usuń</button>
                <a class="btn" href="{{ route('orders.show', $order) }}"><i class="bi bi-reply me-1"></i>Powrót</a>
            </div>
        </div>

        @include('invoices.partials.delete-modal', [
            'invoice' => $invoice,
            'modalId' => 'deleteInvoiceFromEditModal',
            'ajax' => false,
        ])

        <div data-invoice-fragment="items">@include('invoices.edit.partials.items')</div>
        <div data-invoice-fragment="nbp-summary">@include('invoices.edit.partials.nbp-summary')</div>
        <div data-invoice-fragment="buyer">@include('invoices.edit.partials.buyer')</div>
        <div data-invoice-fragment="details">@include('invoices.edit.partials.details')</div>
    </main>

    <div class="modal fade" id="invoiceItemModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <form class="modal-content" method="POST" data-invoice-ajax-form data-item-form action="{{ route('invoices.items.store', $invoice) }}">
                @csrf
                <input type="hidden" name="_method" value="POST" data-item-method>
                <input type="hidden" name="expected_lock_version" value="{{ $invoice->lock_version }}" data-lock-version-input>
                <div class="modal-header"><h2 class="modal-title fs-6" data-item-title>Dodaj pozycję Faktury</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Zamknij"></button></div>
                <div class="modal-body">
                    <div class="alert alert-danger invoice-edit-error" data-form-error hidden></div>
                    <div class="row g-2">
                        <div class="col-12"><label class="form-label">Nazwa</label><input class="form-control form-control-sm" name="name" required maxlength="255"></div>
                        <div class="col-12"><label class="form-label">Opis</label><textarea class="form-control form-control-sm" name="description" rows="2"></textarea></div>
                        <div class="col-4"><label class="form-label">Jednostka</label><input class="form-control form-control-sm" name="unit_name" value="szt." required></div>
                        <div class="col-4"><label class="form-label">Ilość</label><input type="number" class="form-control form-control-sm" name="quantity" value="1" min="0" step="1" inputmode="numeric" required></div>
                        <div class="col-4"><label class="form-label">Pozycja</label><input class="form-control form-control-sm" name="position" value="{{ $invoice->items->count() + 1 }}" inputmode="numeric" required></div>
                        <div class="col-6"><label class="form-label">Cena brutto</label><input type="number" class="form-control form-control-sm" name="unit_price_gross" value="0.00" min="0" step="0.01" inputmode="decimal" required></div>
                        <div class="col-6"><label class="form-label">VAT (%)</label><input class="form-control form-control-sm" name="vat_rate" value="23" inputmode="decimal"></div>
                        <input type="hidden" name="vat_code" value="">
                    </div>
                </div>
                <div class="modal-footer"><button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Anuluj</button><button class="btn btn-sm btn-primary" type="submit">Zapisz</button></div>
            </form>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const modalElement = document.getElementById('invoiceItemModal');
            const itemModal = modalElement ? bootstrap.Modal.getOrCreateInstance(modalElement) : null;
            const itemForm = modalElement?.querySelector('[data-item-form]');
            const createUrl = @json(route('invoices.items.store', $invoice));

            const updateLockVersion = (lockVersion) => {
                document.querySelectorAll('[data-lock-version-input]').forEach((input) => { input.value = lockVersion; });
            };
            const showError = (form, message) => {
                const box = form.querySelector('[data-form-error]');
                if (!box) return;
                box.textContent = message;
                box.hidden = false;
            };
            const clearError = (form) => {
                const box = form.querySelector('[data-form-error]');
                if (box) { box.textContent = ''; box.hidden = true; }
            };
            const apply = (payload) => {
                Object.entries(payload.fragments || {}).forEach(([name, html]) => {
                    const host = document.querySelector(`[data-invoice-fragment="${name}"]`);
                    if (host) host.innerHTML = html;
                });
                updateLockVersion(payload.lock_version);
            };

            document.addEventListener('submit', async (event) => {
                const form = event.target.closest('[data-invoice-ajax-form]');
                if (!form) return;
                event.preventDefault();
                clearError(form);
                const buttons = form.querySelectorAll('button');
                buttons.forEach((button) => { button.disabled = true; });
                try {
                    const response = await fetch(form.action, { method: 'POST', body: new FormData(form), headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' } });
                    const payload = await response.json().catch(() => ({}));
                    if (!response.ok) {
                        const firstValidation = payload.errors ? Object.values(payload.errors).flat()[0] : null;
                        throw new Error(firstValidation || payload.message || 'Nie udało się zapisać zmian.');
                    }
                    apply(payload);
                    if (form.matches('[data-item-form]')) itemModal?.hide();
                } catch (error) {
                    showError(form, error.message);
                } finally {
                    buttons.forEach((button) => { button.disabled = false; });
                }
            });

            document.addEventListener('click', (event) => {
                const add = event.target.closest('[data-add-invoice-item]');
                if (add && itemForm) {
                    itemForm.reset(); itemForm.action = createUrl;
                    itemForm.querySelector('[data-item-method]').value = 'POST';
                    itemForm.querySelector('[data-item-title]').textContent = 'Dodaj pozycję Faktury';
                    itemForm.querySelector('[name="position"]').value = add.dataset.nextPosition;
                    updateLockVersion(add.dataset.lockVersion);
                    clearError(itemForm); itemModal?.show(); return;
                }
                const copy = event.target.closest('[data-copy-address]');
                if (copy) {
                    const form = document.querySelector(copy.dataset.form);
                    const values = JSON.parse(copy.dataset.values);
                    Object.entries(values).forEach(([name, value]) => { const field = form?.elements.namedItem(name); if (field) field.value = value ?? ''; });
                }
                const replace = event.target.closest('[data-copy-order-items]');
                if (replace && !window.confirm('Aktualne pozycje Faktury zostaną zastąpione. Czy kontynuować?')) event.preventDefault();
            });

            document.addEventListener('show.bs.collapse', (event) => {
                const editor = event.target.closest('[data-invoice-item-editor]');
                if (!editor) return;

                document.querySelectorAll('[data-invoice-item-editor].show').forEach((openEditor) => {
                    if (openEditor !== editor) bootstrap.Collapse.getOrCreateInstance(openEditor).hide();
                });
                document.querySelectorAll('[data-invoice-item-row]').forEach((row) => row.classList.remove('is-editing'));
                editor.previousElementSibling?.classList.add('is-editing');
            });

            document.addEventListener('hidden.bs.collapse', (event) => {
                const editor = event.target.closest('[data-invoice-item-editor]');
                if (editor) editor.previousElementSibling?.classList.remove('is-editing');
            });
        });
    </script>
@endsection
