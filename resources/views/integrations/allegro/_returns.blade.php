<section aria-label="Pobieranie zwrotów Allegro">
    <form method="POST" action="{{ route('integrations.allegro.returns.settings') }}">
        @csrf
        @method('PUT')
        <input type="hidden" name="identity" value="{{ $account->orderImportIdentity() }}">
        <input type="hidden" name="configuration_revision" value="{{ $account->returns_revision }}">
        @foreach (['returns_settings', 'identity', 'configuration_revision'] as $field)
            @error($field)<div class="alert alert-danger py-2" role="alert">{{ $message }}</div>@enderror
        @endforeach
        <div class="row align-items-center g-3 mb-3">
            <label class="col-sm-4 col-form-label" for="allegroReturnsEnabled">Automatyczne pobieranie zwrotów</label>
            <div class="col-sm-8">
                <input type="hidden" name="returns_enabled" value="0">
                <div class="form-check form-switch"><input class="form-check-input" id="allegroReturnsEnabled" name="returns_enabled" type="checkbox" value="1" @checked(old('returns_enabled', $account->returns_enabled))></div>
                @error('returns_enabled')<div class="text-danger small">{{ $message }}</div>@enderror
            </div>
        </div>
        <div class="row align-items-center g-3 mb-3">
            <label class="col-sm-4 col-form-label" for="allegroReturnsFrom">Pobieraj zwroty zgłoszone od</label>
            <div class="col-sm-8">
                <input class="form-control" type="date" id="allegroReturnsFrom" name="returns_from" max="{{ now()->toDateString() }}" value="{{ old('returns_from', $account->returns_from_at?->setTimezone(config('app.timezone'))->toDateString() ?? now()->toDateString()) }}" required>
                @error('returns_from')<div class="text-danger small">{{ $message }}</div>@enderror
            </div>
        </div>
        <div class="row mb-3"><span class="col-sm-4">Częstotliwość</span><span class="col-sm-8">Co 5 minut</span></div>
        @if ($account->returns_error)<div class="alert alert-warning py-2">{{ $account->returns_error }}</div>@endif
        <div class="row"><div class="offset-sm-4 col-sm-8"><button class="btn btn-primary allegro-shipment-save" type="submit"><i class="bi bi-check-lg me-1" aria-hidden="true"></i>Zapisz</button></div></div>
    </form>
</section>

@include('integrations.allegro._refunds')
