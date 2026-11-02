<?php

namespace Modules\Integrations\VonHalsky\Services;

use App\Models\OrderStatusSetting;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Modules\Integrations\VonHalsky\Enums\VonHalskyOrderAction;
use Modules\Integrations\VonHalsky\Models\VonHalskyAccount;
use Modules\Integrations\VonHalsky\Models\VonHalskyStatusMapping;

class VonHalskyStatusMappingService
{
    public function ready(): bool
    {
        return Schema::hasTable('von_halsky_status_mappings') && Schema::hasTable('von_halsky_order_commands')
            && Schema::hasColumns('von_halsky_accounts', ['status_sync_enabled', 'status_sync_revision', 'access_token_scopes'])
            && DB::table('migrations')->where('migration', '2026_10_30_020000_add_von_halsky_outbound_order_commands')->exists();
    }

    public function update(array $data): void
    {
        if (! $this->ready()) {
            throw ValidationException::withMessages(['status_sync_enabled' => 'Najpierw wykonaj migrację przekazywania statusów Von Halsky.']);
        }
        DB::transaction(function () use ($data): void {
            $account = VonHalskyAccount::query()->where('account_key', VonHalskyAccount::ACCOUNT_KEY)->lockForUpdate()->firstOrFail();
            if ($account->lock_version !== (int) $data['expected_lock_version']) {
                throw ValidationException::withMessages(['expected_lock_version' => 'Ustawienia zostały zmienione. Odśwież stronę.']);
            }
            if ($data['status_sync_enabled'] && (! $account->is_active || $account->last_test_status !== 'success' || ! $account->hasCompleteCredentials())) {
                throw ValidationException::withMessages(['status_sync_enabled' => 'Najpierw potwierdź połączenie Von Halsky.']);
            }
            $statuses = OrderStatusSetting::query()->lockForUpdate()->get()->keyBy('id');
            $rows = [];
            foreach ($data['outbound'] as $id => $action) {
                if (! ctype_digit((string) $id) || ! $statuses->has((int) $id)) {
                    throw ValidationException::withMessages(['outbound' => 'Lista statusów OMS zmieniła się. Odśwież stronę.']);
                }
                if ($action === null || $action === '') {
                    continue;
                }
                if (! is_string($action) || ! VonHalskyOrderAction::tryFrom($action)) {
                    throw ValidationException::withMessages(['outbound' => 'Nieprawidłowa operacja Von Halsky.']);
                }
                $rows[] = ['von_halsky_account_id' => $account->id, 'environment' => $account->environment->value,
                    'organization_id' => $account->organization_id, 'order_status_setting_id' => (int) $id, 'action' => $action];
            }
            $this->query($account)->delete();
            foreach ($rows as $row) {
                VonHalskyStatusMapping::query()->create($row);
            }
            $account->forceFill(['status_sync_enabled' => (bool) $data['status_sync_enabled'],
                'status_sync_revision' => (int) $account->status_sync_revision + 1, 'lock_version' => $account->lock_version + 1])->save();
        }, 3);
    }

    public function target(VonHalskyAccount $account, string $status): ?VonHalskyOrderAction
    {
        $id = OrderStatusSetting::query()->where('status', $status)->value('id');

        return $id ? $this->query($account)->where('order_status_setting_id', $id)->first()?->action : null;
    }

    public function query(VonHalskyAccount $account): Builder
    {
        return VonHalskyStatusMapping::query()->where('von_halsky_account_id', $account->id)
            ->where('environment', $account->environment->value)->where('organization_id', $account->organization_id);
    }
}
