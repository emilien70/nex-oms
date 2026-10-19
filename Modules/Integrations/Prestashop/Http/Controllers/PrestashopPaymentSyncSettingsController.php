<?php

namespace Modules\Integrations\Prestashop\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Modules\Integrations\Prestashop\Http\Requests\UpdatePrestashopPaymentSyncSettingsRequest;
use Modules\Integrations\Prestashop\Services\PrestashopPaymentSyncSettingsService;

class PrestashopPaymentSyncSettingsController extends Controller
{
    public function update(
        UpdatePrestashopPaymentSyncSettingsRequest $request,
        PrestashopPaymentSyncSettingsService $settings,
    ): RedirectResponse {
        $settings->update($request->boolean('payment_sync_enabled'));

        return redirect()
            ->route('integrations.prestashop.edit', ['tab' => 'payments'])
            ->with('success', 'Ustawienia synchronizacji płatności zostały zapisane.');
    }
}
