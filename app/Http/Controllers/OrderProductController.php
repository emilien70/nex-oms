<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\RespondsToOrderAjax;
use App\Models\Order;
use App\Models\OrderItem;
use App\Services\OrderTotalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class OrderProductController extends Controller
{
    use RespondsToOrderAjax;

    public function store(Request $request, Order $order, OrderTotalService $orderTotalService): JsonResponse|RedirectResponse
    {
        $validated = $this->validateProduct($request);

        DB::transaction(function () use ($order, $validated, $orderTotalService): void {
            $quantity = (int) $validated['quantity'];
            $unitPriceGross = (float) $validated['unit_price_gross'];

            $item = $order->items()->create([
                'product_name' => $validated['product_name'],
                'quantity' => $quantity,
                'unit_price_gross' => $unitPriceGross,
                'total_price_gross' => round($quantity * $unitPriceGross, 2),
                'currency' => $validated['currency'] ?? $order->currency ?? 'PLN',
                'vat_rate' => $validated['vat_rate'] ?? null,
                'weight' => $validated['weight'] ?? null,
            ]);

            $orderTotalService->recalculate($order);

            $order->events()->create([
                'event_type' => 'product_added',
                'title' => 'Produkt dodany',
                'description' => html_entity_decode('Dodano produkt do zam&oacute;wienia', ENT_QUOTES, 'UTF-8'),
                'payload' => [
                    'product_name' => $item->product_name,
                    'quantity' => $item->quantity,
                    'unit_price_gross' => $item->unit_price_gross,
                ],
            ]);
        });

        return $this->orderMutationResponse($request, ['products', 'order-info', 'history'], back());
    }

    public function update(Request $request, OrderItem $orderItem, OrderTotalService $orderTotalService): JsonResponse|RedirectResponse
    {
        $validated = $this->validateProduct($request);

        DB::transaction(function () use ($orderItem, $validated, $orderTotalService): void {
            $quantity = (int) $validated['quantity'];
            $unitPriceGross = (float) $validated['unit_price_gross'];

            $orderItem->update([
                'product_name' => $validated['product_name'],
                'quantity' => $quantity,
                'unit_price_gross' => $unitPriceGross,
                'total_price_gross' => round($quantity * $unitPriceGross, 2),
                'currency' => $validated['currency'] ?? $orderItem->currency,
                'vat_rate' => $validated['vat_rate'] ?? null,
                'weight' => $validated['weight'] ?? null,
            ]);

            $orderTotalService->recalculate($orderItem->order);

            $orderItem->order->events()->create([
                'event_type' => 'product_updated',
                'title' => 'Produkt zaktualizowany',
                'description' => html_entity_decode('Zaktualizowano produkt w zam&oacute;wieniu', ENT_QUOTES, 'UTF-8'),
                'payload' => [
                    'product_name' => $orderItem->product_name,
                    'quantity' => $orderItem->quantity,
                    'unit_price_gross' => $orderItem->unit_price_gross,
                ],
            ]);
        });

        return $this->orderMutationResponse($request, ['products', 'order-info', 'history'], back());
    }

    public function destroy(Request $request, OrderItem $orderItem, OrderTotalService $orderTotalService): JsonResponse|RedirectResponse
    {
        DB::transaction(function () use ($orderItem, $orderTotalService): void {
            $order = $orderItem->order;
            $productName = $orderItem->product_name;
            $quantity = $orderItem->quantity;
            $unitPriceGross = $orderItem->unit_price_gross;

            $orderItem->delete();
            $orderTotalService->recalculate($order);

            $order->events()->create([
                'event_type' => 'product_deleted',
                'title' => html_entity_decode('Produkt usuni&#281;ty', ENT_QUOTES, 'UTF-8'),
                'description' => html_entity_decode('Usuni&#281;to produkt z zam&oacute;wienia', ENT_QUOTES, 'UTF-8'),
                'payload' => [
                    'product_name' => $productName,
                    'quantity' => $quantity,
                    'unit_price_gross' => $unitPriceGross,
                ],
            ]);
        });

        return $this->orderMutationResponse(
            $request,
            ['products', 'order-info', 'history'],
            back()->with('success', 'Produkt zostal usuniety.')
        );
    }

    private function validateProduct(Request $request): array
    {
        return $request->validate([
            'product_name' => ['required', 'string', 'max:255'],
            'quantity' => ['required', 'integer', 'min:1'],
            'unit_price_gross' => ['required', 'numeric', 'min:0'],
            'currency' => ['nullable', 'string', 'max:10'],
            'vat_rate' => ['nullable', 'numeric', 'min:0'],
            'weight' => ['nullable', 'numeric', 'min:0'],
        ]);
    }
}
