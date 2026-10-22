<section aria-label="Przekazywanie numerów przesyłek Allegro">
    <form method="POST" action="{{ route('integrations.allegro.shipments.settings') }}">
        @csrf
        @method('PUT')
        <input type="hidden" name="identity" value="{{ $account->orderImportIdentity() }}">
        <input type="hidden" name="configuration_revision" value="{{ $account->shipment_sync_revision }}">
        @foreach (['shipment_settings', 'identity', 'configuration_revision'] as $field)
            @error($field)<div class="alert alert-danger py-2" role="alert">{{ $message }}</div>@enderror
        @endforeach
        <div class="row align-items-center g-3 mb-3">
            <label class="col-sm-4 col-form-label" for="allegroShipmentMode">Przekazywanie numerów przesyłek</label>
            <div class="col-sm-8">
                <select id="allegroShipmentMode" name="shipment_sync_mode" class="form-select">
                    @foreach (\Modules\Integrations\Allegro\Enums\AllegroShipmentSyncMode::cases() as $mode)
                        <option value="{{ $mode->value }}" @selected(old('shipment_sync_mode', $account->shipment_sync_mode->value) === $mode->value)>{{ $mode->label() }}</option>
                    @endforeach
                </select>
                @error('shipment_sync_mode')<div class="text-danger small mt-1">{{ $message }}</div>@enderror
            </div>
        </div>
        <div class="row align-items-center g-3 mb-3">
            <label class="col-sm-4 col-form-label" for="allegroShipmentStatus">Status OMS</label>
            <div class="col-sm-8">
                <select id="allegroShipmentStatus" name="shipment_sync_status_setting_id" class="form-select">
                    <option value="">Wybierz status</option>
                    @foreach ($omsStatuses as $omsStatus)
                        <option value="{{ $omsStatus['id'] }}" @selected((string) old('shipment_sync_status_setting_id', $account->shipment_sync_status_setting_id) === (string) $omsStatus['id'])>{{ $omsStatus['name'] }}</option>
                    @endforeach
                </select>
                @error('shipment_sync_status_setting_id')<div class="text-danger small mt-1">{{ $message }}</div>@enderror
            </div>
        </div>
        <div class="row"><div class="offset-sm-4 col-sm-8"><button class="btn btn-primary allegro-shipment-save" type="submit"><i class="bi bi-check-lg me-1" aria-hidden="true"></i>Zapisz</button></div></div>
    </form>
</section>
