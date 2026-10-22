<?php

namespace Modules\Integrations\Allegro\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Modules\Integrations\Allegro\Http\Requests\UpdateAllegroShipmentSettingsRequest;
use Modules\Integrations\Allegro\Services\AllegroShipmentSettingsService;

class AllegroShipmentSettingsController extends Controller
{
    public function update(UpdateAllegroShipmentSettingsRequest $request, AllegroShipmentSettingsService $settings): RedirectResponse
    {
        $settings->update($request->validated());

        return redirect()->route('integrations.allegro.edit', ['tab' => 'shipments'])->with('success', 'Ustawienia przekazywania numerów przesyłek zostały zapisane.');
    }
}
