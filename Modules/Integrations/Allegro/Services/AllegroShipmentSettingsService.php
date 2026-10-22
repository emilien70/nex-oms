<?php

namespace Modules\Integrations\Allegro\Services;

use App\Models\OrderStatusSetting;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Integrations\Allegro\Enums\AllegroShipmentSyncMode;
use Modules\Integrations\Allegro\Models\AllegroAccount;
use Modules\Integrations\Allegro\Models\AllegroShipmentExport;

class AllegroShipmentSettingsService
{
    public function update(array $data): void
    {
        DB::transaction(function () use ($data): void {
            $account = AllegroAccount::query()->where('account_key', AllegroAccount::DEFAULT_ACCOUNT_KEY)->lockForUpdate()->firstOrFail();
            if ($data['identity'] !== $account->orderImportIdentity() || (int) $data['configuration_revision'] !== $account->shipment_sync_revision) {
                throw ValidationException::withMessages(['shipment_settings' => 'Konfiguracja lub konto Allegro zmieniły się. Odśwież stronę.']);
            }
            $mode = AllegroShipmentSyncMode::from($data['shipment_sync_mode']);
            if ($mode !== AllegroShipmentSyncMode::Disabled && ! $account->isOperational()) {
                throw ValidationException::withMessages(['shipment_sync_mode' => 'Najpierw połącz konto Allegro i wykonaj poprawny test połączenia.']);
            }
            $statusId = $mode === AllegroShipmentSyncMode::Status ? ($data['shipment_sync_status_setting_id'] ?? null) : null;
            if ($mode === AllegroShipmentSyncMode::Status && ! OrderStatusSetting::query()->lockForUpdate()->find($statusId)) {
                throw ValidationException::withMessages(['shipment_sync_status_setting_id' => 'Wybierz aktualny status OMS.']);
            }
            $account->forceFill([
                'shipment_sync_mode' => $mode, 'shipment_sync_status_setting_id' => $statusId,
                'shipment_sync_identity' => $account->orderImportIdentity(), 'shipment_sync_revision' => $account->shipment_sync_revision + 1,
            ])->save();
            AllegroShipmentExport::query()->where('allegro_account_id', $account->id)->whereIn('state', ['waiting', 'pending'])
                ->update(['state' => 'skipped', 'error' => null]);
        }, 3);
    }
}
