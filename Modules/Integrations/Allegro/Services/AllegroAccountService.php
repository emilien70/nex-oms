<?php

namespace Modules\Integrations\Allegro\Services;

use Illuminate\Support\Facades\DB;
use Modules\Integrations\Allegro\Models\AllegroAccount;
use Modules\Shipments\Models\CourierAccount;

class AllegroAccountService
{
    public function get(): AllegroAccount
    {
        $account = AllegroAccount::query()
            ->where('account_key', AllegroAccount::DEFAULT_ACCOUNT_KEY)
            ->first();

        if ($account !== null) {
            return $account;
        }

        $shipping = CourierAccount::query()
            ->where('provider', CourierAccount::PROVIDER_ALLEGRO_SHIPPING)
            ->first();

        return AllegroAccount::query()->create([
            'account_key' => AllegroAccount::DEFAULT_ACCOUNT_KEY,
            'name' => 'Allegro',
            'environment' => $shipping?->environment ?: 'production',
            'client_id' => $shipping?->resolvedOrganizationId(),
            'client_secret' => $shipping?->resolvedApiSecret(),
            'is_active' => false,
        ]);
    }

    public function update(array $data): AllegroAccount
    {
        return DB::transaction(function () use ($data): AllegroAccount {
            $this->get();

            $account = AllegroAccount::query()
                ->where('account_key', AllegroAccount::DEFAULT_ACCOUNT_KEY)
                ->lockForUpdate()
                ->firstOrFail();

            $clientSecret = trim((string) ($data['client_secret'] ?? ''));
            $connectionChanged = $account->environment !== $data['environment']
                || $account->client_id !== trim((string) $data['client_id'])
                || ($clientSecret !== '' && $clientSecret !== $account->client_secret);

            $account->fill([
                'name' => trim((string) $data['name']),
                'environment' => $data['environment'],
                'client_id' => trim((string) $data['client_id']),
            ]);

            if ($clientSecret !== '') {
                $account->client_secret = $clientSecret;
            }

            if ($connectionChanged) {
                $this->clearAuthorization($account);
                $this->unlinkShippingAccount($account);
            }

            $account->save();

            return $account->refresh();
        });
    }

    public function markConnected(AllegroAccount $account, array $profile): AllegroAccount
    {
        return DB::transaction(function () use ($account, $profile): AllegroAccount {
            $account = AllegroAccount::query()->lockForUpdate()->findOrFail($account->id);
            $previousIdentity = $account->orderImportIdentity();
            $account->forceFill([
                'allegro_user_id' => (string) ($profile['id'] ?? ''),
                'allegro_login' => (string) ($profile['login'] ?? ''),
                'marketplace_id' => data_get($profile, 'baseMarketplace.id'),
                'is_active' => true,
                'last_tested_at' => now(),
                'last_test_status' => AllegroAccount::STATUS_SUCCESS,
                'last_error' => null,
            ]);
            if ($previousIdentity !== $account->orderImportIdentity()) {
                $account->forceFill([
                    'status_sync_enabled' => false, 'status_sync_identity' => null,
                    'status_sync_revision' => (int) $account->status_sync_revision + 1,
                    'shipment_sync_mode' => 'disabled', 'shipment_sync_identity' => null,
                    'shipment_sync_revision' => (int) $account->shipment_sync_revision + 1,
                    'invoice_sync_mode' => 'disabled', 'invoice_sync_identity' => null,
                    'invoice_sync_revision' => (int) $account->invoice_sync_revision + 1,
                    'correction_sync_mode' => 'disabled', 'correction_sync_identity' => null,
                    'correction_sync_revision' => (int) $account->correction_sync_revision + 1,
                    'returns_enabled' => false, 'returns_identity' => null, 'returns_revision' => $account->returns_revision + 1,
                    'returns_scan_offset' => 0, 'returns_scan_until' => null,
                    'refunds_enabled' => false, 'refunds_identity' => null, 'refunds_revision' => $account->refunds_revision + 1,
                    'refunds_scan_offset' => 0, 'refunds_scan_until' => null,
                ]);
            }
            $account->save();

            $shipping = CourierAccount::query()
                ->where('provider', CourierAccount::PROVIDER_ALLEGRO_SHIPPING)
                ->first();

            if ($shipping !== null) {
                $shipping->forceFill([
                    'allegro_account_id' => $account->id,
                    'last_error' => null,
                ])->save();
            }

            return $account->refresh();
        });
    }

    public function markConnectionError(AllegroAccount $account, string $message): void
    {
        $account->forceFill([
            'is_active' => false,
            'last_tested_at' => now(),
            'last_test_status' => AllegroAccount::STATUS_ERROR,
            'last_error' => $message,
        ])->save();
    }

    public function disconnect(AllegroAccount $account): AllegroAccount
    {
        return DB::transaction(function () use ($account): AllegroAccount {
            $account = AllegroAccount::query()->lockForUpdate()->findOrFail($account->id);
            $this->unlinkShippingAccount($account);
            $this->clearAuthorization($account);
            $account->save();

            return $account->refresh();
        });
    }

    private function clearAuthorization(AllegroAccount $account): void
    {
        $account->forceFill([
            'access_token' => null,
            'refresh_token' => null,
            'access_token_expires_at' => null,
            'granted_scopes' => null,
            'allegro_user_id' => null,
            'allegro_login' => null,
            'marketplace_id' => null,
            'is_active' => false,
            'last_tested_at' => null,
            'last_test_status' => null,
            'last_error' => null,
            'status_sync_enabled' => false,
            'status_sync_identity' => null,
            'status_sync_revision' => (int) $account->status_sync_revision + 1,
            'shipment_sync_mode' => 'disabled', 'shipment_sync_identity' => null,
            'shipment_sync_revision' => (int) $account->shipment_sync_revision + 1,
            'invoice_sync_mode' => 'disabled', 'invoice_sync_identity' => null,
            'invoice_sync_revision' => (int) $account->invoice_sync_revision + 1,
            'correction_sync_mode' => 'disabled', 'correction_sync_identity' => null,
            'correction_sync_revision' => (int) $account->correction_sync_revision + 1,
            'returns_enabled' => false, 'returns_identity' => null, 'returns_revision' => $account->returns_revision + 1,
            'returns_scan_offset' => 0, 'returns_scan_until' => null,
            'refunds_enabled' => false, 'refunds_identity' => null, 'refunds_revision' => $account->refunds_revision + 1,
            'refunds_scan_offset' => 0, 'refunds_scan_until' => null,
        ]);
    }

    private function unlinkShippingAccount(AllegroAccount $account): void
    {
        CourierAccount::query()
            ->where('allegro_account_id', $account->id)
            ->update(['allegro_account_id' => null]);
    }
}
