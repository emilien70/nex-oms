<?php

namespace Modules\Integrations\Prestashop\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Modules\Integrations\Prestashop\Http\Requests\UpdatePrestashopOrderImportSettingsRequest;
use Modules\Integrations\Prestashop\Services\PrestashopOrderImportSettingsService;

class PrestashopOrderImportSettingsController extends Controller
{
    public function update(
        UpdatePrestashopOrderImportSettingsRequest $request,
        PrestashopOrderImportSettingsService $settings,
    ): RedirectResponse {
        $settings->update($request->validated());

        return redirect()
            ->route('integrations.prestashop.edit', ['tab' => 'orders'])
            ->with('success', 'Ustawienia importu zamówień zostały zapisane.');
    }
}
