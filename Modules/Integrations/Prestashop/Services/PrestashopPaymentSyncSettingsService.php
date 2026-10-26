<?php

namespace Modules\Integrations\Prestashop\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Integrations\Prestashop\Models\PrestashopAccount;

class PrestashopPaymentSyncSettingsService
{
    public function update(bool $enabled, ?bool $warnMissingTransactionId = null): PrestashopAccount
    {
        return DB::transaction(function () use ($enabled, $warnMissingTransactionId): PrestashopAccount {
            $account = PrestashopAccount::query()
                ->where('account_key', PrestashopAccount::DEFAULT_ACCOUNT_KEY)
                ->lockForUpdate()
                ->firstOrFail();

            if ($enabled && (! $account->is_active || ! $account->hasCompleteCredentials())) {
                throw ValidationException::withMessages([
                    'payment_sync_enabled' => 'Włącz i poprawnie skonfiguruj integrację przed uruchomieniem synchronizacji płatności.',
                ]);
            }

            if ($enabled && ! $account->payment_sync_enabled) {
                $account->forceFill([
                    'last_payment_sync_at' => null,
                    'last_payment_sync_error_at' => null,
                    'last_payment_sync_error' => null,
                ]);
            }

            $account->payment_sync_enabled = $enabled;
            if ($warnMissingTransactionId !== null) {
                $account->warn_missing_transaction_id = $warnMissingTransactionId;
            }
            $account->save();

            return $account->refresh();
        });
    }
}
