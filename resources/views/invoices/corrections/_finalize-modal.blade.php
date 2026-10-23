<div class="modal fade" id="correctionFinalizeModal" tabindex="-1" aria-labelledby="correctionFinalizeTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form class="modal-content" method="POST" action="{{ route('invoices.corrections.finalize', $correction) }}" data-correction-finalize-form>
            @csrf
            <input type="hidden" name="expected_lock_version" value="{{ $correction->lock_version }}">
            <input type="hidden" name="expected_ksef_environment" value="{{ $manualFinalizationEnvironment->value }}">
            <input type="hidden" name="expected_source_invoice_id" value="{{ $manualFinalization['rootInvoice']->id }}">
            <input type="hidden" name="expected_source_invoice_lock_version" value="{{ $manualFinalization['rootInvoice']->lock_version }}">
            @foreach ($returnContext->parameters() as $key => $value)
                <input type="hidden" name="{{ $key }}" value="{{ $value }}">
            @endforeach
            <div class="modal-header">
                <h2 class="modal-title fs-5" id="correctionFinalizeTitle">Zamknij korektę {{ $correction->number }}</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Zamknij"></button>
            </div>
            <div class="modal-body">
                <p>Czy na pewno zamknąć korektę? Po zamknięciu nie będzie można jej edytować ani usunąć. Operacji nie można cofnąć.</p>
                @if (! $manualFinalization['rootInvoice']->isFinalized())
                    <p>Faktura pierwotna {{ $manualFinalization['rootInvoice']->number }} zostanie również trwale zamknięta. Nie będzie można jej edytować ani usunąć.</p>
                @endif
                @if ($manualFinalization['rootConfirmationRequired'])
                    <div class="form-check mb-3">
                        <input class="form-check-input" type="checkbox" name="confirm_source_outside_ksef" value="1" id="correctionSourceOutsideKsefConfirmation" required>
                        <label class="form-check-label" for="correctionSourceOutsideKsefConfirmation">Potwierdzam, że faktura {{ $manualFinalization['rootInvoice']->number }} została wystawiona poza KSeF w środowisku {{ strtoupper($manualFinalizationEnvironment->value) }}.</label>
                    </div>
                @endif
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" name="confirm_outside_ksef" value="1" id="correctionOutsideKsefConfirmation" required>
                    <label class="form-check-label" for="correctionOutsideKsefConfirmation">Potwierdzam, że ta korekta jest wystawiana poza KSeF i nie podlega obowiązkowi transmisji do KSeF w środowisku {{ strtoupper($manualFinalizationEnvironment->value) }}.</label>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-outline-secondary" type="button" data-bs-dismiss="modal">Anuluj</button>
                <button class="btn btn-primary" type="submit"><i class="bi bi-lock me-1" aria-hidden="true"></i>Zamknij korektę</button>
            </div>
        </form>
    </div>
</div>
<script>
    document.addEventListener('DOMContentLoaded', () => {
        const form = document.querySelector('[data-correction-finalize-form]');
        form?.addEventListener('submit', (event) => {
            if (form.dataset.submitting === 'true' || !form.reportValidity()) {
                event.preventDefault();
                return;
            }
            form.dataset.submitting = 'true';
            form.querySelectorAll('button[type="submit"]').forEach((button) => { button.disabled = true; });
        });
    });
</script>
