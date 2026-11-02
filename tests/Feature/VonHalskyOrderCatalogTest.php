<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderStatusSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Modules\Integrations\Services\OrderImportVatResolver;
use Modules\Integrations\VonHalsky\Models\VonHalskyAccount;
use Modules\Integrations\VonHalsky\Services\VonHalskyOrderCatalogService;
use Modules\Integrations\VonHalsky\Services\VonHalskyOrderPreviewService;
use Modules\Products\Models\Product;
use Modules\Products\Services\OrderItemBundleSnapshotService;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\VonHalsky\CatalogFixtures;
use Tests\TestCase;

class VonHalskyOrderCatalogTest extends TestCase
{
    use CatalogFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Http::fake(['*' => Http::response($this->sourceOrder())]);
        Queue::fake();
        Mail::fake();
        Storage::fake('local');
        OrderStatusSetting::syncDefaults();
    }

    public function test_settings_default_to_off_save_with_version_and_reject_invalid_flags(): void
    {
        $account = $this->account();
        $this->assertFalse($account->use_linked_products);
        $this->assertFalse($account->use_catalog_product_names);
        $this->assertFalse($account->use_catalog_vat);
        $route = route('integrations.von-halsky.import-settings');
        $this->get(route('integrations.von-halsky.edit', ['tab' => 'orders']))->assertOk()
            ->assertSee('Korzystaj z powiązanych produktów OMS')->assertSee('Pobieraj VAT z produktów OMS');
        $this->post($route, ['expected_lock_version' => 0, ...$this->flags(), 'default_vat_rate' => '23'])
            ->assertSessionHasNoErrors();
        $this->assertTrue($account->fresh()->use_linked_products);
        $this->post($route, ['expected_lock_version' => 0, ...$this->flags()])->assertSessionHasErrors('expected_lock_version');
        $this->post($route, ['expected_lock_version' => 1, 'use_catalog_vat' => 'yes'])->assertSessionHasErrors('use_catalog_vat');
        Http::assertNothingSent();
    }

    #[DataProvider('flagsCases')]
    public function test_link_name_and_vat_flags_are_independent(bool $linked, bool $names, bool $vat): void
    {
        $account = $this->account(['use_linked_products' => $linked, 'use_catalog_product_names' => $names,
            'use_catalog_vat' => $vat, 'default_vat_rate' => '23']);
        $product = $this->product(['package_weight_kg' => '1.234']);
        $this->link($account, $product);
        $preview = $this->preview($account);
        $item = $preview['items'][0];
        $this->assertSame($linked ? $product->id : null, $item['product_id']);
        $this->assertSame($linked && $names ? 'Nazwa OMS' : 'Nazwa Von Halsky', $item['product_name']);
        $this->assertSame($linked && $vat ? '8.00' : '23.00', $item['vat_rate']);
        $this->assertSame('195.00', $item['unit_price_gross']);
        $this->assertNull($item['quantity']);
        $this->post(route('integrations.von-halsky.import'), $this->payload($preview['source_hash']) + [
            'product_id' => 999, 'vat_rate' => '99', 'product_name' => 'FAKE', 'unit_price_gross' => '1',
        ])->assertSessionHasNoErrors()->assertRedirect();
        $order = Order::query()->sole();
        $stored = $order->items()->sole();
        $this->assertSame($item['product_name'], $stored->product_name);
        $this->assertSame($item['vat_rate'], $stored->vat_rate);
        $this->assertSame($item['product_id'], $stored->product_id);
        $this->assertSame('195.00', $stored->unit_price_gross);
        $this->assertSame('195.00', $order->paid_amount);
        $this->assertSame('195.00', $order->total_gross);
        $this->assertSame(10, $product->fresh()->stock_quantity);
        $this->assertDatabaseCount('automation_runs', 0);
        $this->assertDatabaseCount('invoices', 0);
        Queue::assertNothingPushed();
        Mail::assertNothingSent();
        Http::assertSentCount(2);
    }

    public static function flagsCases(): array
    {
        $cases = [];
        foreach ([false, true] as $linked) {
            foreach ([false, true] as $names) {
                foreach ([false, true] as $vat) {
                    $cases[] = [$linked, $names, $vat];
                }
            }
        }

        return $cases;
    }

    #[DataProvider('vatCases')]
    public function test_vat_resolution_preserves_zero_and_does_not_disguise_invalid_source(mixed $source, bool $special,
        ?string $catalog, ?string $default, ?string $expected, ?string $origin): void
    {
        $account = $this->account($this->flags() + ['default_vat_rate' => $default]);
        if ($catalog !== null) {
            $this->link($account, $this->product(['vat_rate' => $catalog]));
        }
        $preview = app(VonHalskyOrderPreviewService::class)->preview($account, 'PG76P11')['import'];
        $preview['items'][0]['source_vat'] = app(OrderImportVatResolver::class)->source($source, $special);
        $result = app(VonHalskyOrderCatalogService::class)->apply($account, $preview)['items'][0];
        $this->assertSame($expected, $result['vat_rate']);
        $this->assertSame($origin, $result['vat_source']);
    }

    public static function vatCases(): array
    {
        return [
            [null, false, '8', '23', '8.00', 'catalog'],
            [null, false, '0', '23', '0.00', 'catalog'],
            ['23', false, '8', '5', '8.00', 'catalog'],
            ['0', false, null, '23', '0.00', 'channel'],
            ['5', false, null, '23', '5.00', 'channel'],
            [null, false, null, '0', '0.00', 'default'],
            [null, false, null, null, null, null],
            [null, false, null, '23', '23.00', 'default'],
            ['bad', false, '8', '23', null, null],
            [true, false, '8', '23', null, null],
            [null, true, '8', '23', null, null],
            ['5', false, '101', '23', '5.00', 'channel'],
            [null, false, '101', '23', '23.00', 'default'],
        ];
    }

    public function test_missing_link_uses_source_name_and_default_vat_without_name_sku_or_ean_matching(): void
    {
        $account = $this->account($this->flags() + ['default_vat_rate' => '5']);
        $this->product(['name' => 'Nazwa Von Halsky', 'ean' => '123']);
        $preview = $this->preview($account);
        $this->assertNotEmpty($preview['catalog_warnings']);
        $this->assertNull($preview['items'][0]['product_id']);
        $this->assertSame('Nazwa Von Halsky', $preview['items'][0]['product_name']);
        $this->assertSame('5.00', $preview['items'][0]['vat_rate']);
        $this->post(route('integrations.von-halsky.import'), $this->payload($preview['source_hash']))->assertSessionHasNoErrors();
        $this->assertDatabaseCount('orders', 1);
    }

    public function test_other_organization_or_environment_links_are_not_used(): void
    {
        $account = $this->account($this->flags());
        $link = $this->link($account, $this->product());
        foreach ([['organization_id' => 'c8ec11ce-12ba-4fb7-be9a-249e679f4e67'], ['environment' => 'stage']] as $change) {
            $link->update(['organization_id' => $account->organization_id, 'environment' => 'production', ...$change]);
            $this->assertNull(app(VonHalskyOrderPreviewService::class)->preview($account, 'PG76P11')['import']['items'][0]['product_id']);
        }
    }

    public function test_bundle_is_one_item_with_frozen_components_and_weight_but_no_stock_change(): void
    {
        $account = $this->account($this->flags());
        $bundle = $this->bundle();
        $this->link($account, $bundle);
        $preview = $this->preview($account);
        $this->assertSame('2.345', $preview['items'][0]['weight']);
        $this->post(route('integrations.von-halsky.import'), $this->payload($preview['source_hash']))->assertSessionHasNoErrors();
        $order = Order::query()->sole();
        $item = $order->items()->sole();
        $this->assertSame($bundle->id, $item->product_id);
        $this->assertSame('2.345', $item->weight);
        $this->assertSame(2, $item->bundleComponents()->count());
        $snapshot = $item->bundleComponents->toArray();
        $this->assertDatabaseCount('product_stock_movements', 0);
        $this->assertSame([10, 10], $bundle->bundleComponents->map(fn ($c) => $c->component->stock_quantity)->all());
        $bundle->update(['name' => 'Nowa nazwa', 'vat_rate' => '23', 'package_weight_kg' => '9']);
        $bundle->bundleComponents()->first()->update(['quantity' => 5]);
        $this->post(route('integrations.von-halsky.import'), $this->payload($preview['source_hash']))->assertSessionHasNoErrors();
        $this->assertDatabaseCount('orders', 1);
        $this->assertSame($snapshot, $item->fresh()->bundleComponents->toArray());
        $this->assertSame('Nazwa OMS', $item->fresh()->product_name);
        $this->assertSame('8.00', $item->fresh()->vat_rate);
        $this->assertSame('2.345', $item->fresh()->weight);
        Http::assertSentCount(2);
    }

    public function test_adding_link_later_never_rebinds_or_changes_a_historical_unlinked_order(): void
    {
        $account = $this->account();
        $preview = $this->preview($account);
        $this->post(route('integrations.von-halsky.import'), $this->payload($preview['source_hash']))->assertSessionHasNoErrors();
        $order = Order::query()->sole();
        $before = $order->items()->sole()->getAttributes();
        $this->link($account, $this->product());
        $account->update($this->flags());
        $this->post(route('integrations.von-halsky.import'), $this->payload($preview['source_hash']))->assertSessionHasNoErrors();
        $this->assertSame($before, $order->items()->sole()->getAttributes());
        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('order_events', 1);
        Http::assertSentCount(2);
    }

    public function test_deleting_linked_catalog_product_keeps_imported_name_vat_weight_and_bundle_history(): void
    {
        $account = $this->account($this->flags());
        $bundle = $this->bundle();
        $this->link($account, $bundle);
        $preview = $this->preview($account);
        $this->post(route('integrations.von-halsky.import'), $this->payload($preview['source_hash']))->assertSessionHasNoErrors();
        $item = Order::query()->sole()->items()->sole();
        $bundle->delete();
        $this->assertSame('Nazwa OMS', $item->fresh()->product_name);
        $this->assertSame('8.00', $item->fresh()->vat_rate);
        $this->assertSame('2.345', $item->fresh()->weight);
        $this->assertSame(2, $item->fresh()->bundleComponents()->count());
        $this->assertDatabaseCount('von_halsky_product_links', 0);
        $this->assertDatabaseCount('von_halsky_order_links', 1);
    }

    public function test_missing_domestic_context_does_not_apply_catalog_or_default_vat(): void
    {
        $account = $this->account($this->flags() + ['default_vat_rate' => '23']);
        $this->link($account, $this->product());
        $preview = app(VonHalskyOrderPreviewService::class)->preview($account, 'PG76P11')['import'];
        $preview['attributes']['billing_country_code'] = null;
        $item = app(VonHalskyOrderCatalogService::class)->apply($account, $preview)['items'][0];
        $this->assertNull($item['vat_rate']);
    }

    #[DataProvider('catalogChanges')]
    public function test_catalog_changes_after_preview_require_new_confirmation(string $change): void
    {
        $account = $this->account($this->flags());
        $product = $this->bundle();
        $link = $this->link($account, $product);
        $preview = $this->preview($account);
        match ($change) {
            'name' => $product->update(['name' => 'Zmieniono']),
            'vat' => $product->update(['vat_rate' => '23']),
            'weight' => $product->update(['package_weight_kg' => '9.123']),
            'components' => $product->bundleComponents()->first()->update(['quantity' => 5]),
            'component_name' => $product->bundleComponents()->first()->component->update(['name' => 'Zmieniony skladnik']),
            'link' => $link->update(['product_id' => $this->product()->id]),
            'unlink' => $link->delete(),
            'delete_product' => $product->delete(),
        };
        $this->post(route('integrations.von-halsky.import'), $this->payload($preview['source_hash']))->assertSessionHasErrors('von_halsky_import');
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('order_events', 0);
    }

    public static function catalogChanges(): array
    {
        return array_map(fn ($change) => [$change], ['name', 'vat', 'weight', 'components', 'component_name', 'link', 'unlink', 'delete_product']);
    }

    public function test_catalog_is_rechecked_under_locks_after_network_and_before_order_creation(): void
    {
        $account = $this->account($this->flags());
        $product = $this->product();
        $this->link($account, $product);
        $preview = $this->preview($account);
        app()->instance(VonHalskyOrderCatalogService::class, new class(app(OrderImportVatResolver::class), app(OrderItemBundleSnapshotService::class), $product) extends VonHalskyOrderCatalogService
        {
            public function __construct(OrderImportVatResolver $vat, OrderItemBundleSnapshotService $bundles, private Product $product)
            {
                parent::__construct($vat, $bundles);
            }

            public function apply(VonHalskyAccount $account, array $preview, bool $lock = false): array
            {
                if ($lock) {
                    $this->product->update(['vat_rate' => '23']);
                }

                return parent::apply($account, $preview, $lock);
            }
        });
        $this->post(route('integrations.von-halsky.import'), $this->payload($preview['source_hash']))->assertSessionHasErrors('von_halsky_import');
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_price_and_stock_changes_do_not_reprice_or_invalidate_source_order(): void
    {
        $account = $this->account($this->flags());
        $product = $this->product();
        $this->link($account, $product);
        $preview = $this->preview($account);
        $product->update(['price_gross' => '10', 'stock_quantity' => 100]);
        $this->post(route('integrations.von-halsky.import'), $this->payload($preview['source_hash']))->assertSessionHasNoErrors();
        $this->assertSame('195.00', Order::query()->sole()->total_gross);
    }

    public function test_invalid_linked_bundle_blocks_import_but_disabling_links_preserves_source_import(): void
    {
        $account = $this->account($this->flags());
        $this->link($account, $this->product(['item_type' => 'bundle']));
        $preview = $this->preview($account);
        $this->assertNotEmpty($preview['catalog_blockers']);
        $this->post(route('integrations.von-halsky.import'), $this->payload($preview['source_hash']))->assertSessionHasErrors('von_halsky_import');
        $account->update(['use_linked_products' => false]);
        $preview = $this->preview($account);
        $this->assertSame([], $preview['catalog_blockers']);
        $this->post(route('integrations.von-halsky.import'), $this->payload($preview['source_hash']))->assertSessionHasNoErrors();
        $this->assertNull(Order::query()->sole()->items()->sole()->product_id);
    }

    public function test_migration_will_not_drop_in_use_settings_or_links(): void
    {
        $this->account($this->flags());
        $migration = require database_path('migrations/2026_10_29_030000_add_von_halsky_product_links.php');
        $this->expectException(\RuntimeException::class);
        $migration->down();
    }

    private function flags(): array
    {
        return ['use_linked_products' => true, 'use_catalog_product_names' => true, 'use_catalog_vat' => true];
    }

    private function bundle(): Product
    {
        $bundle = $this->product(['item_type' => 'bundle', 'package_weight_kg' => '2.345']);
        foreach ([2, 3] as $quantity) {
            $bundle->bundleComponents()->create(['component_product_id' => $this->product()->id, 'quantity' => $quantity]);
        }

        return $bundle;
    }

    private function preview(VonHalskyAccount $account): array
    {
        return $this->post(route('integrations.von-halsky.preview'), ['expected_lock_version' => $account->lock_version, 'order_identifier' => 'PG76P11'])
            ->assertOk()->viewData('orderPreview')['import'];
    }

    private function payload(string $hash): array
    {
        return ['order_identifier' => 'PG76P11', 'preview_hash' => $hash, 'quantities' => [1],
            'confirmed_quantities' => '1'];
    }
}
