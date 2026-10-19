<?php

namespace Modules\Integrations\Prestashop\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Modules\Integrations\Prestashop\Http\Requests\UpdatePrestashopStatusMappingsRequest;
use Modules\Integrations\Prestashop\Services\PrestashopStatusMappingService;

class PrestashopStatusMappingController extends Controller
{
    public function update(
        UpdatePrestashopStatusMappingsRequest $request,
        PrestashopStatusMappingService $mappingService,
    ): RedirectResponse {
        $mappingService->update(
            $request->validated('inbound', []),
            $request->validated('outbound', []),
            $request->boolean('notify_customer_on_status_change'),
            $request->boolean('status_sync_enabled'),
            (int) $request->validated('status_sync_interval_minutes'),
        );

        return redirect()
            ->route('integrations.prestashop.edit', ['tab' => 'statuses'])
            ->with('success', 'Mapowanie statusów PrestaShop zostało zapisane.');
    }
}
