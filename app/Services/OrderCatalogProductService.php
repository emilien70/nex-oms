<?php

namespace App\Services;

use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Invoices\Services\InvoiceDecimalCalculator;
use Modules\Products\Models\Product;

class OrderCatalogProductService
{
    public function __construct(
        private readonly OrderTotalService $orderTotals,
        private readonly OrderCurrencyService $orderCurrencies,
        private readonly InvoiceDecimalCalculator $decimal,
    ) {}

    /**
     * @param  array<int, array{product_id: int, quantity: int, unit_price_gross: string}>  $requestedItems
     * @return Collection<int, OrderItem>
     */
    public function add(Order $order, array $requestedItems, string $requestedCurrency): Collection
    {
        return DB::transaction(function () use ($order, $requestedItems, $requestedCurrency): Collection {
            $managedOrder = Order::query()->lockForUpdate()->findOrFail($order->getKey());
            $previousOrderCurrency = $managedOrder->currency;
            $currency = $this->orderCurrencies->currencyForNewItem($managedOrder, $requestedCurrency);
            $orderCurrencyAdopted = $previousOrderCurrency !== $currency;
            $productIds = collect($requestedItems)
                ->pluck('product_id')
                ->map(static fn (mixed $id): int => (int) $id)
                ->values();
            $products = Product::query()
                ->whereIn('id', $productIds)
                ->where('is_active', true)
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            if ($products->count() !== $productIds->count()) {
                throw ValidationException::withMessages([
                    'items' => 'Co najmniej jeden z wybranych produktów nie istnieje albo został ukryty.',
                ]);
            }

            $created = collect();

            foreach ($requestedItems as $requestedItem) {
                /** @var Product $product */
                $product = $products->get((int) $requestedItem['product_id']);
                $quantity = (int) $requestedItem['quantity'];
                $unitPriceGross = $this->decimal->normalize((string) $requestedItem['unit_price_gross'], 2);
                $item = $managedOrder->items()->create([
                    'product_id' => $product->getKey(),
                    'product_name' => $product->name,
                    'ean' => $product->ean,
                    'quantity' => $quantity,
                    'unit_price_gross' => $unitPriceGross,
                    'total_price_gross' => $this->orderTotals->lineTotal($unitPriceGross, $quantity),
                    'currency' => $currency,
                    'vat_rate' => $product->vat_rate,
                    'weight' => $product->package_weight_kg,
                ]);

                $managedOrder->events()->create([
                    'event_type' => 'product_added',
                    'title' => 'Produkt dodany',
                    'description' => 'Dodano produkt z katalogu do zamówienia',
                    'payload' => [
                        'product_id' => $product->getKey(),
                        'product_name' => $item->product_name,
                        'quantity' => $item->quantity,
                        'catalog_unit_price_gross' => $product->price_gross,
                        'catalog_currency' => $product->currency,
                        'unit_price_gross' => $item->unit_price_gross,
                        'price_overridden' => $product->currency !== $currency
                            || $this->decimal->compare(
                                (string) $product->price_gross,
                                (string) $item->unit_price_gross,
                            ) !== 0,
                        'currency' => $currency,
                        'order_currency_adopted' => $orderCurrencyAdopted,
                        'previous_order_currency' => $previousOrderCurrency,
                    ],
                ]);

                $created->push($item);
            }

            $this->orderTotals->recalculate($managedOrder);

            return $created;
        });
    }
}
