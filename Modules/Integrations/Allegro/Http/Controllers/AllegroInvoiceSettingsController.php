<?php

namespace Modules\Integrations\Allegro\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Modules\Integrations\Allegro\Http\Requests\UpdateAllegroCorrectionSettingsRequest;
use Modules\Integrations\Allegro\Http\Requests\UpdateAllegroInvoiceSettingsRequest;
use Modules\Integrations\Allegro\Services\AllegroInvoiceSettingsService;

class AllegroInvoiceSettingsController extends Controller
{
    public function updateCorrections(UpdateAllegroCorrectionSettingsRequest $request, AllegroInvoiceSettingsService $settings): RedirectResponse
    {
        $settings->updateCorrections($request->validated());

        return redirect()->route('integrations.allegro.edit', ['tab' => 'invoices'])->with('success', 'Ustawienia przekazywania korekt zostały zapisane.');
    }

    public function update(UpdateAllegroInvoiceSettingsRequest $request, AllegroInvoiceSettingsService $settings): RedirectResponse
    {
        $settings->update($request->validated());

        return redirect()->route('integrations.allegro.edit', ['tab' => 'invoices'])->with('success', 'Ustawienia przekazywania faktur zostały zapisane.');
    }
}
