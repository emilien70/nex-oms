<section aria-label="Pobieranie refundacji Allegro" class="border-top mt-4 pt-4">
    <h3 class="h6 mb-3">Zwroty wpłat</h3>
    @if (! in_array(\Modules\Integrations\Allegro\Models\AllegroAccount::PAYMENTS_READ_SCOPE, $account->granted_scopes ?? [], true))<div class="alert alert-warning py-2">Brak uprawnienia do odczytu płatności: allegro:api:payments:read.</div>@endif
    <form method="POST" action="{{ route('integrations.allegro.refunds.settings') }}">
        @csrf @method('PUT')
        <input type="hidden" name="identity" value="{{ $account->orderImportIdentity() }}"><input type="hidden" name="configuration_revision" value="{{ $account->refunds_revision }}">
        @error('refunds_settings')<div class="alert alert-danger py-2">{{ $message }}</div>@enderror
        <div class="row align-items-center g-3 mb-3"><label class="col-sm-4 col-form-label" for="allegroRefundsEnabled">Pobieraj zwroty wpłat</label><div class="col-sm-8"><input type="hidden" name="refunds_enabled" value="0"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" id="allegroRefundsEnabled" name="refunds_enabled" value="1" @checked(old('refunds_enabled', $account->refunds_enabled))></div>@error('refunds_enabled')<div class="text-danger small">{{ $message }}</div>@enderror</div></div>
        <div class="row align-items-center g-3 mb-3"><label class="col-sm-4 col-form-label" for="allegroRefundsFrom">Pobieraj refundacje od</label><div class="col-sm-8"><input class="form-control" type="date" id="allegroRefundsFrom" name="refunds_from" max="{{ now()->toDateString() }}" value="{{ old('refunds_from', $account->refunds_from_at?->timezone(config('app.timezone'))->toDateString() ?? now()->toDateString()) }}" required>@error('refunds_from')<div class="text-danger small">{{ $message }}</div>@enderror</div></div>
        <div class="row mb-3"><span class="col-sm-4">Częstotliwość</span><span class="col-sm-8">Co 5 minut</span></div>
        @if ($account->refunds_error)<div class="alert alert-warning py-2">{{ $account->refunds_error }}</div>@endif
        <div class="row"><div class="offset-sm-4 col-sm-8"><button class="btn btn-primary allegro-shipment-save" type="submit"><i class="bi bi-check-lg me-1" aria-hidden="true"></i>Zapisz</button></div></div>
    </form>
</section>
