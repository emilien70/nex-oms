<?php

namespace Tests\Feature;

use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Modules\Integrations\Prestashop\Enums\PrestashopCatalogVatMode;
use Modules\Integrations\Prestashop\Enums\PrestashopConnectionTestStatus;
use Modules\Integrations\Prestashop\Models\PrestashopAccount;
use Modules\Integrations\Prestashop\Models\PrestashopOrderPayment;
use Modules\Products\Models\Product;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PrestashopOrderImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_preview_reads_order_without_writing_to_oms(): void
    {
        $this->account();
        $this->fakePrestashop();

        $this->get(route('integrations.prestashop.edit', ['order_identifier' => '13581']))
            ->assertOk()
            ->assertSeeText('Zamówienie 13581')
            ->assertSeeText('Zgemma H11s Wi-Fi Dekoder 4K UHD')
            ->assertSeeText('462,00 PLN')
            ->assertSeeText('Wysłane')
            ->assertSeeText('Importuj do OMS');

        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('prestashop_order_links', 0);
        $this->assertDatabaseCount('prestashop_order_payments', 0);
    }

    public function test_import_creates_order_items_payment_links_and_event_in_one_operation(): void
    {
        $this->account();
        $this->fakePrestashop();

        $response = $this->post(route('integrations.prestashop.orders.import'), [
            'order_identifier' => '13581',
        ]);

        $order = Order::query()->sole();

        $response->assertRedirect(route('orders.show', $order));
        $this->assertSame('prestashop', $order->source);
        $this->assertSame('13581', $order->external_id);
        $this->assertSame(Order::STATUS_NEW, $order->status);
        $this->assertSame('PLN', $order->currency);
        $this->assertSame('462.00', $order->total_gross);
        $this->assertSame('462.00', $order->paid_amount);
        $this->assertSame('10.00', $order->delivery_cost_gross);
        $this->assertSame('paid', $order->payment_status);
        $this->assertSame('InPost Pay', $order->payment_method);
        $this->assertSame('Paczkomaty 24/7', $order->shipping_method);
        $this->assertNull($order->customer_login);
        $this->assertSame('+48 500 600 700', $order->customer_phone);
        $this->assertSame('JDC02M', $order->pickup_point_name);
        $this->assertSame('JDC02M', $order->pickup_point_id);
        $this->assertSame('Długa 12/4', $order->shipping_street);
        $this->assertNull($order->shipping_building_number);
        $this->assertNull($order->shipping_apartment_number);

        $item = $order->items()->sole();
        $this->assertNull($item->product_id);
        $this->assertSame('15505', $item->external_id);
        $this->assertSame('452.00', $item->unit_price_gross);
        $this->assertSame('23.00', $item->vat_rate);

        $this->assertDatabaseHas('prestashop_order_links', [
            'order_id' => $order->getKey(),
            'external_order_id' => 13581,
            'external_reference' => '13581',
            'external_order_state_id' => 4,
            'external_order_state_name' => 'Wysłane',
        ]);
        $this->assertDatabaseHas('prestashop_order_item_links', [
            'order_item_id' => $item->getKey(),
            'external_order_detail_id' => 15505,
            'external_product_id' => 96,
            'external_product_attribute_id' => null,
        ]);
        $this->assertDatabaseHas('prestashop_order_payments', [
            'external_payment_id' => 9121,
            'transaction_id' => '01a13443-5782-707c-afeb-bab523785b89',
            'amount' => '462.00',
            'currency' => 'PLN',
            'method' => 'InPost Pay',
            'accounting_status' => PrestashopOrderPayment::STATUS_ACCOUNTED,
            'needs_review' => false,
        ]);
        $this->assertDatabaseHas('order_events', [
            'order_id' => $order->getKey(),
            'event_type' => 'prestashop_order_imported',
            'title' => 'Zaimportowano zamówienie z PrestaShop',
        ]);

        $this->get(route('orders.show', $order))
            ->assertOk()
            ->assertSeeText('13581')
            ->assertSeeText('multi-click.pl')
            ->assertSee('<span class="inline-edit-text nex-empty">...</span>', false)
            ->assertSee('<input type="text" name="customer_login" class="form-control form-control-sm" value="">', false)
            ->assertSee('href="https://multi-click.pl/index.php?controller=product&amp;id_product=96"', false)
            ->assertSeeText('JDC02M');
    }

    public function test_repeated_import_returns_existing_order_without_duplication(): void
    {
        $this->account();
        $this->fakePrestashop();

        $this->post(route('integrations.prestashop.orders.import'), ['order_identifier' => '13581'])
            ->assertRedirect();
        $order = Order::query()->sole();

        $this->post(route('integrations.prestashop.orders.import'), ['order_identifier' => '13581'])
            ->assertRedirect(route('orders.show', $order));

        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('order_items', 1);
        $this->assertDatabaseCount('prestashop_order_links', 1);
        $this->assertDatabaseCount('prestashop_order_payments', 1);
        $this->assertDatabaseCount('order_events', 1);
    }

    public function test_import_uses_linked_oms_product_and_keeps_prestashop_price(): void
    {
        $account = $this->account();
        $product = $this->product([
            'name' => 'Nazwa katalogowa OMS',
            'price_gross' => '999.99',
            'vat_rate' => '8.00',
            'thumbnail_path' => 'products/test/thumbnail.jpg',
        ]);
        $product->prestashopProductLinks()->create([
            'prestashop_account_id' => $account->getKey(),
            'external_product_id' => 96,
            'external_product_attribute_id' => 0,
        ]);
        $this->fakePrestashop();

        $this->post(route('integrations.prestashop.orders.import'), [
            'order_identifier' => '13581',
        ])->assertRedirect();

        $order = Order::query()->sole();
        $item = $order->items()->sole();

        $this->assertSame($product->getKey(), $item->product_id);
        $this->assertSame('Nazwa katalogowa OMS', $item->product_name);
        $this->assertSame('452.00', $item->unit_price_gross);
        $this->assertSame('452.00', $item->total_price_gross);
        $this->assertSame('23.00', $item->vat_rate);
        $this->assertSame('1.000', $item->weight);

        $this->get(route('orders.show', $order))
            ->assertOk()
            ->assertSeeText('Nazwa katalogowa OMS')
            ->assertSee(route('products.image', [$product, 'thumbnail']), false);
    }

    public function test_imported_linked_bundle_keeps_prestashop_price_and_snapshots_components(): void
    {
        $account = $this->account();
        $decoder = $this->product(['name' => 'Dekoder']);
        $adapter = $this->product(['name' => 'Adapter']);
        $bundle = $this->product([
            'item_type' => Product::TYPE_BUNDLE,
            'name' => 'Zestaw katalogowy OMS',
            'price_gross' => '999.99',
            'stock_quantity' => 0,
        ]);
        $bundle->bundleComponents()->createMany([
            ['component_product_id' => $decoder->getKey(), 'quantity' => 1],
            ['component_product_id' => $adapter->getKey(), 'quantity' => 2],
        ]);
        $bundle->prestashopProductLinks()->create([
            'prestashop_account_id' => $account->getKey(),
            'external_product_id' => 96,
            'external_product_attribute_id' => 0,
        ]);
        $this->fakePrestashop();

        $this->post(route('integrations.prestashop.orders.import'), [
            'order_identifier' => '13581',
        ])->assertRedirect();

        $item = Order::query()->sole()->items()->sole();

        $this->assertSame($bundle->getKey(), $item->product_id);
        $this->assertSame('Zestaw katalogowy OMS', $item->product_name);
        $this->assertSame('452.00', $item->unit_price_gross);
        $this->assertSame(2, $item->bundleComponents()->count());
        $this->assertDatabaseHas('order_item_bundle_components', [
            'order_item_id' => $item->getKey(),
            'component_product_id' => $adapter->getKey(),
            'quantity_per_bundle' => 2,
        ]);
    }

    public function test_import_can_keep_store_name_and_always_use_catalog_vat(): void
    {
        $account = $this->account([
            'use_catalog_product_names' => false,
            'catalog_vat_mode' => PrestashopCatalogVatMode::Catalog,
        ]);
        $product = $this->product([
            'name' => 'Nazwa katalogowa OMS',
            'price_gross' => '999.99',
            'vat_rate' => '8.00',
        ]);
        $product->prestashopProductLinks()->create([
            'prestashop_account_id' => $account->getKey(),
            'external_product_id' => 96,
            'external_product_attribute_id' => 0,
        ]);
        $this->fakePrestashop();

        $this->post(route('integrations.prestashop.orders.import'), [
            'order_identifier' => '13581',
        ])->assertRedirect();

        $item = Order::query()->sole()->items()->sole();

        $this->assertSame($product->getKey(), $item->product_id);
        $this->assertSame('Zgemma H11s Wi-Fi Dekoder 4K UHD', $item->product_name);
        $this->assertSame('8.00', $item->vat_rate);
        $this->assertSame('452.00', $item->unit_price_gross);
    }

    public function test_disabled_product_substitution_does_not_apply_an_existing_mapping(): void
    {
        $account = $this->account([
            'use_linked_products' => false,
            'use_catalog_product_names' => true,
            'catalog_vat_mode' => PrestashopCatalogVatMode::Catalog,
        ]);
        $product = $this->product([
            'name' => 'Nazwa katalogowa OMS',
            'vat_rate' => '8.00',
        ]);
        $product->prestashopProductLinks()->create([
            'prestashop_account_id' => $account->getKey(),
            'external_product_id' => 96,
            'external_product_attribute_id' => 0,
        ]);
        $this->fakePrestashop();

        $this->post(route('integrations.prestashop.orders.import'), [
            'order_identifier' => '13581',
        ])->assertRedirect();

        $item = Order::query()->sole()->items()->sole();

        $this->assertNull($item->product_id);
        $this->assertSame('Zgemma H11s Wi-Fi Dekoder 4K UHD', $item->product_name);
        $this->assertSame('23.00', $item->vat_rate);
        $this->assertSame('452.00', $item->unit_price_gross);
    }

    public function test_repeated_import_completes_a_missing_product_link_without_changing_price(): void
    {
        $account = $this->account();
        $this->fakePrestashop();
        $this->post(route('integrations.prestashop.orders.import'), ['order_identifier' => '13581']);
        $order = Order::query()->sole();
        $item = $order->items()->sole();
        $this->assertNull($item->product_id);

        $product = $this->product([
            'name' => 'Nazwa po powiązaniu',
            'price_gross' => '999.99',
        ]);
        $product->prestashopProductLinks()->create([
            'prestashop_account_id' => $account->getKey(),
            'external_product_id' => 96,
            'external_product_attribute_id' => 0,
        ]);

        $this->post(route('integrations.prestashop.orders.import'), ['order_identifier' => '13581'])
            ->assertRedirect(route('orders.show', $order));

        $item->refresh();

        $this->assertSame($product->getKey(), $item->product_id);
        $this->assertSame('Nazwa po powiązaniu', $item->product_name);
        $this->assertSame('452.00', $item->unit_price_gross);
        $this->assertSame('452.00', $item->total_price_gross);
        $this->assertDatabaseHas('order_events', [
            'order_id' => $order->getKey(),
            'event_type' => 'prestashop_order_products_linked',
        ]);
    }

    public function test_repeated_import_does_not_rebuild_products_after_a_document_slot_exists(): void
    {
        $account = $this->account();
        $this->fakePrestashop();
        $this->post(route('integrations.prestashop.orders.import'), ['order_identifier' => '13581']);
        $order = Order::query()->sole();
        $item = $order->items()->sole();
        $order->documentSlots()->create([
            'document_type' => 'invoice',
            'invoice_id' => null,
        ]);
        $product = $this->product(['name' => 'Nazwa po powiązaniu']);
        $product->prestashopProductLinks()->create([
            'prestashop_account_id' => $account->getKey(),
            'external_product_id' => 96,
            'external_product_attribute_id' => 0,
        ]);

        $this->post(route('integrations.prestashop.orders.import'), ['order_identifier' => '13581'])
            ->assertRedirect(route('orders.show', $order));

        $item->refresh();

        $this->assertNull($item->product_id);
        $this->assertSame('Zgemma H11s Wi-Fi Dekoder 4K UHD', $item->product_name);
        $this->assertSame('452.00', $item->unit_price_gross);
        $this->assertDatabaseMissing('order_events', [
            'order_id' => $order->getKey(),
            'event_type' => 'prestashop_order_products_linked',
        ]);
    }

    public function test_repeated_import_completes_only_missing_contact_and_pickup_metadata(): void
    {
        $this->account();
        $this->fakePrestashop();

        $this->post(route('integrations.prestashop.orders.import'), ['order_identifier' => '13581']);
        $order = Order::query()->sole();
        $order->forceFill([
            'customer_login' => 'jan@example.test',
            'customer_phone' => null,
            'pickup_point_name' => null,
            'pickup_point_id' => null,
        ])->save();

        $this->post(route('integrations.prestashop.orders.import'), ['order_identifier' => '13581'])
            ->assertRedirect(route('orders.show', $order));

        $order->refresh();

        $this->assertNull($order->customer_login);
        $this->assertSame('+48 500 600 700', $order->customer_phone);
        $this->assertSame('JDC02M', $order->pickup_point_name);
        $this->assertSame('JDC02M', $order->pickup_point_id);
        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('order_items', 1);
        $this->assertDatabaseHas('order_events', [
            'order_id' => $order->getKey(),
            'event_type' => 'prestashop_order_metadata_completed',
        ]);
    }

    public function test_import_prefers_apaczka_point_over_base_delivery_fields(): void
    {
        $this->account();
        $this->fakePrestashop(enrichmentOverrides: [
            'apaczka_point' => 'RAD50M',
        ]);

        $this->post(route('integrations.prestashop.orders.import'), ['order_identifier' => '13581'])
            ->assertRedirect();

        $order = Order::query()->sole();

        $this->assertSame('RAD50M', $order->pickup_point_name);
        $this->assertSame('RAD50M', $order->pickup_point_id);
    }

    public function test_order_without_prestashop_status_is_imported_as_new_with_warning_and_history(): void
    {
        $this->account();
        $this->fakePrestashop(['current_state' => '0']);

        $this->get(route('integrations.prestashop.edit', ['order_identifier' => '13581']))
            ->assertOk()
            ->assertSeeText('Brak przypisanego statusu')
            ->assertSeeText('PrestaShop nie przypisał statusu. Zamówienie zostanie zaimportowane do OMS jako Nowe.');

        $this->post(route('integrations.prestashop.orders.import'), [
            'order_identifier' => '13581',
        ])->assertRedirect();

        $order = Order::query()->sole();

        $this->assertSame(Order::STATUS_NEW, $order->status);
        $this->assertDatabaseHas('prestashop_order_links', [
            'order_id' => $order->id,
            'external_order_state_id' => null,
            'external_order_state_name' => null,
        ]);
        $this->assertDatabaseHas('order_events', [
            'order_id' => $order->id,
            'event_type' => 'prestashop_order_imported',
            'description' => 'Zamówienie 13581 nie miało przypisanego statusu w PrestaShop. W OMS ustawiono status Nowe.',
        ]);
    }

    public function test_malformed_prestashop_status_still_blocks_import(): void
    {
        $this->account();
        $this->fakePrestashop(['current_state' => 'invalid']);

        $this->post(route('integrations.prestashop.orders.import'), [
            'order_identifier' => '13581',
        ])
            ->assertRedirect(route('integrations.prestashop.edit', ['order_identifier' => '13581']))
            ->assertSessionHas('error', 'PrestaShop zwrócił nieprawidłowe ID statusu zamówienia.');

        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('prestashop_order_links', 0);
    }

    public function test_import_requires_active_integration_and_does_not_call_prestashop(): void
    {
        $this->account(['is_active' => false]);
        Http::fake();

        $this->post(route('integrations.prestashop.orders.import'), ['order_identifier' => '13581'])
            ->assertRedirect(route('integrations.prestashop.edit', ['order_identifier' => '13581']))
            ->assertSessionHas('error', 'Włącz integrację PrestaShop przed importem zamówienia.');

        Http::assertNothingSent();
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_total_mismatch_blocks_import_without_partial_records(): void
    {
        $this->account();
        $this->fakePrestashop(['total_paid' => '463.000000', 'total_paid_tax_incl' => '463.000000']);

        $this->post(route('integrations.prestashop.orders.import'), ['order_identifier' => '13581'])
            ->assertRedirect(route('integrations.prestashop.edit', ['order_identifier' => '13581']))
            ->assertSessionHas('error', fn (string $message): bool => str_contains($message, 'nie zgadza się'));

        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('prestashop_order_links', 0);
    }

    public function test_invalid_identifier_is_rejected_before_network_request(): void
    {
        $this->account();
        Http::fake();

        $this->post(route('integrations.prestashop.orders.import'), ['order_identifier' => '../13581'])
            ->assertSessionHasErrors('order_identifier');

        Http::assertNothingSent();
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_payment_total_mismatch_blocks_import(): void
    {
        $this->account();
        $this->fakePrestashop(paymentOverrides: ['amount' => '400.000000']);

        $this->post(route('integrations.prestashop.orders.import'), ['order_identifier' => '13581'])
            ->assertSessionHas('error', fn (string $message): bool => str_contains($message, 'Suma płatności'));

        $this->assertDatabaseCount('orders', 0);
    }

    #[DataProvider('vatPriorityCases')]
    public function test_vat_priority_preserves_prices_and_records_the_source(string $mode, ?string $net, ?string $catalogRate, bool $linked, string $country, ?string $defaultRate, ?string $expected, ?string $origin): void
    {
        $account = $this->account(['catalog_vat_mode' => $mode, 'default_vat_rate' => $defaultRate]);
        if ($linked) {
            $this->product(['vat_rate' => $catalogRate])->prestashopProductLinks()->create([
                'prestashop_account_id' => $account->id, 'external_product_id' => 96, 'external_product_attribute_id' => 0,
            ]);
        }
        $this->fakePrestashop(detailOverrides: [
            'unit_price_tax_excl' => $net,
            'total_price_tax_excl' => $net === null || $net === '' ? null : '367.480000',
        ], country: $country);
        $this->post(route('integrations.prestashop.orders.import'), ['order_identifier' => '13581'])->assertSessionHasNoErrors();
        $order = Order::query()->sole();
        $item = $order->items()->sole();
        $this->assertSame($expected, $item->vat_rate);
        $this->assertSame('452.00', $item->unit_price_gross);
        $this->assertSame('452.00', $item->total_price_gross);
        $this->assertSame('462.00', $order->total_gross);
        $this->assertSame('462.00', $order->paid_amount);
        $event = $order->events()->where('event_type', 'prestashop_order_imported')->sole();
        $this->assertSame($origin, $event->payload['vat_sources'][0]['source']);
        if ($origin === 'default') {
            $this->assertStringContainsString('domyślną stawkę VAT', $event->description);
        }
    }

    public static function vatPriorityCases(): array
    {
        return [
            'catalog first' => ['catalog', '367.479675', '8.00', true, 'PL', '5.00', '8.00', 'catalog'],
            'invalid catalog preserves channel' => ['catalog', '367.479675', '101.00', true, 'PL', '5.00', '23.00', 'channel'],
            'store mode' => ['store', '367.479675', '8.00', true, 'PL', '5.00', '23.00', 'channel'],
            'fallback keeps store' => ['fallback', '367.479675', '8.00', true, 'PL', '5.00', '23.00', 'channel'],
            'fallback missing uses catalog' => ['fallback', null, '8.00', true, 'PL', '5.00', '8.00', 'catalog'],
            'channel without mapping' => ['catalog', '367.479675', null, false, 'PL', '5.00', '23.00', 'channel'],
            'default for missing net' => ['catalog', null, null, false, 'PL', '5.00', '5.00', 'default'],
            'default for empty net' => ['store', '', '8.00', true, 'PL', '5.00', '5.00', 'default'],
            'default zero' => ['catalog', null, null, false, 'PL', '0.00', '0.00', 'default'],
            'default disabled' => ['catalog', null, null, false, 'PL', null, null, null],
            'explicit source zero' => ['fallback', '452.00', '8.00', true, 'PL', '5.00', '0.00', 'channel'],
            'explicit catalog zero' => ['catalog', '367.479675', '0.00', true, 'PL', '5.00', '0.00', 'catalog'],
            'foreign preserves channel' => ['catalog', '367.479675', '8.00', true, 'DE', '5.00', '23.00', 'channel'],
            'foreign missing stays missing' => ['catalog', null, '8.00', true, 'DE', '5.00', null, null],
        ];
    }

    public function test_invalid_net_is_not_silently_replaced_by_default_vat(): void
    {
        $this->account(['default_vat_rate' => '23.00']);
        $this->fakePrestashop(detailOverrides: ['unit_price_tax_excl' => 'wrong']);
        $this->post(route('integrations.prestashop.orders.import'), ['order_identifier' => '13581'])->assertSessionHas('error');
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_new_default_does_not_backfill_a_previously_imported_order(): void
    {
        $account = $this->account();
        $this->fakePrestashop(detailOverrides: ['unit_price_tax_excl' => null, 'total_price_tax_excl' => null]);
        $this->post(route('integrations.prestashop.orders.import'), ['order_identifier' => '13581'])->assertSessionHasNoErrors();
        $order = Order::query()->sole();
        $before = $order->items()->sole()->getAttributes();
        $account->update(['default_vat_rate' => '23.00']);
        $this->post(route('integrations.prestashop.orders.import'), ['order_identifier' => '13581'])->assertSessionHasNoErrors();
        $this->assertSame($before, $order->items()->sole()->getAttributes());
        $this->assertSame(1, $order->events()->where('event_type', 'prestashop_order_imported')->count());
    }

    public function test_total_net_can_supply_channel_vat_when_unit_net_is_missing(): void
    {
        $this->account(['default_vat_rate' => '5.00']);
        $this->fakePrestashop(detailOverrides: ['unit_price_tax_excl' => null]);
        $this->post(route('integrations.prestashop.orders.import'), ['order_identifier' => '13581'])->assertSessionHasNoErrors();
        $order = Order::query()->sole();
        $this->assertSame('23.00', $order->items()->sole()->vat_rate);
        $this->assertSame('channel', $order->events()->where('event_type', 'prestashop_order_imported')->sole()->payload['vat_sources'][0]['source']);
    }

    private function account(array $overrides = []): PrestashopAccount
    {
        $account = PrestashopAccount::query()->create(array_merge([
            'account_key' => PrestashopAccount::DEFAULT_ACCOUNT_KEY,
            'name' => 'multi-click.pl',
            'base_url' => 'https://multi-click.pl',
            'api_key' => '12345678901234567890123456789012',
            'is_active' => true,
            'shop_id' => 1,
            'language_id' => 2,
            'language_iso_code' => 'pl',
            'available_currencies' => [
                ['id' => 1, 'name' => 'Złoty', 'iso_code' => 'PLN'],
            ],
            'order_enrichment_resource' => 'bl_order',
            'last_tested_at' => now(),
            'last_test_status' => PrestashopConnectionTestStatus::Success,
            'last_test_message' => 'OK',
        ], $overrides));
        $account->orderStates()->create([
            'external_id' => 4,
            'name' => 'Wysłane',
            'shipped' => true,
            'refreshed_at' => now(),
        ]);

        return $account;
    }

    /** @param array<string, mixed> $overrides */
    private function product(array $overrides = []): Product
    {
        return Product::query()->create(array_merge([
            'name' => 'Produkt OMS',
            'price_gross' => '199.00',
            'currency' => 'PLN',
            'vat_rate' => '23.00',
            'stock_quantity' => 10,
            'is_active' => true,
        ], $overrides));
    }

    private function fakePrestashop(
        array $orderOverrides = [],
        array $detailOverrides = [],
        array $paymentOverrides = [],
        array $enrichmentOverrides = [],
        string $country = 'PL',
    ): void {
        $order = array_merge([
            'id' => 13581,
            'id_address_delivery' => '10239',
            'id_address_invoice' => '10239',
            'id_currency' => '1',
            'id_customer' => '11443',
            'id_carrier' => '281',
            'current_state' => '4',
            'module' => 'inpostizi',
            'payment' => 'InPost Pay',
            'date_add' => '2026-10-13 09:53:55',
            'date_upd' => '2026-10-13 12:29:51',
            'note' => '',
            'gift_message' => '',
            'total_discounts_tax_incl' => '0.000000',
            'total_paid' => '462.000000',
            'total_paid_tax_incl' => '462.000000',
            'total_paid_real' => '462.000000',
            'total_shipping_tax_incl' => '10.000000',
            'total_wrapping_tax_incl' => '0.000000',
            'reference' => '13581',
        ], $orderOverrides);
        $detail = array_merge([
            'id' => 15505,
            'id_order' => '13581',
            'product_id' => '96',
            'product_attribute_id' => '0',
            'product_name' => 'Zgemma H11s Wi-Fi Dekoder 4K UHD',
            'product_quantity' => '1',
            'product_reference' => 'H11S-WiFi',
            'product_ean13' => '5901890074162',
            'product_weight' => '1.000000',
            'unit_price_tax_incl' => '452.000000',
            'unit_price_tax_excl' => '367.479675',
            'total_price_tax_incl' => '452.000000',
            'total_price_tax_excl' => '367.480000',
        ], $detailOverrides);
        $payment = array_merge([
            'id' => 9121,
            'order_reference' => '13581',
            'id_currency' => '1',
            'amount' => '462.000000',
            'payment_method' => 'InPost Pay',
            'transaction_id' => '01a13443-5782-707c-afeb-bab523785b89',
            'card_number' => 'not-stored',
            'card_holder' => 'not-stored',
            'date_add' => '2026-10-13 09:56:44',
        ], $paymentOverrides);
        $enrichment = array_merge([
            'id' => 13581,
            'bl_delivery_point_id' => 'JDC02M',
            'bl_delivery_point_name' => 'JDC02M',
            'bl_delivery_point_address' => '',
            'bl_delivery_point_city' => '',
            'bl_delivery_point_postcode' => '',
        ], $enrichmentOverrides);

        Http::swap((new Factory)->preventStrayRequests());
        Http::fake(function (Request $request) use ($order, $detail, $payment, $enrichment, $country) {
            return match ((string) parse_url($request->url(), PHP_URL_PATH)) {
                '/api/orders/13581' => Http::response(['orders' => [$order]]),
                '/api/bl_order/13581' => Http::response(['orders' => [$enrichment]]),
                '/api/order_details' => Http::response(['order_details' => [$detail]]),
                '/api/currencies/1' => Http::response(['currencies' => [[
                    'id' => 1,
                    'iso_code' => 'PLN',
                ]]]),
                '/api/customers/11443' => Http::response(['customer' => [
                    'id' => 11443,
                    'firstname' => 'Jan',
                    'lastname' => 'Kowalski',
                    'email' => 'jan@example.test',
                ]]),
                '/api/addresses/10239' => Http::response(['address' => [
                    'id' => 10239,
                    'id_country' => '14',
                    'firstname' => 'Jan',
                    'lastname' => 'Kowalski',
                    'address1' => 'Długa 12/4',
                    'postcode' => '00-001',
                    'city' => 'Warszawa',
                    'phone_mobile' => '',
                    'phone' => '500 600 700',
                ]]),
                '/api/countries/14' => Http::response(['countries' => [[
                    'id' => 14,
                    'iso_code' => $country,
                    'name' => $country === 'PL' ? 'Polska' : 'Niemcy',
                ]]]),
                '/api/carriers/281' => Http::response(['carriers' => [[
                    'id' => 281,
                    'name' => 'Paczkomaty 24/7',
                ]]]),
                '/api/order_payments' => Http::response(['order_payments' => [$payment]]),
                default => Http::response([], 404),
            };
        });
    }
}
