<?php

namespace Modules\Ksef\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Modules\Ksef\Enums\KsefPaymentType;
use Modules\Ksef\Http\Requests\UpdateKsefPaymentTypesRequest;
use Modules\Ksef\Services\KsefPaymentMethodMappingService;

class KsefPaymentTypeSettingsController extends Controller
{
    public function update(
        UpdateKsefPaymentTypesRequest $request,
        KsefPaymentMethodMappingService $mappings,
    ): RedirectResponse {
        $data = $request->validated();
        $mappings->update(
            KsefPaymentType::from($data['default_payment_type']),
            $data['mappings'],
        );

        return redirect()
            ->route('integrations.ksef.edit', ['tab' => 'payment-types'])
            ->with('success', 'Zapisano mapowanie typów płatności.');
    }
}
