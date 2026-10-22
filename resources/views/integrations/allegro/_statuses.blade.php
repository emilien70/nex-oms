<section aria-label="Powiązania statusów zamówień Allegro">
    <div class="allegro-status-direction"><span>OMS</span><i class="bi bi-arrow-right" aria-hidden="true"></i><span>Allegro</span></div>
    <form method="POST" action="{{ route('integrations.allegro.statuses.update') }}">
        @csrf
        @method('PUT')
        <input type="hidden" name="identity" value="{{ $account->orderImportIdentity() }}">
        <input type="hidden" name="configuration_revision" value="{{ $account->status_sync_revision }}">
        <input type="hidden" name="status_sync_enabled" value="0">
        <div class="allegro-status-switch">
            <label for="allegroStatusSyncEnabled">Powiązanie statusów</label>
            <div class="form-check form-switch">
                <input class="form-check-input" type="checkbox" role="switch" id="allegroStatusSyncEnabled" name="status_sync_enabled" value="1" @checked(old('status_sync_enabled', $account->status_sync_enabled)) aria-label="Powiązanie statusów">
            </div>
        </div>
        @foreach (['status_mappings', 'status_sync_enabled', 'identity', 'configuration_revision', 'outbound'] as $field)
            @error($field)<div class="alert alert-danger py-2" role="alert">{{ $message }}</div>@enderror
        @endforeach
        @foreach ($omsStatuses as $omsStatus)
            @php($selectedStatus = old('outbound.'.$omsStatus['id'], $outboundMappings->get($omsStatus['id'])?->allegro_status?->value))
            <div class="allegro-status-row" data-allegro-status-row="{{ $omsStatus['code'] }}">
                <label for="allegroStatusMapping{{ $omsStatus['id'] }}">{{ $omsStatus['name'] }}</label>
                <i class="bi bi-arrow-right allegro-status-arrow" aria-hidden="true"></i>
                <div class="w-100">
                    <select class="form-select" id="allegroStatusMapping{{ $omsStatus['id'] }}" name="outbound[{{ $omsStatus['id'] }}]" aria-label="Status Allegro dla: {{ $omsStatus['name'] }}">
                        <option value="" @selected(blank($selectedStatus))>Nie przekazuj</option>
                        @foreach ($fulfillmentStatuses as $fulfillmentStatus)
                            <option value="{{ $fulfillmentStatus->value }}" @selected($selectedStatus === $fulfillmentStatus->value)>{{ $fulfillmentStatus->label() }}</option>
                        @endforeach
                    </select>
                    @error('outbound.'.$omsStatus['id'])<div class="text-danger small mt-1">{{ $message }}</div>@enderror
                </div>
            </div>
        @endforeach
        <div class="allegro-status-footer"><button class="btn btn-primary" type="submit"><i class="bi bi-check-lg me-1" aria-hidden="true"></i>Zapisz</button></div>
    </form>
</section>
