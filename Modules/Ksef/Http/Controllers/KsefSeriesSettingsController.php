<?php

namespace Modules\Ksef\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Modules\Ksef\Http\Requests\UpdateKsefSeriesSettingsRequest;
use Modules\Ksef\Services\KsefSettingsService;

class KsefSeriesSettingsController extends Controller
{
    public function update(
        UpdateKsefSeriesSettingsRequest $request,
        KsefSettingsService $settingsService,
    ): RedirectResponse {
        $settingsService->updateSeries($request->validated('series_ids'));

        return redirect()->route('integrations.ksef.edit', ['tab' => 'series']);
    }
}
