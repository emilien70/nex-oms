<?php

namespace Modules\Integrations\Allegro\Services;

use App\Models\OrderStatusSetting;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Integrations\Allegro\Enums\AllegroFulfillmentStatus;
use Modules\Integrations\Allegro\Models\AllegroAccount;

class AllegroStatusMappingService
{
    public function __construct(private readonly AllegroAccountService $accounts) {}

    public function update(array $outbound, bool $enabled, string $identity, int $revision): void
    {
        OrderStatusSetting::syncDefaults();
        $this->accounts->get();
        DB::transaction(function () use ($outbound, $enabled, $identity, $revision): void {
            $account = AllegroAccount::query()->where('account_key', AllegroAccount::DEFAULT_ACCOUNT_KEY)
                ->lockForUpdate()->firstOrFail();
            if ($account->orderImportIdentity() !== $identity || (int) $account->status_sync_revision !== $revision) {
                throw ValidationException::withMessages(['status_mappings' => 'Konfiguracja lub konto Allegro zmieniły się. Odśwież stronę.']);
            }
            if ($enabled && ! $account->isOperational()) {
                throw ValidationException::withMessages(['status_sync_enabled' => 'Najpierw połącz konto Allegro i wykonaj poprawny test połączenia.']);
            }
            $statuses = OrderStatusSetting::query()->lockForUpdate()->get()->keyBy('id');
            $rows = [];
            foreach ($outbound as $statusId => $value) {
                if (! ctype_digit((string) $statusId) || ! $statuses->has((int) $statusId)) {
                    throw ValidationException::withMessages(['outbound' => 'Lista statusów OMS zmieniła się. Odśwież stronę.']);
                }
                if ($value === null || $value === '') {
                    continue;
                }
                $target = is_string($value) ? AllegroFulfillmentStatus::tryFrom($value) : null;
                if ($target === null) {
                    throw ValidationException::withMessages(["outbound.{$statusId}" => 'Nieprawidłowy status realizacji Allegro.']);
                }
                $rows[] = [
                    'environment' => $account->environment, 'seller_user_id' => (string) $account->allegro_user_id,
                    'order_status_setting_id' => (int) $statusId, 'allegro_status' => $target->value,
                    'created_at' => now(), 'updated_at' => now(),
                ];
            }
            $account->outboundStatusMappings()->where('environment', $account->environment)
                ->where('seller_user_id', (string) $account->allegro_user_id)->delete();
            if ($rows !== []) {
                $account->outboundStatusMappings()->createMany($rows);
            }
            $account->forceFill([
                'status_sync_enabled' => $enabled, 'status_sync_identity' => $identity,
                'status_sync_revision' => $revision + 1,
            ])->save();
        }, 3);
    }
}
