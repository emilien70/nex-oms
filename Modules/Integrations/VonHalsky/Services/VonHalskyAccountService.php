<?php

namespace Modules\Integrations\VonHalsky\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Modules\Integrations\VonHalsky\Enums\VonHalskyEnvironment;
use Modules\Integrations\VonHalsky\Exceptions\VonHalskyApiException;
use Modules\Integrations\VonHalsky\Models\VonHalskyAccount;

class VonHalskyAccountService
{
    public function get(): VonHalskyAccount
    {
        return VonHalskyAccount::query()->where('account_key', VonHalskyAccount::ACCOUNT_KEY)->first()
            ?? new VonHalskyAccount;
    }

    public function update(array $data): VonHalskyAccount
    {
        return DB::transaction(function () use ($data): VonHalskyAccount {
            $account = VonHalskyAccount::query()->firstOrCreate(['account_key' => VonHalskyAccount::ACCOUNT_KEY]);
            $account = VonHalskyAccount::query()->lockForUpdate()->findOrFail($account->id);
            $this->checkVersion($account, (int) $data['expected_lock_version']);
            $secret = filled($data['client_secret'] ?? null) ? $data['client_secret'] : $account->client_secret;

            if (! filled($secret)) {
                throw ValidationException::withMessages(['client_secret' => 'Podaj Client Secret dla nowego połączenia.']);
            }

            $changed = $account->environment->value !== $data['environment']
                || $account->organization_id !== $data['organization_id']
                || $account->client_id !== $data['client_id']
                || $account->client_secret !== $secret;

            if ($changed) {
                $this->clearConnection($account);
            }

            $account->forceFill([
                'name' => $data['name'],
                'environment' => VonHalskyEnvironment::from($data['environment']),
                'organization_id' => $data['organization_id'],
                'client_id' => $data['client_id'],
                'client_secret' => $secret,
                'lock_version' => $account->lock_version + 1,
            ])->save();

            return $account;
        });
    }

    public function beginTest(int $expectedVersion): VonHalskyAccount
    {
        return DB::transaction(function () use ($expectedVersion): VonHalskyAccount {
            $account = VonHalskyAccount::query()->where('account_key', VonHalskyAccount::ACCOUNT_KEY)->lockForUpdate()->firstOrFail();
            $this->checkVersion($account, $expectedVersion);
            $this->clearConnection($account);
            $account->lock_version++;
            $account->save();

            return $account;
        });
    }

    public function updateImportSettings(array $data): void
    {
        DB::transaction(function () use ($data): void {
            $account = VonHalskyAccount::query()->where('account_key', VonHalskyAccount::ACCOUNT_KEY)->lockForUpdate()->firstOrFail();
            $this->checkVersion($account, (int) $data['expected_lock_version']);
            $account->forceFill([
                'initial_status_setting_id' => $data['initial_status_setting_id'] ?? null,
                'default_vat_rate' => $data['default_vat_rate'] ?? null,
                'use_linked_products' => (bool) ($data['use_linked_products'] ?? false),
                'use_catalog_product_names' => (bool) ($data['use_catalog_product_names'] ?? false),
                'use_catalog_vat' => (bool) ($data['use_catalog_vat'] ?? false),
                'lock_version' => $account->lock_version + 1,
            ])->save();
        });
    }

    public function disconnect(int $expectedVersion): void
    {
        DB::transaction(function () use ($expectedVersion): void {
            $account = VonHalskyAccount::query()->where('account_key', VonHalskyAccount::ACCOUNT_KEY)->lockForUpdate()->firstOrFail();
            $this->checkVersion($account, $expectedVersion);
            $this->clearConnection($account);
            $account->lock_version++;
            $account->save();
        });
    }

    public function updateAutomaticImport(array $data): void
    {
        if (! app(VonHalskyOrderImportState::class)->ready()) {
            throw ValidationException::withMessages(['order_import_enabled' => 'Najpierw wykonaj migrację pobierania Von Halsky.']);
        }
        DB::transaction(function () use ($data): void {
            $account = VonHalskyAccount::query()->where('account_key', VonHalskyAccount::ACCOUNT_KEY)->lockForUpdate()->firstOrFail();
            $this->checkVersion($account, (int) $data['expected_lock_version']);
            $enabled = (bool) $data['order_import_enabled'];
            $start = filled($data['order_import_started_at'] ?? null)
                ? CarbonImmutable::createFromFormat('!Y-m-d\TH:i', $data['order_import_started_at'], config('app.timezone')) : null;
            if ($enabled && (! $start || ! $account->is_active || $account->last_test_status !== 'success')) {
                throw ValidationException::withMessages(['order_import_enabled' => 'Potwierdź połączenie i wybierz datę rozpoczęcia pobierania.']);
            }
            $changedStart = $start?->timestamp !== $account->order_import_started_at?->timestamp;
            $account->forceFill(['order_import_enabled' => $enabled,
                'order_import_interval_minutes' => (int) $data['order_import_interval_minutes'],
                'order_import_started_at' => $start, 'order_import_revision' => (int) $account->order_import_revision + 1,
                'lock_version' => $account->lock_version + 1, 'last_order_scan_requested_at' => null,
                'last_order_import_error' => null]);
            if ($changedStart) {
                $account->order_import_cursor_at = null;
                $account->last_order_reconciliation_at = null;
            }
            $account->save();
        });
    }

    public function recordTest(VonHalskyAccount $context, ?string $error = null): void
    {
        DB::transaction(function () use ($context, $error): void {
            $account = $this->current($context, lock: true);
            $account->forceFill([
                'is_active' => $error === null,
                'last_tested_at' => now(),
                'last_test_status' => $error === null ? 'success' : 'error',
                'last_test_message' => $error ?? 'Połączenie działa. Potwierdzono dostęp do odczytu zamówień organizacji.',
            ]);

            if ($error !== null) {
                $account->access_token = null;
                $account->token_expires_at = null;
            }

            $account->save();
        });
    }

    public function current(VonHalskyAccount $context, bool $lock = false): VonHalskyAccount
    {
        $query = VonHalskyAccount::query()->whereKey($context->id);
        $account = ($lock ? $query->lockForUpdate() : $query)->first();

        if (! $account || $account->connection_revision !== $context->connection_revision) {
            throw new VonHalskyApiException('Konfiguracja połączenia została zmieniona. Odśwież stronę i ponów operację.');
        }

        return $account;
    }

    private function checkVersion(VonHalskyAccount $account, int $expected): void
    {
        if ($account->lock_version !== $expected) {
            throw ValidationException::withMessages(['expected_lock_version' => 'Ustawienia zostały zmienione. Odśwież stronę przed zapisem.']);
        }
    }

    private function clearConnection(VonHalskyAccount $account): void
    {
        if (Schema::hasColumn('von_halsky_accounts', 'status_sync_enabled')) {
            $account->forceFill(['status_sync_enabled' => false,
                'status_sync_revision' => (int) $account->status_sync_revision + 1, 'access_token_scopes' => null]);
        }
        if (Schema::hasColumn('von_halsky_accounts', 'order_import_revision')) {
            $account->forceFill(['order_import_enabled' => false,
                'order_import_revision' => (int) $account->order_import_revision + 1,
                'order_import_cursor_at' => null, 'last_order_reconciliation_at' => null,
                'last_order_scan_requested_at' => null]);
        }
        $account->forceFill([
            'is_active' => false,
            'access_token' => null,
            'token_expires_at' => null,
            'last_tested_at' => null,
            'last_test_status' => null,
            'last_test_message' => null,
            'connection_revision' => $account->connection_revision + 1,
        ]);
    }
}
