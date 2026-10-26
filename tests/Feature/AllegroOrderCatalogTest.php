<?php

namespace Tests\Feature;

use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Mockery\MockInterface;
use Modules\Integrations\Allegro\Exceptions\AllegroImportException;
use Modules\Integrations\Allegro\Models\AllegroAccount;
use Modules\Integrations\Allegro\Models\AllegroProductLink;
use Modules\Integrations\Allegro\Services\AllegroOrderCatalogService;
use Modules\Integrations\Allegro\Services\AllegroOrderImportService;
use Modules\Integrations\Allegro\Services\AllegroOrderPreviewService;
use Modules\Invoices\Services\InvoiceIssuingService;
use Modules\Products\Models\Product;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Invoices\Concerns\CreatesInvoiceStage2CDocuments;
use Tests\Support\AllegroOrderFixture as Fixture;
use Tests\TestCase;

class AllegroOrderCatalogTest extends TestCase
{
    use CreatesInvoiceStage2CDocuments;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Http::fake(['*/order/checkout-forms/*' => Http::response(Fixture::payload())]);
        Queue::fake();
    }

    public function test_preview_and_import_use_catalog_name_id_photo_and_vat_but_keep_allegro_money(): void
    {
        $account = $this->account();
        $product = $this->product(['thumbnail_path' => 'products/1/thumbnail.jpg', 'image_path' => 'products/1/original.jpg']);
        $this->link($account, $product);
        $before = $product->getAttributes();
        $preview = $this->get($this->previewUrl())->assertOk()->assertSee('Dekoder OMS')->assertSee('Allegro: Produkt Allegro')
            ->assertSee(route('products.image', [$product, 'thumbnail']), false)->assertSee('23.00%')->viewData('orderPreview');
        $this->assertDatabaseCount('orders', 0);
        $this->assertSame($product->id, $preview['items'][0]['product_id']);
        $this->post(route('integrations.allegro.orders.import'), ['order_identifier' => Fixture::ORDER_ID, 'preview_hash' => $preview['source_hash']])->assertRedirect();
        $order = Order::query()->sole();
        $item = $order->items()->sole();
        $this->assertSame($product->id, $item->product_id);
        $this->assertSame('Dekoder OMS', $item->product_name);
        $this->assertSame('23.00', $item->vat_rate);
        $this->assertSame('30.01', $item->unit_price_gross);
        $this->assertSame('90.03', $item->total_price_gross);
        $this->assertSame('100.03', $order->total_gross);
        $this->assertSame('100.03', $order->paid_amount);
        $this->assertSame('12345678901', $item->offer_id);
        $this->assertSame($before, $product->fresh()->getAttributes());
        $this->assertDatabaseCount('product_stock_movements', 0);
        $this->get(route('orders.show', $order))->assertOk()->assertSee('Dekoder OMS')->assertSee(route('products.image', [$product, 'thumbnail']), false)->assertSee('https://allegro.pl/oferta/-12345678901', false);
        Queue::assertNothingPushed();
    }

    #[DataProvider('settingCombinations')]
    public function test_settings_control_only_catalog_fields(bool $linked, bool $names, bool $vat): void
    {
        $account = $this->account(['use_linked_products' => $linked, 'use_catalog_product_names' => $names, 'use_catalog_vat' => $vat]);
        $product = $this->product();
        $this->link($account, $product);
        $order = $this->import($account);
        $item = $order->items()->sole();
        $this->assertSame($linked ? $product->id : null, $item->product_id);
        $this->assertSame($linked && $names ? 'Dekoder OMS' : 'Produkt Allegro', $item->product_name);
        $this->assertSame($linked && $vat ? '23.00' : null, $item->vat_rate);
        $this->assertSame('30.01', $item->unit_price_gross);
        $this->assertSame('PLN', $item->currency);
    }

    public static function settingCombinations(): array
    {
        return [[true, true, true], [true, false, true], [true, true, false], [true, false, false], [false, true, true]];
    }

    public function test_missing_mapping_is_an_importable_original_item_not_an_automatic_name_match(): void
    {
        $account = $this->account();
        $this->product(['name' => 'Produkt Allegro']);
        $preview = $this->preview($account);
        $this->assertTrue($preview['importable']);
        $this->assertNull($preview['items'][0]['product_id']);
        $this->assertStringContainsString('Brak powiązania', implode(' ', $preview['warnings']));
        $order = app(AllegroOrderImportService::class)->import($account, Fixture::ORDER_ID, $preview['source_hash']);
        $this->assertSame('Produkt Allegro', $order->items()->sole()->product_name);
        $this->assertNull($order->items()->sole()->vat_rate);
        $this->assertDatabaseCount('products', 1);
        $this->assertDatabaseCount('allegro_product_links', 0);
    }

    public function test_bundle_is_one_order_line_with_a_frozen_component_snapshot_and_no_stock_change(): void
    {
        $account = $this->account();
        $decoder = $this->product(['name' => 'Dekoder', 'stock_quantity' => 50, 'package_weight_kg' => '10.000']);
        $wifi = $this->product(['name' => 'Wi-Fi', 'stock_quantity' => 100, 'package_weight_kg' => '20.000']);
        $bundle = $this->product(['name' => 'Dekoder z Wi-Fi', 'item_type' => 'bundle', 'stock_quantity' => 0, 'package_weight_kg' => '1.234']);
        $bundle->bundleComponents()->create(['component_product_id' => $decoder->id, 'quantity' => 1]);
        $bundle->bundleComponents()->create(['component_product_id' => $wifi->id, 'quantity' => 2]);
        $this->link($account, $bundle);
        $order = $this->import($account);
        $this->assertCount(1, $order->items);
        $item = $order->items->sole();
        $this->assertSame($bundle->id, $item->product_id);
        $this->assertSame(3, $item->quantity);
        $this->assertSame('1.234', $item->weight);
        $this->assertCount(2, $item->bundleComponents);
        $this->assertSame([1, 2], $item->bundleComponents->pluck('quantity_per_bundle')->all());
        $this->assertSame(50, $decoder->fresh()->stock_quantity);
        $this->assertSame(100, $wifi->fresh()->stock_quantity);
        $bundle->bundleComponents()->where('component_product_id', $wifi->id)->update(['quantity' => 5]);
        $wifi->update(['name' => 'Nowa nazwa Wi-Fi']);
        $this->assertSame([1, 2], $item->bundleComponents()->orderBy('id')->pluck('quantity_per_bundle')->all());
        $this->assertSame('Wi-Fi', $item->bundleComponents()->orderByDesc('id')->first()->component_product_name);
        $bundle->update(['package_weight_kg' => '9.999']);
        app(AllegroOrderImportService::class)->import($account, Fixture::ORDER_ID, str_repeat('0', 64));
        $this->assertSame('1.234', $item->fresh()->weight);
        $this->assertDatabaseCount('product_stock_movements', 0);
    }

    public function test_invalid_bundle_is_blocked_before_any_order_is_saved(): void
    {
        $account = $this->account();
        $bundle = $this->product(['item_type' => 'bundle']);
        $this->link($account, $bundle);
        $preview = $this->preview($account);
        $this->assertFalse($preview['importable']);
        $this->assertStringContainsString('Zestaw OMS', implode(' ', $preview['blockers']));
        try {
            app(AllegroOrderImportService::class)->import($account, Fixture::ORDER_ID, $preview['source_hash']);
            $this->fail('Niepoprawny zestaw nie może być importowany.');
        } catch (AllegroImportException) {
            $this->assertDatabaseCount('orders', 0);
        }
    }

    public function test_bundle_weight_change_after_preview_requires_confirmation(): void
    {
        $account = $this->account();
        $bundle = $this->product(['item_type' => 'bundle', 'package_weight_kg' => '1.000']);
        $bundle->bundleComponents()->createMany([
            ['component_product_id' => $this->product()->id, 'quantity' => 1],
            ['component_product_id' => $this->product()->id, 'quantity' => 1],
        ]);
        $this->link($account, $bundle);
        $preview = $this->preview($account);
        $bundle->update(['package_weight_kg' => '2.000']);
        try {
            app(AllegroOrderImportService::class)->import($account, Fixture::ORDER_ID, $preview['source_hash']);
            $this->fail('Weight change requires a new preview');
        } catch (AllegroImportException) {
            $this->assertDatabaseCount('orders', 0);
        }
    }

    public function test_catalog_weight_falls_back_to_source_and_reapplication_does_not_keep_old_bundle_weight(): void
    {
        $account = $this->account();
        $bundle = $this->product(['item_type' => 'bundle', 'package_weight_kg' => '2.500']);
        $bundle->bundleComponents()->createMany([
            ['component_product_id' => $this->product()->id, 'quantity' => 1],
            ['component_product_id' => $this->product()->id, 'quantity' => 1],
        ]);
        $this->link($account, $bundle);
        $preview = $this->preview($account);
        $preview['items'][0]['source_weight'] = '0.750';
        $catalog = app(AllegroOrderCatalogService::class);
        $preview = $catalog->apply($account, $preview);
        $this->assertSame('2.500', $preview['items'][0]['weight']);
        $bundle->update(['package_weight_kg' => null]);
        $preview = $catalog->apply($account, $preview);
        $this->assertSame('0.750', $preview['items'][0]['weight']);
        $account->update(['use_linked_products' => false]);
        $this->assertSame('0.750', $catalog->apply($account, $preview)['items'][0]['weight']);
    }

    public function test_bundle_composition_change_after_preview_requires_a_new_confirmation(): void
    {
        $account = $this->account();
        $bundle = $this->product(['item_type' => 'bundle']);
        $bundle->bundleComponents()->create(['component_product_id' => $this->product()->id, 'quantity' => 1]);
        $component = $bundle->bundleComponents()->create(['component_product_id' => $this->product()->id, 'quantity' => 1]);
        $this->link($account, $bundle);
        $preview = $this->preview($account);
        $component->update(['quantity' => 2]);
        try {
            app(AllegroOrderImportService::class)->import($account, Fixture::ORDER_ID, $preview['source_hash']);
            $this->fail('Nowy skład zestawu wymaga potwierdzenia.');
        } catch (AllegroImportException) {
            $this->assertDatabaseCount('orders', 0);
        }
    }

    #[DataProvider('foreignContexts')]
    public function test_foreign_context_never_automatically_uses_domestic_catalog_vat(string $path, mixed $value): void
    {
        $account = $this->account();
        $this->link($account, $this->product());
        $raw = Fixture::payload();
        data_set($raw, $path, $value);
        $this->fakeOrder($raw);
        $preview = $this->preview($account);
        $this->assertNull($preview['items'][0]['vat_rate']);
        $this->assertStringContainsString('VAT pozostawiono do ręcznej weryfikacji', implode(' ', $preview['warnings']));
        $order = app(AllegroOrderImportService::class)->import($account, Fixture::ORDER_ID, $preview['source_hash']);
        $this->assertNotNull($order->items()->sole()->product_id);
        $this->assertNull($order->items()->sole()->vat_rate);
    }

    public static function foreignContexts(): array
    {
        return [['marketplace.id', 'allegro-cz'], ['marketplace.id', null], ['delivery.address.countryCode', 'DE'], ['invoice.address.countryCode', 'DE']];
    }

    #[DataProvider('vatValues')]
    public function test_vat_is_validated_and_never_guessed(mixed $value, ?string $expected): void
    {
        $account = $this->account();
        $this->link($account, $this->product(['vat_rate' => $value]));
        $this->assertSame($expected, $this->import($account)->items()->sole()->vat_rate);
    }

    public static function vatValues(): array
    {
        return [['0.00', '0.00'], ['8.00', '8.00'], ['101.00', null], ['2.50', null]];
    }

    public function test_identity_and_environment_are_used_for_lookup_not_only_local_account_id(): void
    {
        $account = $this->account();
        $this->link($account, $this->product());
        $account->update(['allegro_user_id' => 'other-seller']);
        $this->assertNull($this->preview($account)['items'][0]['product_id']);
        $account->update(['allegro_user_id' => '12345678', 'environment' => 'sandbox']);
        $this->assertNull($this->preview($account)['items'][0]['product_id']);
    }

    #[DataProvider('catalogChanges')]
    public function test_changes_between_preview_and_import_require_confirmation(string $field, mixed $value): void
    {
        $account = $this->account();
        $product = $this->product();
        $link = $this->link($account, $product);
        $preview = $this->preview($account);
        if ($field === 'mapping') {
            $link->update(['product_id' => $this->product(['name' => 'Inny produkt'])->id]);
        } elseif ($field === 'use_catalog_product_names') {
            $account->update([$field => $value]);
        } else {
            $product->update([$field => $value]);
        }
        try {
            app(AllegroOrderImportService::class)->import($account->fresh(), Fixture::ORDER_ID, $preview['source_hash']);
            $this->fail('Import wymaga nowego potwierdzenia.');
        } catch (AllegroImportException) {
            $this->assertDatabaseCount('orders', 0);
        }
    }

    public static function catalogChanges(): array
    {
        return [['name', 'Nowa nazwa'], ['vat_rate', '8.00'], ['thumbnail_path', 'changed.jpg'], ['image_checksum', 'new-checksum'], ['mapping', null], ['use_catalog_product_names', false]];
    }

    public function test_locked_recheck_detects_a_change_after_remote_preview_was_prepared(): void
    {
        $account = $this->account();
        $product = $this->product();
        $this->link($account, $product);
        $preview = $this->preview($account);
        $this->mock(AllegroOrderPreviewService::class, function (MockInterface $mock) use ($product, $preview): void {
            $mock->shouldReceive('preview')->once()->andReturnUsing(function () use ($product, $preview): array {
                $product->update(['name' => 'Zmiana przed blokadą']);

                return $preview;
            });
        });
        try {
            app(AllegroOrderImportService::class)->import($account, Fixture::ORDER_ID, $preview['source_hash']);
            $this->fail('Nie wolno zapisać starego dopasowania.');
        } catch (AllegroImportException $exception) {
            $this->assertStringContainsString('Powiązania, dane produktów', $exception->getMessage());
            $this->assertDatabaseCount('orders', 0);
        }
    }

    public function test_catalog_price_and_stock_changes_do_not_change_order_price_or_invalidate_preview(): void
    {
        $account = $this->account();
        $product = $this->product();
        $this->link($account, $product);
        $preview = $this->preview($account);
        $product->update(['price_gross' => '999.99', 'stock_quantity' => 50]);
        $order = app(AllegroOrderImportService::class)->import($account, Fixture::ORDER_ID, $preview['source_hash']);
        $this->assertSame('30.01', $order->items()->sole()->unit_price_gross);
        $this->assertSame(50, $product->fresh()->stock_quantity);
    }

    public function test_additional_services_are_not_mapped_to_the_product_or_bundle(): void
    {
        $account = $this->account();
        $this->link($account, $this->product());
        $raw = Fixture::payload();
        $raw['lineItems'][0]['selectedAdditionalServices'] = [['name' => 'Pakowanie', 'quantity' => 1, 'price' => ['amount' => '4.97', 'currency' => 'PLN']]];
        $raw['summary']['totalToPay']['amount'] = '105.00';
        $this->fakeOrder($raw);
        $order = $this->import($account);
        $this->assertCount(2, $order->items);
        $productItem = $order->items->firstWhere('offer_id', '12345678901');
        $serviceItem = $order->items->firstWhere('offer_id', null);
        $this->assertNotNull($productItem->product_id);
        $this->assertNull($serviceItem->product_id);
        $this->assertNull($serviceItem->vat_rate);
        $this->assertSame('Pakowanie', $serviceItem->product_name);
    }

    public function test_existing_order_and_invoice_are_not_changed_when_mapping_or_catalog_is_changed_or_deleted(): void
    {
        $account = $this->account();
        $product = $this->product();
        $link = $this->link($account, $product);
        $order = $this->import($account);
        $invoice = app(InvoiceIssuingService::class)->issue($order, $this->createDocumentSeries(), $this->documentContext('2026-10-19 14:00:00'));
        $invoiceBefore = $invoice->getAttributes();
        $invoiceItems = $invoice->items->toArray();
        $orderBefore = $order->getAttributes();
        $itemBefore = $order->items()->sole()->getAttributes();
        $product->update(['name' => 'Nowa nazwa', 'vat_rate' => '8.00']);
        $account->update(['default_vat_rate' => '5.00']);
        $link->update(['product_id' => $this->product(['name' => 'Zastępstwo'])->id]);
        $again = app(AllegroOrderImportService::class)->import($account, Fixture::ORDER_ID, str_repeat('0', 64));
        $this->assertSame($order->id, $again->id);
        $this->assertSame($orderBefore, $again->getAttributes());
        $this->assertSame($itemBefore, $again->items()->sole()->getAttributes());
        $this->assertSame($invoiceBefore, $invoice->fresh()->getAttributes());
        $this->assertSame($invoiceItems, $invoice->fresh()->items->toArray());
        $product->delete();
        $item = $order->items()->sole();
        $this->assertNull($item->product_id);
        $this->assertSame('Dekoder OMS', $item->product_name);
        $this->assertSame('30.01', $item->unit_price_gross);
        $this->get(route('orders.show', $order))->assertOk()->assertSee('Dekoder OMS');
    }

    public function test_settings_save_is_validated_does_not_change_credentials_and_invalidates_preview(): void
    {
        $account = $this->account();
        $beforeToken = $account->access_token;
        $this->withSession(['allegro_order_preview' => ['hash' => 'old']])->put(route('integrations.allegro.orders.settings'), [
            'use_linked_products' => '1', 'use_catalog_product_names' => '0', 'use_catalog_vat' => '0', 'access_token' => 'injected',
        ])->assertRedirect(route('integrations.allegro.edit', ['tab' => 'orders']))->assertSessionMissing('allegro_order_preview');
        $this->assertTrue($account->fresh()->use_linked_products);
        $this->assertFalse($account->fresh()->use_catalog_product_names);
        $this->assertFalse($account->fresh()->use_catalog_vat);
        $this->assertSame($beforeToken, $account->fresh()->access_token);
        $this->putJson(route('integrations.allegro.orders.settings'), ['use_linked_products' => 'wrong'])->assertUnprocessable()
            ->assertJsonValidationErrors(['use_linked_products', 'use_catalog_product_names', 'use_catalog_vat']);
    }

    #[DataProvider('importVatCases')]
    public function test_channel_catalog_and_default_vat_priority(mixed $tax, ?string $catalogRate, bool $useCatalog, ?string $defaultRate, bool $domestic, ?string $expected, ?string $origin): void
    {
        $account = $this->account(['use_catalog_vat' => $useCatalog, 'default_vat_rate' => $defaultRate]);
        if ($catalogRate !== null) {
            $this->link($account, $this->product(['vat_rate' => $catalogRate]));
        }
        $raw = Fixture::payload();
        $raw['lineItems'][0]['tax'] = $tax;
        if (! $domestic) {
            $raw['delivery']['address']['countryCode'] = 'DE';
        }
        $this->fakeOrder($raw);
        $preview = $this->preview($account);
        $this->assertSame($expected, $preview['items'][0]['vat_rate']);
        $order = app(AllegroOrderImportService::class)->import($account, Fixture::ORDER_ID, $preview['source_hash']);
        $item = $order->items()->sole();
        $this->assertSame($expected, $item->vat_rate);
        $this->assertSame('30.01', $item->unit_price_gross);
        $this->assertSame('90.03', $item->total_price_gross);
        $this->assertSame('100.03', $order->total_gross);
        $event = $order->events()->where('event_type', 'allegro_order_imported')->sole();
        $this->assertSame($origin, $event->payload['vat_sources'][0]['source']);
        if ($origin === 'default') {
            $this->assertStringContainsString('domyślną stawkę VAT', $event->description);
        }
    }

    public static function importVatCases(): array
    {
        return [
            'channel without mapping' => [['rate' => '8.00'], null, true, '23.00', true, '8.00', 'channel'],
            'channel zero' => [['rate' => '0'], null, true, '23.00', true, '0.00', 'channel'],
            'catalog first' => [['rate' => '8.00'], '23.00', true, '5.00', true, '23.00', 'catalog'],
            'catalog zero' => [['rate' => '8.00'], '0.00', true, '5.00', true, '0.00', 'catalog'],
            'invalid catalog preserves channel' => [['rate' => '8.00'], '101.00', true, '5.00', true, '8.00', 'channel'],
            'catalog disabled' => [['rate' => '8.00'], '23.00', false, '5.00', true, '8.00', 'channel'],
            'missing source uses catalog' => [null, '23.00', true, '5.00', true, '23.00', 'catalog'],
            'default last' => [null, null, true, '5.00', true, '5.00', 'default'],
            'default zero' => [null, null, true, '0.00', true, '0.00', 'default'],
            'disabled default' => [null, null, true, null, true, null, null],
            'foreign preserves channel' => [['rate' => '19.00'], '23.00', true, '5.00', false, '19.00', 'channel'],
            'foreign missing stays missing' => [null, '23.00', true, '5.00', false, null, null],
            'exempt' => [['rate' => null, 'exemption' => 'ART_113'], '23.00', true, '5.00', true, null, null],
            'invalid rate' => [['rate' => 'wrong'], '23.00', true, '5.00', true, null, null],
            'invalid tax shape' => ['wrong', '23.00', true, '5.00', true, null, null],
        ];
    }

    public function test_default_changes_require_new_preview_even_when_channel_vat_is_present(): void
    {
        $account = $this->account();
        $raw = Fixture::payload();
        $raw['lineItems'][0]['tax'] = ['rate' => '8.00'];
        $this->fakeOrder($raw);
        $preview = $this->preview($account);
        $this->assertSame('8.00', $preview['items'][0]['vat_rate']);
        $account->update(['default_vat_rate' => '5.00']);
        $this->expectException(AllegroImportException::class);
        app(AllegroOrderImportService::class)->import($account, Fixture::ORDER_ID, $preview['source_hash']);
    }

    public function test_new_default_does_not_backfill_a_completed_order(): void
    {
        $account = $this->account();
        $order = $this->import($account);
        $before = $order->items()->sole()->getAttributes();
        $account->update(['default_vat_rate' => '23.00']);
        $again = app(AllegroOrderImportService::class)->import($account, Fixture::ORDER_ID, str_repeat('0', 64));
        $this->assertSame($before, $again->items()->sole()->getAttributes());
        $this->assertSame(1, $again->events()->where('event_type', 'allegro_order_imported')->count());
    }

    public function test_default_vat_setting_is_optional_validated_and_preserves_zero(): void
    {
        $account = $this->account();
        $this->assertNull($account->default_vat_rate);
        $payload = ['use_linked_products' => '1', 'use_catalog_product_names' => '1', 'use_catalog_vat' => '1'];
        $this->put(route('integrations.allegro.orders.settings'), $payload + ['default_vat_rate' => '0'])->assertSessionHasNoErrors();
        $this->assertSame('0.00', $account->fresh()->default_vat_rate);
        $this->put(route('integrations.allegro.orders.settings'), $payload)->assertSessionHasNoErrors();
        $this->assertSame('0.00', $account->fresh()->default_vat_rate);
        foreach (['-1', '101', 'zw', '2.5', true, ['23']] as $invalid) {
            $this->putJson(route('integrations.allegro.orders.settings'), $payload + ['default_vat_rate' => $invalid])
                ->assertUnprocessable()->assertJsonValidationErrors('default_vat_rate');
        }
        $this->put(route('integrations.allegro.orders.settings'), $payload + ['default_vat_rate' => ''])->assertSessionHasNoErrors();
        $this->assertNull($account->fresh()->default_vat_rate);
        $this->get(route('integrations.allegro.edit', ['tab' => 'orders']))->assertOk()->assertSee('Domyślna stawka VAT');
        Http::assertNothingSent();
    }

    private function account(array $attributes = []): AllegroAccount
    {
        return AllegroAccount::query()->create(array_merge([
            'account_key' => 'default', 'name' => 'multi-click', 'environment' => 'production',
            'client_id' => 'client', 'client_secret' => 'secret', 'access_token' => 'token', 'refresh_token' => 'refresh',
            'access_token_expires_at' => now()->addHours(5), 'granted_scopes' => AllegroAccount::REQUIRED_SCOPES,
            'allegro_user_id' => '12345678', 'is_active' => true, 'last_test_status' => 'success', 'marketplace_id' => 'allegro-pl',
        ], $attributes))->refresh();
    }

    private function product(array $attributes = []): Product
    {
        return Product::query()->create(array_merge(['name' => 'Dekoder OMS', 'price_gross' => '199.00', 'currency' => 'PLN', 'stock_quantity' => 10, 'vat_rate' => '23.00', 'is_active' => true], $attributes))->refresh();
    }

    private function link(AllegroAccount $account, Product $product): AllegroProductLink
    {
        return $product->allegroProductLinks()->create(['allegro_account_id' => $account->id, 'environment' => $account->environment, 'seller_user_id' => $account->allegro_user_id, 'external_offer_id' => '12345678901']);
    }

    private function preview(AllegroAccount $account): array
    {
        return app(AllegroOrderPreviewService::class)->preview($account, Fixture::ORDER_ID);
    }

    private function import(AllegroAccount $account): Order
    {
        $preview = $this->preview($account);

        return app(AllegroOrderImportService::class)->import($account, Fixture::ORDER_ID, $preview['source_hash']);
    }

    private function previewUrl(): string
    {
        return route('integrations.allegro.edit', ['tab' => 'orders', 'order_identifier' => Fixture::ORDER_ID]);
    }

    private function fakeOrder(array $payload): void
    {
        Http::swap((new Factory)->preventStrayRequests());
        Http::fake(['*/order/checkout-forms/*' => Http::response($payload)]);
    }
}
