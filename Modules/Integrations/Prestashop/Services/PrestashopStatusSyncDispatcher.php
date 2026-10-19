<?php

namespace Modules\Integrations\Prestashop\Services;

use Modules\Integrations\Prestashop\Jobs\SyncPrestashopInboundStatusesJob;
use Modules\Integrations\Prestashop\Models\PrestashopAccount;

class PrestashopStatusSyncDispatcher
{
    public function dispatchDue(): bool
    {
        $account = PrestashopAccount::query()
            ->where('account_key', PrestashopAccount::DEFAULT_ACCOUNT_KEY)
            ->first();

        if (! $account?->is_active || ! $account->status_sync_enabled) {
            return false;
        }

        $interval = in_array($account->status_sync_interval_minutes, [1, 5, 10], true)
            ? $account->status_sync_interval_minutes
            : 1;

        if (
            $account->last_status_sync_at
            && $account->last_status_sync_at->gt(now()->subMinutes($interval))
        ) {
            return false;
        }

        SyncPrestashopInboundStatusesJob::dispatch($account->getKey());

        return true;
    }
}
