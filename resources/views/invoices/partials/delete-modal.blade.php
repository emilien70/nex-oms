@php
    $isProforma = $invoice->isProforma();
    $isCorrection = $invoice->isCorrection();
    $documentLabel = $isCorrection ? 'Korekty' : ($isProforma ? 'Pro formy' : 'Faktury VAT');
    $documentName = $isCorrection ? 'Korektę' : ($isProforma ? 'Pro formę' : 'fakturę');
@endphp

<div class="modal fade" id="{{ $modalId }}" tabindex="-1" aria-labelledby="{{ $modalId }}Label" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title fs-6" id="{{ $modalId }}Label">Usuwanie {{ $documentLabel }}</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Zamknij"></button>
            </div>
            <div class="modal-body">
                Czy na pewno chcesz usunąć {{ $documentName }} „{{ $invoice->number }}”? Tej operacji nie można cofnąć.
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Anuluj</button>
                <form method="POST" action="{{ route('invoices.destroy', $invoice) }}" @if ($ajax ?? false) data-sales-document-form data-sales-document-delete-form @endif @if ($isCorrection) data-correction-delete-form @endif>
                    @csrf
                    @method('DELETE')
                    <input type="hidden" name="expected_lock_version" value="{{ $invoice->lock_version }}">
                    <button type="submit" class="btn btn-danger">Usuń</button>
                </form>
            </div>
        </div>
    </div>
</div>
