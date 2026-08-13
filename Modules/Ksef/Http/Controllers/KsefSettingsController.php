<?php

namespace Modules\Ksef\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Modules\Ksef\Enums\KsefAuthenticationMethod;
use Modules\Ksef\Enums\KsefEnvironment;
use Modules\Ksef\Http\Requests\UpdateKsefSettingsRequest;
use Modules\Ksef\Services\KsefSettingsService;

class KsefSettingsController extends Controller
{
    public function edit(Request $request, KsefSettingsService $settingsService): View
    {
        return view('integrations.ksef.edit', [
            'settings' => $settingsService->get(),
            'environmentOptions' => KsefEnvironment::cases(),
            'authenticationMethods' => KsefAuthenticationMethod::cases(),
            'tokenConfiguredByEnvironment' => $settingsService->tokenConfiguredByEnvironment(),
            'series' => $settingsService->seriesForConfiguration(),
            'activeTab' => $request->query('tab') === 'series' ? 'series' : 'connection',
        ]);
    }

    public function update(
        UpdateKsefSettingsRequest $request,
        KsefSettingsService $settingsService,
    ): RedirectResponse {
        $settingsService->update($request->validated());

        return redirect()->route('integrations.ksef.edit');
    }
}
