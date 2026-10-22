<section aria-label="Przekazywanie faktur Allegro">
    <h3 class="h6 mb-3">Faktury VAT</h3>
    <form method="POST" action="{{ route('integrations.allegro.invoices.settings') }}">
        @csrf
        @method('PUT')
        <input type="hidden" name="identity" value="{{ $account->orderImportIdentity() }}">
        <input type="hidden" name="configuration_revision" value="{{ $account->invoice_sync_revision }}">
        @foreach (['invoice_settings', 'identity', 'configuration_revision'] as $field)
            @error($field)<div class="alert alert-danger py-2" role="alert">{{ $message }}</div>@enderror
        @endforeach
        <div class="row align-items-center g-3 mb-3">
            <label class="col-sm-4 col-form-label" for="allegroInvoiceMode">Przekazywanie automatyczne</label>
            <div class="col-sm-8">
                <select id="allegroInvoiceMode" name="invoice_sync_mode" class="form-select">
                    @foreach (\Modules\Integrations\Allegro\Enums\AllegroInvoiceSyncMode::cases() as $mode)
                        <option value="{{ $mode->value }}" @selected(old('invoice_sync_mode', $account->invoice_sync_mode->value) === $mode->value)>{{ $mode->label() }}</option>
                    @endforeach
                </select>
                @error('invoice_sync_mode')<div class="text-danger small mt-1">{{ $message }}</div>@enderror
            </div>
        </div>
        <div class="row align-items-center g-3 mb-3">
            <label class="col-sm-4 col-form-label" for="allegroInvoiceStatus">Status OMS</label>
            <div class="col-sm-8">
                <select id="allegroInvoiceStatus" name="invoice_sync_status_setting_id" class="form-select">
                    <option value="">Wybierz status</option>
                    @foreach ($omsStatuses as $omsStatus)
                        <option value="{{ $omsStatus['id'] }}" @selected((string) old('invoice_sync_status_setting_id', $account->invoice_sync_status_setting_id) === (string) $omsStatus['id'])>{{ $omsStatus['name'] }}</option>
                    @endforeach
                </select>
                @error('invoice_sync_status_setting_id')<div class="text-danger small mt-1">{{ $message }}</div>@enderror
            </div>
        </div>
        <div class="row align-items-center g-3 mb-3">
            <label class="col-sm-4 col-form-label" for="allegroInvoiceRequired">Przekazywane faktury</label>
            <div class="col-sm-8">
                <select id="allegroInvoiceRequired" name="invoice_sync_only_required" class="form-select">
                    <option value="0" @selected(! old('invoice_sync_only_required', $account->invoice_sync_only_required))>Wszystkie wystawione faktury VAT</option>
                    <option value="1" @selected(old('invoice_sync_only_required', $account->invoice_sync_only_required))>Tylko gdy klient poprosił o fakturę</option>
                </select>
                @error('invoice_sync_only_required')<div class="text-danger small mt-1">{{ $message }}</div>@enderror
            </div>
        </div>
        <div class="row"><div class="offset-sm-4 col-sm-8"><button class="btn btn-primary allegro-shipment-save" type="submit"><i class="bi bi-check-lg me-1" aria-hidden="true"></i>Zapisz</button></div></div>
    </form>
</section>
<section class="mt-4 pt-3 border-top" aria-label="Przekazywanie korekt Allegro">
    <h3 class="h6 mb-3">Korekty</h3>
    <form method="POST" action="{{ route('integrations.allegro.corrections.settings') }}">
        @csrf
        @method('PUT')
        <input type="hidden" name="identity" value="{{ $account->orderImportIdentity() }}">
        <input type="hidden" name="configuration_revision" value="{{ $account->correction_sync_revision }}">
        <div class="row align-items-center g-3 mb-3">
            <label class="col-sm-4 col-form-label" for="allegroCorrectionMode">Przekazywanie automatyczne</label>
            <div class="col-sm-8">
                <select id="allegroCorrectionMode" name="correction_sync_mode" class="form-select">
                    @foreach (\Modules\Integrations\Allegro\Enums\AllegroInvoiceSyncMode::cases() as $mode)
                        <option value="{{ $mode->value }}" @selected(old('correction_sync_mode', $account->correction_sync_mode->value) === $mode->value)>{{ $mode->correctionLabel() }}</option>
                    @endforeach
                </select>
                @error('correction_sync_mode')<div class="text-danger small mt-1">{{ $message }}</div>@enderror
            </div>
        </div>
        <div class="row align-items-center g-3 mb-3">
            <label class="col-sm-4 col-form-label" for="allegroCorrectionStatus">Status OMS</label>
            <div class="col-sm-8">
                <select id="allegroCorrectionStatus" name="correction_sync_status_setting_id" class="form-select">
                    <option value="">Wybierz status</option>
                    @foreach ($omsStatuses as $omsStatus)
                        <option value="{{ $omsStatus['id'] }}" @selected((string) old('correction_sync_status_setting_id', $account->correction_sync_status_setting_id) === (string) $omsStatus['id'])>{{ $omsStatus['name'] }}</option>
                    @endforeach
                </select>
                @error('correction_sync_status_setting_id')<div class="text-danger small mt-1">{{ $message }}</div>@enderror
            </div>
        </div>
        <div class="row"><div class="offset-sm-4 col-sm-8"><button class="btn btn-primary allegro-shipment-save" type="submit"><i class="bi bi-check-lg me-1" aria-hidden="true"></i>Zapisz</button></div></div>
    </form>
</section>
