<?php

namespace Modules\Integrations\Allegro\Services;

use App\Models\OrderStatusSetting;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Integrations\Allegro\Enums\AllegroInvoiceSyncMode;
use Modules\Integrations\Allegro\Models\AllegroAccount;
use Modules\Integrations\Allegro\Models\AllegroInvoiceExport;

class AllegroInvoiceSettingsService
{
    public function update(array $data): void
    {
        $this->save($data, 'invoice');
    }

    public function updateCorrections(array $data): void
    {
        $this->save($data, 'correction');
    }

    private function save(array $data, string $type): void
    {
        $prefix = $type.'_sync_';
        DB::transaction(function () use ($data, $type, $prefix): void {
            $account = AllegroAccount::query()->where('account_key', AllegroAccount::DEFAULT_ACCOUNT_KEY)->lockForUpdate()->firstOrFail();
            if ($data['identity'] !== $account->orderImportIdentity() || (int) $data['configuration_revision'] !== $account->{$prefix.'revision'}) {
                throw ValidationException::withMessages(['invoice_settings' => 'Konfiguracja lub konto Allegro zmieniły się. Odśwież stronę.']);
            }
            $mode = AllegroInvoiceSyncMode::from($data[$prefix.'mode']);
            if ($mode !== AllegroInvoiceSyncMode::Disabled && ! $account->isOperational()) {
                throw ValidationException::withMessages([$prefix.'mode' => 'Najpierw połącz konto Allegro i wykonaj poprawny test połączenia.']);
            }
            $statusId = $mode === AllegroInvoiceSyncMode::Status ? ($data[$prefix.'status_setting_id'] ?? null) : null;
            if ($mode === AllegroInvoiceSyncMode::Status && ! OrderStatusSetting::query()->lockForUpdate()->find($statusId)) {
                throw ValidationException::withMessages([$prefix.'status_setting_id' => 'Wybierz aktualny status OMS.']);
            }
            $account->forceFill([
                $prefix.'mode' => $mode, $prefix.'status_setting_id' => $statusId,
                $prefix.'identity' => $account->orderImportIdentity(), $prefix.'revision' => $account->{$prefix.'revision'} + 1,
            ])->save();
            if ($type === 'invoice') {
                $account->forceFill(['invoice_sync_only_required' => (bool) $data['invoice_sync_only_required']])->save();
            }
            // Already-started uploads remain reconcilable even after disabling the automatic mode.
            AllegroInvoiceExport::query()->where('allegro_account_id', $account->id)->where('manual', false)
                ->where('document_type', $type)
                ->whereNull('upload_started_at')->whereIn('state', ['waiting', 'pending'])
                ->update(['state' => 'skipped', 'error' => null]);
        }, 3);
    }
}
