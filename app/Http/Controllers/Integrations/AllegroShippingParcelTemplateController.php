<?php

namespace App\Http\Controllers\Integrations;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Modules\Shipments\Models\CourierAccount;
use Modules\Shipments\Services\InPostCourierParcelTemplateService;

class AllegroShippingParcelTemplateController extends Controller
{
    public function store(Request $request, InPostCourierParcelTemplateService $templates): RedirectResponse
    {
        $templates->create($this->account(), $this->validated($request));

        return redirect()->route('integrations.couriers.allegro-shipping.edit');
    }

    public function update(
        Request $request,
        string $templateId,
        InPostCourierParcelTemplateService $templates,
    ): RedirectResponse {
        $templates->update($this->account(), $templateId, $this->validated($request));

        return redirect()->route('integrations.couriers.allegro-shipping.edit');
    }

    public function destroy(
        string $templateId,
        InPostCourierParcelTemplateService $templates,
    ): RedirectResponse {
        $templates->delete($this->account(), $templateId);

        return redirect()->route('integrations.couriers.allegro-shipping.edit');
    }

    private function account(): CourierAccount
    {
        return CourierAccount::query()
            ->where('provider', CourierAccount::PROVIDER_ALLEGRO_SHIPPING)
            ->firstOrFail();
    }

    private function validated(Request $request): array
    {
        $validated = $request->validateWithBag('allegroParcelTemplate', [
            'template_name' => ['required', 'string', 'max:100'],
            'template_weight' => ['required', 'numeric', 'gt:0', 'max:1000'],
            'template_length' => ['required', 'numeric', 'gt:0', 'max:500'],
            'template_width' => ['required', 'numeric', 'gt:0', 'max:500'],
            'template_height' => ['required', 'numeric', 'gt:0', 'max:500'],
            '_template_id' => ['nullable', 'string', 'max:64'],
        ], [
            'template_name.required' => 'Podaj nazwe szablonu.',
            'template_weight.required' => 'Podaj wage przesylki.',
            'template_length.required' => 'Podaj dlugosc przesylki.',
            'template_width.required' => 'Podaj szerokosc przesylki.',
            'template_height.required' => 'Podaj wysokosc przesylki.',
        ]);

        return [
            'name' => $validated['template_name'],
            'weight' => $validated['template_weight'],
            'length' => $validated['template_length'],
            'width' => $validated['template_width'],
            'height' => $validated['template_height'],
        ];
    }
}
