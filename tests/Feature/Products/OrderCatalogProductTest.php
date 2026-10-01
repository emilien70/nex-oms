<?php

namespace Tests\Feature\Products;

use App\Models\Currency;
use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Products\Models\Product;
use Tests\TestCase;

class OrderCatalogProductTest extends TestCase
{
    use RefreshDatabase;

    public function test_order_page_exposes_catalog_and_manual_product_choices(): void
    {
        $order = $this->createOrder();

        $this->get(route('orders.show', $order))
            ->assertOk()
            ->assertSee('Dodaj produkty z magazynu...')
            ->assertSee('Dodaj produkt ręcznie...')
            ->assertSee(route('orders.catalog-products.index', $order), false)
            ->assertSee(route('orders.catalog-products.store', $order), false)
            ->assertSee('data-catalog-products-form', false)
            ->assertSee('data-catalog-currency-select', false)
            ->assertSee('name="currency"', false)
            ->assertSee('data-default-currency="PLN"', false);
    }

    public function test_catalog_picker_lists_only_active_products_and_searches_by_name_id_and_ean(): void
    {
        $order = $this->createOrder();
        $active = $this->createProduct([
            'name' => 'Dekoder aktywny',
            'ean' => '5901234567890',
        ]);
        $hidden = $this->createProduct([
            'name' => 'Produkt ukryty',
            'ean' => '5900000000001',
            'is_active' => false,
        ]);

        $response = $this->getJson(route('orders.catalog-products.index', $order));

        $response->assertOk()->assertJsonPath('total', 1);
        $this->assertStringContainsString($active->name, $response->json('html'));
        $this->assertStringNotContainsString($hidden->name, $response->json('html'));
        $this->assertStringNotContainsString((string) $active->ean, $response->json('html'));

        $byEan = $this->getJson(route('orders.catalog-products.index', [
            'order' => $order,
            'q' => $active->ean,
        ]));
        $byEan->assertOk()->assertJsonPath('total', 1);
        $this->assertStringContainsString($active->name, $byEan->json('html'));

        $byId = $this->getJson(route('orders.catalog-products.index', [
            'order' => $order,
            'q' => (string) $active->getKey(),
        ]));
        $byId->assertOk()->assertJsonPath('total', 1);
    }

    public function test_multiple_catalog_products_are_added_with_snapshot_and_operator_price_and_quantity(): void
    {
        $this->createCurrency('EUR');
        $order = $this->createOrder();
        $first = $this->createProduct([
            'name' => 'Dekoder testowy',
            'ean' => '5901234567890',
            'price_gross' => '10.01',
            'stock_quantity' => 8,
            'package_weight_kg' => '1.250',
        ]);
        $second = $this->createProduct([
            'name' => 'Pilot testowy',
            'price_gross' => '9.99',
            'stock_quantity' => 1,
        ]);

        $this->postJson(route('orders.catalog-products.store', $order), [
            'currency' => 'EUR',
            'items' => [
                [
                    'product_id' => $first->getKey(),
                    'quantity' => 2,
                    'unit_price_gross' => '10,01',
                ],
                [
                    'product_id' => $second->getKey(),
                    'quantity' => 3,
                    'unit_price_gross' => '7.25',
                ],
            ],
        ])
            ->assertOk()
            ->assertJson([
                'saved' => true,
                'refresh' => ['products', 'order-info', 'history'],
            ]);

        $items = $order->items()->orderBy('id')->get();
        $this->assertCount(2, $items);
        $this->assertSame($first->getKey(), $items[0]->product_id);
        $this->assertSame('Dekoder testowy', $items[0]->product_name);
        $this->assertSame('5901234567890', $items[0]->ean);
        $this->assertSame(2, $items[0]->quantity);
        $this->assertSame('10.01', $items[0]->unit_price_gross);
        $this->assertSame('20.02', $items[0]->total_price_gross);
        $this->assertSame('EUR', $items[0]->currency);
        $this->assertSame('23.00', $items[0]->vat_rate);
        $this->assertSame('1.250', $items[0]->weight);
        $this->assertSame($second->getKey(), $items[1]->product_id);
        $this->assertSame(3, $items[1]->quantity);
        $this->assertSame('7.25', $items[1]->unit_price_gross);
        $this->assertSame('21.75', $items[1]->total_price_gross);
        $this->assertSame('EUR', $items[1]->currency);
        $this->assertSame('41.77', $order->refresh()->total_gross);
        $this->assertSame('EUR', $order->currency);

        $this->assertSame(8, $first->refresh()->stock_quantity);
        $this->assertSame('10.01', $first->price_gross);
        $this->assertSame(1, $second->refresh()->stock_quantity);
        $this->assertSame('9.99', $second->price_gross);
        $this->assertDatabaseCount('order_events', 2);
        $eventPayload = $order->events()->latest('id')->first()->payload;
        $this->assertTrue((bool) $eventPayload['price_overridden']);
        $this->assertSame('PLN', $eventPayload['catalog_currency']);
        $this->assertSame('EUR', $eventPayload['currency']);
        $this->assertTrue((bool) $eventPayload['order_currency_adopted']);
    }

    public function test_duplicate_or_hidden_products_are_rejected_without_partial_write(): void
    {
        $order = $this->createOrder();
        $active = $this->createProduct();
        $hidden = $this->createProduct(['is_active' => false]);

        $this->postJson(route('orders.catalog-products.store', $order), [
            'currency' => 'PLN',
            'items' => [
                ['product_id' => $active->getKey(), 'quantity' => 1, 'unit_price_gross' => '10.00'],
                ['product_id' => $active->getKey(), 'quantity' => 2, 'unit_price_gross' => '10.00'],
            ],
        ])->assertUnprocessable()->assertJsonValidationErrors('items.1.product_id');

        $this->postJson(route('orders.catalog-products.store', $order), [
            'currency' => 'PLN',
            'items' => [
                ['product_id' => $active->getKey(), 'quantity' => 1, 'unit_price_gross' => '10.00'],
                ['product_id' => $hidden->getKey(), 'quantity' => 1, 'unit_price_gross' => '10.00'],
            ],
        ])->assertUnprocessable()->assertJsonValidationErrors('items.1.product_id');

        $this->assertDatabaseCount('order_items', 0);
        $this->assertDatabaseCount('order_events', 0);
        $this->assertSame('0.00', $order->refresh()->total_gross);
    }

    public function test_unsupported_selected_currency_is_rejected_without_partial_write(): void
    {
        $order = $this->createOrder();
        $pln = $this->createProduct(['currency' => 'PLN']);
        $eur = $this->createProduct(['currency' => 'EUR']);

        $this->postJson(route('orders.catalog-products.store', $order), [
            'currency' => 'ZZZ',
            'items' => [
                ['product_id' => $pln->getKey(), 'quantity' => 1, 'unit_price_gross' => '10.00'],
                ['product_id' => $eur->getKey(), 'quantity' => 1, 'unit_price_gross' => '20.00'],
            ],
        ])->assertUnprocessable()->assertJsonValidationErrors('currency');

        $this->assertDatabaseCount('order_items', 0);
        $this->assertDatabaseCount('order_events', 0);
        $this->assertSame('PLN', $order->refresh()->currency);
        $this->assertSame('0.00', $order->total_gross);
    }

    public function test_selected_currency_must_match_an_order_that_already_contains_items(): void
    {
        $this->createCurrency('EUR');
        $order = $this->createOrder(['total_gross' => '10.00']);
        OrderItem::query()->create([
            'order_id' => $order->getKey(),
            'product_name' => 'Istniejąca pozycja',
            'quantity' => 1,
            'unit_price_gross' => '10.00',
            'total_price_gross' => '10.00',
            'currency' => 'PLN',
            'vat_rate' => '23.00',
        ]);
        $product = $this->createProduct();

        $this->postJson(route('orders.catalog-products.store', $order), [
            'currency' => 'EUR',
            'items' => [
                ['product_id' => $product->getKey(), 'quantity' => 1, 'unit_price_gross' => '20.00'],
            ],
        ])->assertUnprocessable()->assertJsonValidationErrors('currency');

        $this->assertDatabaseCount('order_items', 1);
        $this->assertDatabaseCount('order_events', 0);
        $this->assertSame('PLN', $order->refresh()->currency);
        $this->assertSame('10.00', $order->total_gross);
    }

    public function test_manual_product_remains_unlinked_from_the_catalog(): void
    {
        $order = $this->createOrder();

        $this->postJson(route('orders.products.store', $order), [
            'product_name' => 'Pozycja ręczna',
            'quantity' => 1,
            'unit_price_gross' => '15.00',
            'currency' => 'PLN',
            'vat_rate' => '8',
            'weight' => '0.250',
        ])->assertOk();

        $this->assertNull($order->items()->sole()->product_id);
    }

    public function test_deleting_catalog_product_nulls_relation_and_preserves_order_snapshot(): void
    {
        $order = $this->createOrder();
        $product = $this->createProduct([
            'name' => 'Historyczny produkt',
            'ean' => '5901234567890',
        ]);
        $item = OrderItem::query()->create([
            'order_id' => $order->getKey(),
            'product_id' => $product->getKey(),
            'product_name' => $product->name,
            'ean' => $product->ean,
            'quantity' => 1,
            'unit_price_gross' => '99.99',
            'total_price_gross' => '99.99',
            'currency' => 'PLN',
            'vat_rate' => '23.00',
        ]);

        $product->delete();

        $item->refresh();
        $this->assertNull($item->product_id);
        $this->assertSame('Historyczny produkt', $item->product_name);
        $this->assertSame('5901234567890', $item->ean);
        $this->assertSame('99.99', $item->unit_price_gross);
    }

    /** @param array<string, mixed> $overrides */
    private function createOrder(array $overrides = []): Order
    {
        return Order::query()->create(array_merge([
            'source' => 'manual',
            'status' => Order::STATUS_NEW,
            'currency' => 'PLN',
            'total_gross' => '0.00',
            'paid_amount' => '0.00',
            'delivery_cost_gross' => '0.00',
            'payment_status' => 'unpaid',
        ], $overrides));
    }

    /** @param array<string, mixed> $overrides */
    private function createProduct(array $overrides = []): Product
    {
        return Product::query()->create(array_merge([
            'name' => 'Produkt '.uniqid(),
            'ean' => null,
            'price_gross' => '99.99',
            'currency' => 'PLN',
            'vat_rate' => '23.00',
            'stock_quantity' => 10,
            'package_weight_kg' => null,
            'is_active' => true,
        ], $overrides));
    }

    private function createCurrency(string $code): Currency
    {
        return Currency::query()->create([
            'code' => $code,
            'name' => $code,
            'nbp_table' => 'A',
        ]);
    }
}
