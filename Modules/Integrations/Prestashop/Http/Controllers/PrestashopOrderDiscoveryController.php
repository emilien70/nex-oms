<?php

namespace Modules\Integrations\Prestashop\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Modules\Integrations\Prestashop\Jobs\DiscoverPrestashopOrdersJob;
use Modules\Integrations\Prestashop\Services\PrestashopAccountService;

class PrestashopOrderDiscoveryController extends Controller
{
    public function __invoke(PrestashopAccountService $accounts): RedirectResponse
    {
        $account = $accounts->get();

        if (! $account->is_active || ! $account->order_import_enabled) {
            return redirect()
                ->route('integrations.prestashop.edit', ['tab' => 'orders'])
                ->with('error', 'Włącz automatyczny import zamówień przed ręcznym sprawdzeniem.');
        }

        DiscoverPrestashopOrdersJob::dispatch($account->getKey());

        return redirect()
            ->route('integrations.prestashop.edit', ['tab' => 'orders'])
            ->with('success', 'Sprawdzanie nowych zamówień PrestaShop zostało zlecone.');
    }
}
