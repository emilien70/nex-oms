<?php

namespace Modules\Integrations\Allegro\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Modules\Integrations\Allegro\Http\Requests\UpdateAllegroStatusMappingsRequest;
use Modules\Integrations\Allegro\Services\AllegroStatusMappingService;

class AllegroStatusMappingController extends Controller
{
    public function update(UpdateAllegroStatusMappingsRequest $request, AllegroStatusMappingService $mappings): RedirectResponse
    {
        $mappings->update(
            $request->validated('outbound'), $request->boolean('status_sync_enabled'),
            $request->validated('identity'), (int) $request->validated('configuration_revision'),
        );

        return redirect()->route('integrations.allegro.edit', ['tab' => 'statuses'])
            ->with('success', 'Powiązania statusów Allegro zostały zapisane.');
    }
}
