<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\RespondsToOrderAjax;
use App\Models\Order;
use App\Services\OrderTotalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class OrderMetaController extends Controller
{
    use RespondsToOrderAjax;

    public function updatePaidAmount(Request $request, Order $order): JsonResponse|RedirectResponse
    {
        $validated = $request->validate([
            'paid_amount' => ['required', 'numeric', 'min:0', 'max:'.max((float) $order->total_gross, 0)],
        ]);

        $order->update([
            'paid_amount' => $validated['paid_amount'],
        ]);

        $order->events()->create([
            'event_type' => 'paid_amount_updated',
            'title' => html_entity_decode('Wp&#322;ata zaktualizowana', ENT_QUOTES, 'UTF-8'),
            'description' => html_entity_decode('Zaktualizowano kwot&#281; wp&#322;aty', ENT_QUOTES, 'UTF-8'),
            'payload' => [
                'paid_amount' => $order->paid_amount,
            ],
        ]);

        return $this->orderMutationResponse($request, ['order-info', 'history'], back());
    }

    public function recalculateTotal(Request $request, Order $order, OrderTotalService $orderTotalService): JsonResponse|RedirectResponse
    {
        $totalGross = $orderTotalService->recalculate($order);

        $order->events()->create([
            'event_type' => 'order_total_recalculated',
            'title' => html_entity_decode('Warto&#347;&#263; zam&oacute;wienia przeliczona', ENT_QUOTES, 'UTF-8'),
            'description' => html_entity_decode('Przeliczono warto&#347;&#263; zam&oacute;wienia na podstawie produkt&oacute;w i kosztu wysy&#322;ki', ENT_QUOTES, 'UTF-8'),
            'payload' => [
                'total_gross' => $order->total_gross,
            ],
        ]);

        return $this->orderMutationResponse(
            $request,
            ['order-info', 'history'],
            back()->with('success', html_entity_decode('Warto&#347;&#263; zam&oacute;wienia zosta&#322;a przeliczona.', ENT_QUOTES, 'UTF-8'))
        );
    }

    public function updatePickupPoint(Request $request, Order $order): JsonResponse|RedirectResponse
    {
        $validated = $request->validate([
            'pickup_point_name' => ['nullable', 'string', 'max:255'],
            'pickup_point_id' => ['nullable', 'string', 'max:255'],
            'pickup_point_address' => ['nullable', 'string', 'max:255'],
            'pickup_point_postal_code' => ['nullable', 'string', 'max:255'],
            'pickup_point_city' => ['nullable', 'string', 'max:255'],
        ]);

        $order->update($validated);

        $order->events()->create([
            'event_type' => 'pickup_point_updated',
            'title' => html_entity_decode('Odbi&oacute;r w punkcie zaktualizowany', ENT_QUOTES, 'UTF-8'),
            'description' => html_entity_decode('Zaktualizowano dane punktu odbioru', ENT_QUOTES, 'UTF-8'),
            'payload' => $validated,
        ]);

        return $this->orderMutationResponse($request, ['pickup', 'history'], back());
    }

    public function updateStarColor(Request $request, Order $order): JsonResponse|RedirectResponse
    {
        $validated = $request->validate([
            'star_color' => ['nullable', Rule::in(['orange', 'navy', 'green', 'blue', 'red'])],
        ]);

        $order->update([
            'star_color' => $validated['star_color'] ?? null,
        ]);

        return $this->orderMutationResponse($request, [], back());
    }
}
