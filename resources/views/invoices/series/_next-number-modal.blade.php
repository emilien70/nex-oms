<div
    class="modal fade"
    id="invoiceSeriesNextNumberModal"
    tabindex="-1"
    aria-labelledby="invoiceSeriesNextNumberModalTitle"
    aria-hidden="true"
    data-next-number-modal
    data-reopen-series-id="{{ old('next_number_series_id') }}"
    @if ($reopenNextNumberSeries !== null)
        data-reopen-form-url="{{ route('invoices.series.next-number.form', $reopenNextNumberSeries) }}"
        data-reopen-store-url="{{ route('invoices.series.next-number.store', $reopenNextNumberSeries) }}"
    @endif
>
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-lg">
        <form class="modal-content" method="POST" data-next-number-modal-form>
            @csrf
            <div class="modal-header">
                <h2 class="modal-title fs-6" id="invoiceSeriesNextNumberModalTitle">Ustaw następny numer</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Zamknij"></button>
            </div>
            <div class="modal-body" data-next-number-modal-body>
                <div class="invoice-series-modal-loading" role="status">
                    <span class="spinner-border spinner-border-sm" aria-hidden="true"></span>
                    <span>Ładowanie danych numeracji…</span>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light btn-sm border" data-bs-dismiss="modal">Anuluj</button>
                <button type="submit" class="btn btn-primary btn-sm" data-next-number-submit disabled>
                    Zapisz następny numer
                </button>
            </div>
        </form>
    </div>
</div>
