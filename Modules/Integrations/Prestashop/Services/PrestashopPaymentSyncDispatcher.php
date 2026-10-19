<?php

namespace Modules\Integrations\Prestashop\Services;

use Modules\Integrations\Prestashop\Jobs\SyncPrestashopPaymentsJob;
use Modules\Integrations\Prestashop\Models\PrestashopAccount;

class PrestashopPaymentSyncDispatcher
{
    public function dispatchDue(): bool
    {
        $account = PrestashopAccount::query()
            ->where('account_key', PrestashopAccount::DEFAULT_ACCOUNT_KEY)
            ->first();

        if (
            ! $account?->is_active
            || ! $account->payment_sync_enabled
            || ! $account->hasCompleteCredentials()
        ) {
            return false;
        }

        if ($account->last_payment_sync_at?->gt(now()->subMinute())) {
            return false;
        }

        SyncPrestashopPaymentsJob::dispatch($account->getKey());

        return true;
    }
}
