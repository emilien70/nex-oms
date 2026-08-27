<div class="modal fade" id="deleteSalesDocumentModal" tabindex="-1" aria-labelledby="deleteSalesDocumentModalLabel" aria-hidden="true" data-sales-document-delete-modal>
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title fs-6" id="deleteSalesDocumentModalLabel" data-sales-document-delete-title>Usuwanie dokumentu sprzedaży</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Zamknij"></button>
            </div>
            <div class="modal-body">
                <p class="mb-0" data-sales-document-delete-question></p>
                <div class="alert alert-danger mt-3 mb-0" data-sales-document-delete-error role="alert" hidden></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Anuluj</button>
                <form method="POST" action="" data-sales-document-form data-sales-document-delete-form>
                    @csrf
                    @method('DELETE')
                    <input type="hidden" name="expected_lock_version" value="" data-sales-document-delete-lock-version>
                    <input type="hidden" name="return_to" value="order">
                    <button type="submit" class="btn btn-danger">Usuń</button>
                </form>
            </div>
        </div>
    </div>
</div>
