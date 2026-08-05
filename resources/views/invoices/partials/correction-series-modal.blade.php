@if ($correctionSeries->count() > 1)
    <div class="modal fade" id="{{ $modalId }}" tabindex="-1" aria-labelledby="{{ $modalId }}Label" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <form class="modal-content" method="GET" data-correction-series-form>
                <div class="modal-header">
                    <h2 class="modal-title fs-6" id="{{ $modalId }}Label">Seria numeracji</h2>
                    <button class="btn-close" type="button" data-bs-dismiss="modal" aria-label="Zamknij"></button>
                </div>
                <div class="modal-body">
                    <p class="mb-3">
                        Czy chcesz wystawić Korektę do wybranej Faktury VAT? Korekta otrzyma kolejny numer z wybranej serii i zostanie wystawiona po zatwierdzeniu formularza.
                    </p>
                    <label class="form-label" for="{{ $modalId }}Series">Seria numeracji:</label>
                    <select class="form-select" id="{{ $modalId }}Series" name="series_id" required>
                        @foreach ($correctionSeries as $series)
                            <option value="{{ $series->id }}">{{ $series->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="modal-footer">
                    <button class="btn btn-outline-secondary rounded-pill" type="button" data-bs-dismiss="modal">Zamknij</button>
                    <button class="btn btn-primary rounded-pill" type="submit">Potwierdź</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const modal = document.getElementById(@json($modalId));
            const form = modal?.querySelector('[data-correction-series-form]');

            modal?.addEventListener('show.bs.modal', (event) => {
                const url = event.relatedTarget?.dataset.correctionUrl;
                if (form && url) form.action = url;
            });
        });
    </script>
@endif
