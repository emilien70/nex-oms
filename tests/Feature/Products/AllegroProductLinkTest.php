<?php

namespace Tests\Feature\Products;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Modules\Integrations\Allegro\Models\AllegroAccount;
use Modules\Integrations\Allegro\Models\AllegroProductLink;
use Modules\Integrations\Prestashop\Models\PrestashopAccount;
use Modules\Products\Models\Product;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AllegroProductLinkTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    public function test_one_product_has_multiple_offers_and_both_integration_types_survive_ajax_updates(): void
    {
        $account = $this->account();
        $shop = PrestashopAccount::query()->create(['account_key' => 'default', 'name' => 'multi-click.pl', 'base_url' => 'https://multi-click.pl', 'is_active' => true]);
        $product = $this->product();
        $this->postJson(route('products.integrations.prestashop.store', [$product, $shop]), ['external_product_id' => 96])->assertOk();
        $url = route('products.integrations.allegro.store', [$product, $account]);
        $this->postJson($url, ['external_offer_id' => '12345678901'])->assertOk()
            ->assertJsonPath('link.external_offer_id', '12345678901')
            ->assertJsonPath('link.offer_url', 'https://allegro.pl/oferta/-12345678901')
            ->assertJsonPath('html', fn ($html) => str_contains($html, 'data-integration-type="prestashop"') && str_contains($html, 'data-integration-type="allegro"'));
        $first = AllegroProductLink::query()->sole();
        $this->postJson($url, ['external_offer_id' => '99999999999999999999'])->assertOk();
        $this->assertDatabaseCount('allegro_product_links', 2);
        $this->assertSame('99999999999999999999', AllegroProductLink::query()->latest('id')->first()->external_offer_id);
        $this->patchJson(route('products.integrations.allegro.update', [$product, $account, $first]), ['external_offer_id' => '12345678902'])->assertOk();
        $this->get(route('products.index'))->assertOk()->assertSee('multi-click (Allegro)')->assertSee('multi-click.pl (PrestaShop)')
            ->assertSee('Liczba powiązań: 2')->assertSee('data-external-product-id="12345678902"', false)
            ->assertSee('data-integration-allegro-store-url', false)->assertSee('data-integration-id-label', false);
        $this->postJson(route('products.integrations.prestashop.store', [$product, $shop]), ['external_product_id' => 97])->assertOk()
            ->assertJsonPath('html', fn ($html) => str_contains($html, '12345678902') && str_contains($html, 'data-integration-type="allegro"'));
        $this->deleteJson(route('products.integrations.allegro.destroy', [$product, $account, $first]))->assertOk()
            ->assertJsonPath('html', fn ($html) => str_contains($html, '99999999999999999999') && str_contains($html, 'multi-click.pl'));
        $this->assertDatabaseCount('allegro_product_links', 1);
        $this->assertDatabaseCount('prestashop_product_links', 2);
        Http::assertNothingSent();
    }

    public function test_bundle_uses_the_same_linking_dialog_and_identifier_pool(): void
    {
        $account = $this->account();
        $bundle = $this->product(['item_type' => 'bundle', 'name' => 'Zestaw z Wi-Fi']);
        $this->postJson(route('products.integrations.allegro.store', [$bundle, $account]), ['external_offer_id' => '12345678901'])->assertOk();
        $this->get(route('products.index', ['tab' => 'bundles']))->assertOk()->assertSee('Zestaw z Wi-Fi')->assertSee('multi-click (Allegro)')->assertSee('Liczba powiązań: 1');
    }

    public function test_duplicate_offer_cannot_be_added_to_the_same_or_another_product_or_bundle(): void
    {
        $account = $this->account();
        $first = $this->product();
        $second = $this->product(['item_type' => 'bundle']);
        $this->postJson(route('products.integrations.allegro.store', [$first, $account]), ['external_offer_id' => '123'])->assertOk();
        foreach ([$first, $second] as $product) {
            $this->postJson(route('products.integrations.allegro.store', [$product, $account]), ['external_offer_id' => '123'])
                ->assertUnprocessable()->assertJsonValidationErrors('external_offer_id');
        }
        $this->postJson(route('products.integrations.allegro.store', [$second, $account]), ['external_offer_id' => '456'])->assertOk();
        $link = AllegroProductLink::query()->where('external_offer_id', '456')->sole();
        $this->patchJson(route('products.integrations.allegro.update', [$second, $account, $link]), ['external_offer_id' => '123'])
            ->assertUnprocessable()->assertJsonValidationErrors('external_offer_id');
        $this->assertSame('456', $link->fresh()->external_offer_id);
    }

    #[DataProvider('invalidIds')]
    public function test_invalid_ids_are_rejected_without_http(mixed $value): void
    {
        $this->postJson(route('products.integrations.allegro.store', [$this->product(), $this->account()]), ['external_offer_id' => $value])
            ->assertUnprocessable()->assertJsonValidationErrors('external_offer_id');
        $this->assertDatabaseCount('allegro_product_links', 0);
        Http::assertNothingSent();
    }

    public static function invalidIds(): array
    {
        return array_map(fn ($value) => [$value], [null, '', '0', '0123', '-1', '1.5', '1e10', 'https://allegro.pl/oferta/-123', '<script>', str_repeat('9', 33), ['123'], 123]);
    }

    public function test_inactive_or_disconnected_account_cannot_create_links(): void
    {
        $account = $this->account(['is_active' => false]);
        $product = $this->product();
        $this->postJson(route('products.integrations.allegro.store', [$product, $account]), ['external_offer_id' => '123'])
            ->assertUnprocessable()->assertJsonValidationErrors('integration');
        $this->get(route('products.index'))->assertOk()->assertDontSee('data-account-label="multi-click (Allegro)"', false);
    }

    public function test_link_cannot_be_changed_through_another_product_account_or_seller_identity(): void
    {
        $account = $this->account();
        $other = $this->account(['account_key' => 'other', 'allegro_user_id' => 'other-seller']);
        $product = $this->product();
        $wrongProduct = $this->product();
        $this->postJson(route('products.integrations.allegro.store', [$product, $account]), ['external_offer_id' => '123'])->assertOk();
        $link = AllegroProductLink::query()->sole();
        foreach ([[$wrongProduct, $account, $link], [$product, $other, $link]] as $parameters) {
            $this->patchJson(route('products.integrations.allegro.update', $parameters), ['external_offer_id' => '456'])->assertNotFound();
            $this->deleteJson(route('products.integrations.allegro.destroy', $parameters))->assertNotFound();
        }
        $account->update(['allegro_user_id' => 'changed-seller']);
        $this->patchJson(route('products.integrations.allegro.update', [$product, $account, $link]), ['external_offer_id' => '456'])->assertNotFound();
        $this->get(route('products.index'))->assertOk()->assertDontSee('data-external-product-id="123"', false);
        $this->postJson(route('products.integrations.allegro.store', [$wrongProduct, $account]), ['external_offer_id' => '123'])->assertOk();
        $this->assertDatabaseCount('allegro_product_links', 2);
    }

    public function test_sandbox_links_are_separate_and_use_sandbox_url(): void
    {
        $account = $this->account();
        $product = $this->product();
        $this->postJson(route('products.integrations.allegro.store', [$product, $account]), ['external_offer_id' => '123'])->assertOk();
        $account->update(['environment' => 'sandbox']);
        $this->postJson(route('products.integrations.allegro.store', [$product, $account]), ['external_offer_id' => '123'])->assertOk()
            ->assertJsonPath('link.offer_url', 'https://allegro.pl.allegrosandbox.pl/oferta/-123');
        $this->assertDatabaseCount('allegro_product_links', 2);
        $this->get(route('products.index'))->assertOk()->assertSee('Liczba powiązań: 1');
    }

    public function test_deleting_configuration_removes_links_but_not_products_or_other_integrations(): void
    {
        $account = $this->account();
        $product = $this->product();
        $this->postJson(route('products.integrations.allegro.store', [$product, $account]), ['external_offer_id' => '123'])->assertOk();
        $account->delete();
        $this->assertDatabaseCount('allegro_product_links', 0);
        $this->assertDatabaseHas('products', ['id' => $product->id]);
        $account = $this->account();
        $this->postJson(route('products.integrations.allegro.store', [$product, $account]), ['external_offer_id' => '123'])->assertOk();
        $product->delete();
        $this->assertDatabaseCount('allegro_product_links', 0);
        $this->assertDatabaseHas('allegro_accounts', ['id' => $account->id]);
    }

    private function account(array $attributes = []): AllegroAccount
    {
        return AllegroAccount::query()->create(array_merge([
            'account_key' => 'default', 'name' => 'multi-click', 'environment' => 'production',
            'client_id' => 'client', 'client_secret' => 'secret', 'access_token' => 'token', 'refresh_token' => 'refresh',
            'access_token_expires_at' => now()->addHours(5), 'granted_scopes' => AllegroAccount::REQUIRED_SCOPES,
            'allegro_user_id' => '12345678', 'is_active' => true, 'last_test_status' => 'success',
        ], $attributes))->refresh();
    }

    private function product(array $attributes = []): Product
    {
        return Product::query()->create(array_merge(['name' => 'Dekoder OMS', 'price_gross' => '199.00', 'currency' => 'PLN', 'stock_quantity' => 10, 'is_active' => true], $attributes))->refresh();
    }
}
