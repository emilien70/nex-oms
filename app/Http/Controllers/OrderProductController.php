<?php

namespace App\Http\Controllers;

use App\Exceptions\OrderCurrencyException;
use App\Http\Controllers\Concerns\NormalizesDecimalInput;
use App\Http\Controllers\Concerns\RespondsToOrderAjax;
use App\Models\Order;
use App\Models\OrderItem;
use App\Rules\ValidCurrencyCode;
use App\Services\OrderCurrencyService;
use App\Services\OrderTotalService;
use App\Support\CurrencyCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OrderProductController extends Controller
{
    use NormalizesDecimalInput;
    use RespondsToOrderAjax;

    public function __construct(private readonly CurrencyCatalog $currencies) {}

    public function store(
        Request $request,
        Order $order,
        OrderTotalService $orderTotalService,
        OrderCurrencyService $orderCurrencyService,
    ): JsonResponse|RedirectResponse {
        $this->normalizeDecimalFields($request, ['unit_price_gross', 'vat_rate', 'weight']);

        if (! $request->exists('currency')) {
            $orderCurrency = $this->currencies->normalize($order->currency);
            $request->merge([
                'currency' => $orderCurrency !== null && $this->currencies->exists($orderCurrency)
                    ? $orderCurrency
                    : CurrencyCatalog::SYSTEM_CURRENCY,
            ]);
        } else {
            $request->merge(['currency' => $this->currencies->normalize($request->input('currency'))]);
        }

        $validated = $this->validateProduct($request);

        try {
            DB::transaction(function () use ($order, $validated, $orderTotalService, $orderCurrencyService): void {
                $managedOrder = Order::query()->lockForUpdate()->findOrFail($order->getKey());
                $quantity = (int) $validated['quantity'];
                $unitPriceGross = (string) $validated['unit_price_gross'];
                $previousOrderCurrency = $this->currencies->normalize($managedOrder->currency);
                $currency = $orderCurrencyService->currencyForNewItem($managedOrder, $validated['currency']);
                $orderCurrencyAdopted = $previousOrderCurrency !== $currency;

                $item = $managedOrder->items()->create([
                    'product_name' => $validated['product_name'],
                    'quantity' => $quantity,
                    'unit_price_gross' => $unitPriceGross,
                    'total_price_gross' => $orderTotalService->lineTotal($unitPriceGross, $quantity),
                    'currency' => $currency,
                    'vat_rate' => $validated['vat_rate'] ?? null,
                    'weight' => $validated['weight'] ?? null,
                ]);

                $orderTotalService->recalculate($managedOrder);

                $managedOrder->events()->create([
                    'event_type' => 'product_added',
                    'title' => 'Produkt dodany',
                    'description' => html_entity_decode('Dodano produkt do zam&oacute;wienia', ENT_QUOTES, 'UTF-8'),
                    'payload' => [
                        'product_name' => $item->product_name,
                        'quantity' => $item->quantity,
                        'unit_price_gross' => $item->unit_price_gross,
                        'currency' => $currency,
                        'order_currency_adopted' => $orderCurrencyAdopted,
                        'previous_order_currency' => $previousOrderCurrency,
                    ],
                ]);
            });
        } catch (OrderCurrencyException $exception) {
            throw ValidationException::withMessages(['currency' => $exception->getMessage()]);
        }

        return $this->orderMutationResponse($request, ['products', 'order-info', 'history'], back());
    }

    public function update(
        Request $request,
        OrderItem $orderItem,
        OrderTotalService $orderTotalService,
        OrderCurrencyService $orderCurrencyService,
    ): JsonResponse|RedirectResponse {
        $this->normalizeDecimalFields($request, ['unit_price_gross', 'vat_rate', 'weight']);
        $request->merge([
            'currency' => $this->currencies->normalize(
                $request->exists('currency') ? $request->input('currency') : ($orderItem->currency ?? $orderItem->order->currency),
            ),
        ]);
        $validated = $this->validateProduct($request, $orderItem->currency ?? $orderItem->order->currency);

        try {
            DB::transaction(function () use ($orderItem, $validated, $orderTotalService, $orderCurrencyService): void {
                $managedItem = OrderItem::query()->lockForUpdate()->findOrFail($orderItem->getKey());
                $managedOrder = Order::query()->lockForUpdate()->findOrFail($managedItem->order_id);
                $quantity = (int) $validated['quantity'];
                $unitPriceGross = (string) $validated['unit_price_gross'];
                $currency = $orderCurrencyService->currencyForExistingItem(
                    $managedOrder,
                    $managedItem,
                    $validated['currency'],
                );

                $managedItem->update([
                    'product_name' => $validated['product_name'],
                    'quantity' => $quantity,
                    'unit_price_gross' => $unitPriceGross,
                    'total_price_gross' => $orderTotalService->lineTotal($unitPriceGross, $quantity),
                    'currency' => $currency,
                    'vat_rate' => $validated['vat_rate'] ?? null,
                    'weight' => $validated['weight'] ?? null,
                ]);

                $orderTotalService->recalculate($managedOrder);

                $managedOrder->events()->create([
                    'event_type' => 'product_updated',
                    'title' => 'Produkt zaktualizowany',
                    'description' => html_entity_decode('Zaktualizowano produkt w zam&oacute;wieniu', ENT_QUOTES, 'UTF-8'),
                    'payload' => [
                        'product_name' => $managedItem->product_name,
                        'quantity' => $managedItem->quantity,
                        'unit_price_gross' => $managedItem->unit_price_gross,
                    ],
                ]);
            });
        } catch (OrderCurrencyException $exception) {
            throw ValidationException::withMessages(['currency' => $exception->getMessage()]);
        }

        return $this->orderMutationResponse($request, ['products', 'order-info', 'history'], back());
    }

    public function destroy(Request $request, OrderItem $orderItem, OrderTotalService $orderTotalService): JsonResponse|RedirectResponse
    {
        try {
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
        } catch (OrderCurrencyException $exception) {
            throw ValidationException::withMessages(['currency' => $exception->getMessage()]);
        }

        return $this->orderMutationResponse(
            $request,
            ['products', 'order-info', 'history'],
            back()->with('success', 'Produkt zostal usuniety.')
        );
    }

    private function validateProduct(Request $request, mixed $unchangedHistoricalCurrency = null): array
    {
        return $request->validate([
            'product_name' => ['required', 'string', 'max:255'],
            'quantity' => ['required', 'integer', 'min:1'],
            'unit_price_gross' => ['required', 'numeric', 'min:0'],
            'currency' => [
                'required',
                'string',
                'size:3',
                'regex:/^[A-Z]{3}$/',
                new ValidCurrencyCode($this->currencies, $unchangedHistoricalCurrency),
            ],
            'vat_rate' => ['nullable', 'numeric', 'min:0'],
            'weight' => ['nullable', 'numeric', 'min:0'],
        ], [
            'currency.required' => CurrencyCatalog::INVALID_CURRENCY_MESSAGE,
            'currency.string' => CurrencyCatalog::INVALID_CURRENCY_MESSAGE,
            'currency.size' => CurrencyCatalog::INVALID_CURRENCY_MESSAGE,
            'currency.regex' => CurrencyCatalog::INVALID_CURRENCY_MESSAGE,
        ]);
    }
}
