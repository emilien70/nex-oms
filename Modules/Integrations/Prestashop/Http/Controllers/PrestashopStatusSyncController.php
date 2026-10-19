<?php

namespace Modules\Integrations\Prestashop\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Modules\Integrations\Prestashop\Jobs\SyncPrestashopInboundStatusesJob;
use Modules\Integrations\Prestashop\Services\PrestashopAccountService;

class PrestashopStatusSyncController extends Controller
{
    public function __invoke(PrestashopAccountService $accountService): RedirectResponse
    {
        $account = $accountService->get();

        if (! $account->is_active || ! $account->hasCompleteCredentials()) {
            return redirect()
                ->route('integrations.prestashop.edit', ['tab' => 'statuses'])
                ->with('error', 'Włącz i poprawnie skonfiguruj integrację przed synchronizacją statusów.');
        }

        SyncPrestashopInboundStatusesJob::dispatch($account->getKey(), true);

        return redirect()
            ->route('integrations.prestashop.edit', ['tab' => 'statuses'])
            ->with('success', 'Synchronizacja statusów PrestaShop została zlecona.');
    }
}
