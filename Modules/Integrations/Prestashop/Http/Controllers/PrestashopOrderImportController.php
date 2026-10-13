<?php

namespace Modules\Integrations\Prestashop\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Modules\Integrations\Prestashop\Exceptions\PrestashopApiException;
use Modules\Integrations\Prestashop\Exceptions\PrestashopImportException;
use Modules\Integrations\Prestashop\Http\Requests\ImportPrestashopOrderRequest;
use Modules\Integrations\Prestashop\Services\PrestashopOrderImportService;

class PrestashopOrderImportController extends Controller
{
    public function __invoke(
        ImportPrestashopOrderRequest $request,
        PrestashopOrderImportService $importer,
    ): RedirectResponse {
        $identifier = $request->validated('order_identifier');

        try {
            $order = $importer->import($identifier);
        } catch (PrestashopApiException|PrestashopImportException $exception) {
            return redirect()
                ->route('integrations.prestashop.edit', ['order_identifier' => $identifier])
                ->with('error', $exception->getMessage());
        }

        return redirect()
            ->route('orders.show', $order)
            ->with('success', 'Zamówienie PrestaShop zostało zaimportowane do OMS.');
    }
}
