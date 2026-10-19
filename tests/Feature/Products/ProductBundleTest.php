<?php

namespace Tests\Feature\Products;

use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Integrations\Prestashop\Models\PrestashopAccount;
use Modules\Products\Models\Product;
use Tests\TestCase;

class ProductBundleTest extends TestCase
{
    use RefreshDatabase;

    public function test_bundle_uses_the_product_id_pool_and_has_derived_availability(): void
    {
        $decoder = $this->product([
            'name' => 'ZGEMMA H11s DEKODER 4K',
            'stock_quantity' => 5,
        ]);
        $adapter = $this->product([
            'name' => 'Adapter USB Wi-Fi MT7601',
            'stock_quantity' => 10,
        ]);

        $this->get(route('product-bundles.create'))
            ->assertOk()
            ->assertSee('Nowy zestaw')
            ->assertSee('Skład zestawu')
            ->assertSee('Dodaj produkt');

        $this->post(route('product-bundles.store'), $this->bundlePayload([
            'components' => [
                ['product_id' => $decoder->getKey(), 'quantity' => 2],
                ['product_id' => $adapter->getKey(), 'quantity' => 3],
            ],
        ]))
            ->assertRedirect(route('product-bundles.edit', 3))
            ->assertSessionHas('success', 'Zestaw został zapisany.');

        $bundle = Product::query()->where('item_type', Product::TYPE_BUNDLE)->sole();

        $this->assertSame(3, $bundle->getKey());
        $this->assertSame(0, $bundle->stock_quantity);
        $this->assertSame(2, $bundle->load('bundleComponents.component')->availableStockQuantity());
        $this->assertDatabaseHas('product_bundle_components', [
            'bundle_id' => $bundle->getKey(),
            'component_product_id' => $decoder->getKey(),
            'quantity' => 2,
        ]);
        $this->assertDatabaseHas('product_bundle_components', [
            'bundle_id' => $bundle->getKey(),
            'component_product_id' => $adapter->getKey(),
            'quantity' => 3,
        ]);

        $this->get(route('products.index'))
            ->assertOk()
            ->assertSee($decoder->name)
            ->assertDontSee($bundle->name);

        $this->get(route('products.index', ['tab' => 'bundles']))
            ->assertOk()
            ->assertSee('Dodaj zestaw')
            ->assertSee($bundle->name)
            ->assertSee('2 szt.')
            ->assertDontSee(route('products.stock', $bundle), false);

        $this->get(route('product-bundles.edit', $bundle))
            ->assertOk()
            ->assertSee('Edycja zestawu')
            ->assertSee('Dostępna liczba zestawów');
    }

    public function test_bundle_requires_two_distinct_simple_products(): void
    {
        $product = $this->product();
        $otherProduct = $this->product(['name' => 'Drugi produkt']);
        $existingBundle = Product::query()->create([
            'item_type' => Product::TYPE_BUNDLE,
            'name' => 'Istniejący zestaw',
            'price_gross' => '100.00',
            'currency' => 'PLN',
            'vat_rate' => '23.00',
            'stock_quantity' => 0,
            'is_active' => true,
        ]);

        $this->post(route('product-bundles.store'), $this->bundlePayload([
            'components' => [
                ['product_id' => $product->getKey(), 'quantity' => 1],
                ['product_id' => $product->getKey(), 'quantity' => 2],
            ],
        ]))
            ->assertSessionHasErrors('components.1.product_id');

        $this->post(route('product-bundles.store'), $this->bundlePayload([
            'components' => [
                ['product_id' => $product->getKey(), 'quantity' => 1],
                ['product_id' => $existingBundle->getKey(), 'quantity' => 1],
            ],
        ]))
            ->assertSessionHasErrors('components.1.product_id');

        $this->assertDatabaseCount('product_bundle_components', 0);
        $this->assertDatabaseCount('products', 3);

        $this->post(route('product-bundles.store'), $this->bundlePayload([
            'components' => [
                ['product_id' => $product->getKey(), 'quantity' => 1],
                ['product_id' => $otherProduct->getKey(), 'quantity' => 1],
            ],
        ]))->assertSessionDoesntHaveErrors();
    }

    public function test_adding_bundle_to_order_keeps_one_line_and_snapshots_its_components(): void
    {
        $decoder = $this->product(['name' => 'Dekoder', 'stock_quantity' => 8]);
        $adapter = $this->product(['name' => 'Adapter', 'stock_quantity' => 20]);
        $replacement = $this->product(['name' => 'Pilot', 'stock_quantity' => 30]);
        $bundle = $this->bundle($decoder, $adapter);
        $order = Order::query()->create([
            'source' => 'manual',
            'status' => Order::STATUS_NEW,
            'currency' => 'PLN',
            'total_gross' => '0.00',
            'paid_amount' => '0.00',
            'delivery_cost_gross' => '0.00',
            'payment_status' => 'unpaid',
        ]);

        $this->postJson(route('orders.catalog-products.store', $order), [
            'currency' => 'PLN',
            'items' => [[
                'product_id' => $bundle->getKey(),
                'quantity' => 2,
                'unit_price_gross' => '650.00',
            ]],
        ])->assertOk();

        $item = $order->items()->sole();

        $this->assertSame($bundle->getKey(), $item->product_id);
        $this->assertSame($bundle->name, $item->product_name);
        $this->assertSame('650.00', $item->unit_price_gross);
        $this->assertSame(2, $item->bundleComponents()->count());
        $this->assertDatabaseHas('order_item_bundle_components', [
            'order_item_id' => $item->getKey(),
            'component_product_id' => $decoder->getKey(),
            'component_product_name' => 'Dekoder',
            'quantity_per_bundle' => 1,
        ]);

        $bundle->bundleComponents()->delete();
        $bundle->bundleComponents()->createMany([
            ['component_product_id' => $decoder->getKey(), 'quantity' => 4],
            ['component_product_id' => $replacement->getKey(), 'quantity' => 1],
        ]);

        $this->assertDatabaseHas('order_item_bundle_components', [
            'order_item_id' => $item->getKey(),
            'component_product_id' => $adapter->getKey(),
            'quantity_per_bundle' => 2,
        ]);
        $this->assertDatabaseMissing('order_item_bundle_components', [
            'order_item_id' => $item->getKey(),
            'component_product_id' => $replacement->getKey(),
        ]);
    }

    public function test_bundle_stock_cannot_be_edited_directly(): void
    {
        $first = $this->product();
        $second = $this->product(['name' => 'Drugi produkt']);
        $bundle = $this->bundle($first, $second);

        $this->patchJson(route('products.stock', $bundle), [
            'stock_quantity' => 100,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('stock_quantity');

        $this->assertSame(0, $bundle->refresh()->stock_quantity);
    }

    public function test_duplicating_order_preserves_the_bundle_component_snapshot(): void
    {
        $decoder = $this->product(['name' => 'Dekoder']);
        $adapter = $this->product(['name' => 'Adapter']);
        $bundle = $this->bundle($decoder, $adapter);
        $order = Order::query()->create([
            'source' => 'manual',
            'status' => Order::STATUS_NEW,
            'currency' => 'PLN',
            'total_gross' => '0.00',
            'paid_amount' => '0.00',
            'delivery_cost_gross' => '0.00',
            'payment_status' => 'unpaid',
        ]);

        $this->postJson(route('orders.catalog-products.store', $order), [
            'currency' => 'PLN',
            'items' => [[
                'product_id' => $bundle->getKey(),
                'quantity' => 2,
                'unit_price_gross' => '650.00',
            ]],
        ])->assertOk();

        $response = $this->post(route('orders.duplicate', $order));
        $copy = Order::query()->whereKeyNot($order->getKey())->sole();
        $copyItem = $copy->items()->sole();

        $response->assertRedirect(route('orders.show', $copy));
        $this->assertSame($bundle->getKey(), $copyItem->product_id);
        $this->assertSame(2, $copyItem->bundleComponents()->count());
        $this->assertDatabaseHas('order_item_bundle_components', [
            'order_item_id' => $copyItem->getKey(),
            'bundle_product_id' => $bundle->getKey(),
            'component_product_id' => $decoder->getKey(),
            'component_product_name' => 'Dekoder',
            'quantity_per_bundle' => 1,
        ]);
        $this->assertDatabaseHas('order_item_bundle_components', [
            'order_item_id' => $copyItem->getKey(),
            'bundle_product_id' => $bundle->getKey(),
            'component_product_id' => $adapter->getKey(),
            'component_product_name' => 'Adapter',
            'quantity_per_bundle' => 2,
        ]);
    }

    public function test_bundle_can_be_linked_to_a_prestashop_offer_like_a_product(): void
    {
        $first = $this->product();
        $second = $this->product(['name' => 'Drugi produkt']);
        $bundle = $this->bundle($first, $second);
        $account = PrestashopAccount::query()->create([
            'account_key' => PrestashopAccount::DEFAULT_ACCOUNT_KEY,
            'name' => 'multi-click.pl',
            'base_url' => 'https://multi-click.pl',
            'api_key' => '12345678901234567890123456789012',
            'is_active' => true,
        ]);

        $this->postJson(route('products.integrations.prestashop.store', [$bundle, $account]), [
            'external_product_id' => 96,
        ])
            ->assertOk()
            ->assertJsonPath('link.external_product_id', 96);

        $this->assertDatabaseHas('prestashop_product_links', [
            'product_id' => $bundle->getKey(),
            'prestashop_account_id' => $account->getKey(),
            'external_product_id' => 96,
        ]);
    }

    private function bundle(Product $first, Product $second): Product
    {
        $bundle = Product::query()->create([
            'item_type' => Product::TYPE_BUNDLE,
            'name' => 'ZGEMMA H11s DEKODER 4K + WiFi',
            'price_gross' => '650.00',
            'currency' => 'PLN',
            'vat_rate' => '23.00',
            'stock_quantity' => 0,
            'is_active' => true,
        ]);
        $bundle->bundleComponents()->createMany([
            ['component_product_id' => $first->getKey(), 'quantity' => 1],
            ['component_product_id' => $second->getKey(), 'quantity' => 2],
        ]);

        return $bundle;
    }

    /** @param array<string, mixed> $overrides */
    private function product(array $overrides = []): Product
    {
        return Product::query()->create(array_merge([
            'item_type' => Product::TYPE_PRODUCT,
            'name' => 'Produkt '.uniqid(),
            'price_gross' => '99.99',
            'currency' => 'PLN',
            'vat_rate' => '23.00',
            'stock_quantity' => 10,
            'is_active' => true,
        ], $overrides));
    }

    /** @param array<string, mixed> $overrides */
    private function bundlePayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'ZGEMMA H11s DEKODER 4K + WiFi',
            'ean' => '',
            'price_gross' => '650.00',
            'currency' => 'PLN',
            'vat_rate_preset' => '23.00',
            'custom_vat_rate' => '',
            'remove_image' => '0',
            'is_active' => '1',
            'components' => [],
        ], $overrides);
    }
}
