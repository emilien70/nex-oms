<?php

namespace Modules\Integrations\Prestashop\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Modules\Integrations\Prestashop\Jobs\SyncPrestashopPaymentsJob;
use Modules\Integrations\Prestashop\Services\PrestashopAccountService;

class PrestashopPaymentSyncController extends Controller
{
    public function __invoke(PrestashopAccountService $accounts): RedirectResponse
    {
        $account = $accounts->get();

        if (! $account->is_active || ! $account->hasCompleteCredentials()) {
            return redirect()
                ->route('integrations.prestashop.edit', ['tab' => 'payments'])
                ->with('error', 'Włącz i poprawnie skonfiguruj integrację PrestaShop.');
        }

        SyncPrestashopPaymentsJob::dispatch($account->getKey(), true);

        return redirect()
            ->route('integrations.prestashop.edit', ['tab' => 'payments'])
            ->with('success', 'Synchronizacja płatności PrestaShop została zlecona.');
    }
}
