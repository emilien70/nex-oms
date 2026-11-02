<?php

namespace Tests\Feature\Products;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Modules\Integrations\Allegro\Models\AllegroAccount;
use Modules\Integrations\Prestashop\Models\PrestashopAccount;
use Modules\Integrations\VonHalsky\Models\VonHalskyProductLink;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\VonHalsky\CatalogFixtures;
use Tests\TestCase;

class VonHalskyProductLinkTest extends TestCase
{
    use CatalogFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Http::fake([]);
    }

    public function test_crud_and_multiple_offers_share_existing_modal_and_keep_all_integrations_after_ajax(): void
    {
        $account = $this->account();
        $product = $this->product();
        $shop = PrestashopAccount::query()->create(['account_key' => 'default', 'name' => 'Sklep', 'base_url' => 'https://shop.invalid', 'is_active' => true]);
        $allegro = AllegroAccount::query()->create(['account_key' => 'default', 'name' => 'Allegro', 'environment' => 'production', 'allegro_user_id' => '123',
            'client_id' => 'TEST', 'client_secret' => 'TEST', 'last_test_status' => 'success',
            'is_active' => true, 'access_token' => 'TEST', 'refresh_token' => 'TEST', 'access_token_expires_at' => now()->addHour(),
            'granted_scopes' => AllegroAccount::REQUIRED_SCOPES]);
        $this->postJson(route('products.integrations.prestashop.store', [$product, $shop]), ['external_product_id' => 12])->assertOk();
        $this->postJson(route('products.integrations.allegro.store', [$product, $allegro]), ['external_offer_id' => '123'])->assertOk();
        $url = route('products.integrations.von-halsky.store', [$product, $account]);
        $this->postJson($url, $this->payload('offer-1'))->assertOk()->assertJsonPath('link.external_offer_id', 'offer-1')
            ->assertJsonPath('link.offer_url', null)->assertJsonPath('html', fn ($html) => str_contains($html, 'Sklep') && str_contains($html, '(Allegro)'));
        $link = VonHalskyProductLink::query()->sole();
        $this->postJson($url, $this->payload('offer-2'))->assertOk();
        $this->patchJson(route('products.integrations.von-halsky.update', [$product, $account, $link]), $this->payload('offer-3'))->assertOk();
        $this->get(route('products.index'))->assertOk()->assertSee('multi-click (InPost Von Halsky)')
            ->assertSee('Liczba powiązań: 2')->assertSee('data-integration-halsky-store-url', false);
        $this->postJson(route('products.integrations.prestashop.store', [$product, $shop]), ['external_product_id' => 13])->assertOk()
            ->assertJsonPath('html', fn ($html) => str_contains($html, 'offer-3') && str_contains($html, '(Allegro)'));
        $this->postJson(route('products.integrations.allegro.store', [$product, $allegro]), ['external_offer_id' => '124'])->assertOk()
            ->assertJsonPath('html', fn ($html) => str_contains($html, 'offer-3') && str_contains($html, '(PrestaShop)'));
        $this->deleteJson(route('products.integrations.von-halsky.destroy', [$product, $account, $link]), ['expected_connection_revision' => 0])
            ->assertOk()->assertJsonPath('html', fn ($html) => str_contains($html, 'offer-2') && ! str_contains($html, 'offer-3'));
        $this->assertDatabaseCount('von_halsky_product_links', 1);
        Http::assertNothingSent();
    }

    public function test_bundle_uses_same_identifier_pool_and_offer_cannot_be_reassigned_implicitly(): void
    {
        $account = $this->account();
        $product = $this->product();
        $bundle = $this->product(['item_type' => 'bundle']);
        $this->postJson(route('products.integrations.von-halsky.store', [$bundle, $account]), $this->payload())->assertOk();
        foreach ([$product, $bundle] as $target) {
            $this->postJson(route('products.integrations.von-halsky.store', [$target, $account]), $this->payload())
                ->assertUnprocessable()->assertJsonValidationErrors('external_offer_id');
        }
        $this->get(route('products.index', ['tab' => 'bundles']))->assertOk()->assertSee('Liczba powiązań: 1');
        $this->expectException(UniqueConstraintViolationException::class);
        $this->link($account, $product);
    }

    #[DataProvider('invalidIds')]
    public function test_invalid_identifiers_are_rejected(mixed $id): void
    {
        $this->postJson(route('products.integrations.von-halsky.store', [$this->product(), $this->account()]), $this->payload($id))
            ->assertUnprocessable()->assertJsonValidationErrors('external_offer_id');
        $this->assertDatabaseCount('von_halsky_product_links', 0);
        Http::assertNothingSent();
    }

    public static function invalidIds(): array
    {
        return array_map(fn ($id) => [$id], [null, '', 123, ['x'], 'https://evil.invalid', '<script>', 'a/b', 'a b', str_repeat('a', 256)]);
    }

    public function test_current_product_account_and_identity_are_required_for_mutations(): void
    {
        $account = $this->account();
        $otherAccount = $this->account(['account_key' => 'other', 'organization_id' => 'c8ec11ce-12ba-4fb7-be9a-249e679f4e67']);
        $product = $this->product();
        $other = $this->product();
        $link = $this->link($account, $product);
        foreach ([[$other, $account, $link], [$product, $otherAccount, $link]] as $parameters) {
            $this->patchJson(route('products.integrations.von-halsky.update', $parameters), $this->payload('changed'))->assertNotFound();
            $this->deleteJson(route('products.integrations.von-halsky.destroy', $parameters), ['expected_connection_revision' => 0])->assertNotFound();
        }
        $account->update(['environment' => 'stage', 'connection_revision' => 1]);
        $this->postJson(route('products.integrations.von-halsky.store', [$other, $account]), $this->payload())->assertUnprocessable()->assertJsonValidationErrors('integration');
        $this->patchJson(route('products.integrations.von-halsky.update', [$product, $account, $link]), $this->payload('changed', 1))->assertNotFound();
        $this->deleteJson(route('products.integrations.von-halsky.destroy', [$product, $account, $link]), ['expected_connection_revision' => 1])->assertNotFound();
        $this->postJson(route('products.integrations.von-halsky.store', [$other, $account]), $this->payload('offer-test', 1))->assertOk();
        $this->get(route('products.index'))->assertOk()->assertSee('Liczba powiązań: 1')->assertDontSee('Liczba powiązań: 2');
        Http::assertNothingSent();
    }

    public function test_disconnected_account_cannot_add_link_and_deletion_does_not_delete_products(): void
    {
        $account = $this->account(['is_active' => false]);
        $product = $this->product();
        $this->postJson(route('products.integrations.von-halsky.store', [$product, $account]), $this->payload())->assertUnprocessable();
        $link = $this->link($account, $product);
        $this->get(route('products.index'))->assertOk()->assertDontSee('data-integration-choice data-integration-type="von-halsky"', false);
        $this->deleteJson(route('products.integrations.von-halsky.destroy', [$product, $account, $link]), ['expected_connection_revision' => 0])->assertOk();
        $this->link($account, $product);
        $account->delete();
        $this->assertDatabaseCount('von_halsky_product_links', 0);
        $this->assertDatabaseHas('products', ['id' => $product->id]);
        $account = $this->account();
        $this->link($account, $product);
        $product->delete();
        $this->assertDatabaseCount('von_halsky_product_links', 0);
        $this->assertDatabaseHas('von_halsky_accounts', ['id' => $account->id]);
    }

    private function payload(mixed $id = 'offer-test', int $revision = 0): array
    {
        return ['external_offer_id' => $id, 'expected_connection_revision' => $revision];
    }
}
