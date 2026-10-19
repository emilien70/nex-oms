<?php

namespace Modules\Integrations\Prestashop\Services;

use Modules\Integrations\Prestashop\Jobs\DiscoverPrestashopOrdersJob;
use Modules\Integrations\Prestashop\Models\PrestashopAccount;

class PrestashopOrderImportDispatcher
{
    public function dispatchDue(): bool
    {
        $account = PrestashopAccount::query()
            ->where('account_key', PrestashopAccount::DEFAULT_ACCOUNT_KEY)
            ->first();

        if (
            ! $account?->is_active
            || ! $account->order_import_enabled
            || ! $account->hasCompleteCredentials()
        ) {
            return false;
        }

        DiscoverPrestashopOrdersJob::dispatch($account->getKey());

        return true;
    }
}
