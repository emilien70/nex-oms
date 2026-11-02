<section class="halsky-section">
    <h2>Statusy zamówień</h2>
    @if (! $statusMappingsReady)
        <div class="alert alert-warning py-2 small" role="status">Najpierw wykonaj migrację przekazywania statusów Von Halsky.</div>
    @endif
    <form method="POST" action="{{ route('integrations.von-halsky.status-mappings') }}" data-halsky-submit>
        @csrf
        <input type="hidden" name="expected_lock_version" value="{{ $account->lock_version }}">
        <div class="halsky-form-row">
            <label for="halsky-status-sync">Powiązanie statusów</label>
            <select id="halsky-status-sync" name="status_sync_enabled" class="form-select form-select-sm" @disabled(! $statusMappingsReady || ! $account->exists)>
                <option value="0" @selected(! old('status_sync_enabled', $account->status_sync_enabled))>Nie</option>
                <option value="1" @selected((bool) old('status_sync_enabled', $account->status_sync_enabled))>Tak</option>
            </select>
        </div>
        @foreach ($orderStatuses as $status)
            <div class="row g-2 align-items-center border-bottom py-2">
                <label class="col-12 col-md-5 small" for="halsky-map-{{ $status['id'] ?? '' }}">{{ $status['name'] }}</label>
                <div class="col-md-1 text-center d-none d-md-block"><i class="bi bi-arrow-right" aria-hidden="true"></i></div>
                <div class="col-12 col-md-6">
                    <select id="halsky-map-{{ $status['id'] ?? '' }}" name="outbound[{{ $status['id'] ?? '' }}]" class="form-select form-select-sm" @disabled(! $statusMappingsReady || ! $account->exists)>
                        <option value="">Nie przekazuj</option>
                        @foreach (\Modules\Integrations\VonHalsky\Enums\VonHalskyOrderAction::cases() as $action)
                            <option value="{{ $action->value }}" @selected(old('outbound.'.($status['id'] ?? ''), $statusMappings->get($status['id'] ?? '')?->value) === $action->value)>{{ $action->label() }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
        @endforeach
        <div class="d-flex justify-content-between align-items-center mt-3 gap-2">
            <i class="bi bi-info-circle text-muted" title="Anulowane oznacza odmowę sprzedawcy, nie zwrot pieniędzy. Opłacone lub zaakceptowane zakupy nie mogą być anulowane tą operacją." aria-label="Ograniczenia anulowania"></i>
            <button class="btn btn-primary btn-sm" type="submit" @disabled(! $statusMappingsReady || ! $account->exists)><i class="bi bi-check-lg me-1" aria-hidden="true"></i>Zapisz</button>
        </div>
    </form>
</section>
