<?php

namespace Tests\Feature\Products;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Modules\Integrations\Prestashop\Models\PrestashopAccount;
use Modules\Integrations\Prestashop\Models\PrestashopProductLink;
use Modules\Products\Models\Product;
use Tests\TestCase;

class PrestashopProductLinkTest extends TestCase
{
    use RefreshDatabase;

    public function test_product_list_shows_integrations_instead_of_vat_and_shipping_columns(): void
    {
        $account = $this->account();
        $product = $this->product();
        $product->prestashopProductLinks()->create([
            'prestashop_account_id' => $account->getKey(),
            'external_product_id' => 96,
            'external_product_attribute_id' => 0,
        ]);
        $product->prestashopProductLinks()->create([
            'prestashop_account_id' => $account->getKey(),
            'external_product_id' => 97,
            'external_product_attribute_id' => 0,
        ]);

        $this->get(route('products.index'))
            ->assertOk()
            ->assertSee('Integracje')
            ->assertDontSee('>VAT<', false)
            ->assertDontSee('>Wysyłka<', false)
            ->assertSee('multi-click.pl (PrestaShop)')
            ->assertSee('Liczba powiązań: 2')
            ->assertSee('data-integration-list', false)
            ->assertSee('data-integration-link-record', false)
            ->assertSee('data-external-product-id="96"', false)
            ->assertSee('data-external-product-id="97"', false)
            ->assertSee('data-offer-url="https://multi-click.pl/index.php?controller=product&amp;id_product=96"', false)
            ->assertSee('data-integration-add', false)
            ->assertSee('data-integration-list-items', false);
    }

    public function test_active_prestashop_integration_can_have_multiple_links_and_one_can_be_updated(): void
    {
        $account = $this->account();
        $product = $this->product();

        $this->postJson(route('products.integrations.prestashop.store', [$product, $account]), [
            'external_product_id' => 96,
        ])
            ->assertOk()
            ->assertJsonPath('message', 'Powiązanie zostało dodane.')
            ->assertJsonPath('link.external_product_id', 96)
            ->assertJsonPath('html', fn (string $html): bool => str_contains(
                $html,
                'data-offer-url="https://multi-click.pl/index.php?controller=product&amp;id_product=96"',
            ));

        $firstLink = PrestashopProductLink::query()->sole();

        $this->assertSame($product->getKey(), $firstLink->product->getKey());
        $this->assertSame($account->getKey(), $firstLink->account->getKey());
        $this->assertSame(96, $firstLink->external_product_id);

        $this->postJson(route('products.integrations.prestashop.store', [$product, $account]), [
            'external_product_id' => 97,
        ])->assertOk();

        $secondLink = PrestashopProductLink::query()
            ->where('external_product_id', 97)
            ->sole();

        $this->assertDatabaseCount('prestashop_product_links', 2);

        $this->patchJson(
            route('products.integrations.prestashop.update', [$product, $account, $firstLink]),
            ['external_product_id' => 98],
        )
            ->assertOk()
            ->assertJsonPath('message', 'Powiązanie zostało zapisane.')
            ->assertJsonPath('link.id', $firstLink->getKey())
            ->assertJsonPath('link.external_product_id', 98);

        $this->assertDatabaseHas('prestashop_product_links', [
            'id' => $firstLink->getKey(),
            'product_id' => $product->getKey(),
            'prestashop_account_id' => $account->getKey(),
            'external_product_id' => 98,
            'external_product_attribute_id' => 0,
        ]);
        $this->assertDatabaseHas('prestashop_product_links', [
            'id' => $secondLink->getKey(),
            'external_product_id' => 97,
        ]);
    }

    public function test_external_product_cannot_be_linked_to_two_oms_products(): void
    {
        $account = $this->account();
        $firstProduct = $this->product(['name' => 'Pierwszy produkt']);
        $secondProduct = $this->product(['name' => 'Drugi produkt']);

        $this->postJson(route('products.integrations.prestashop.store', [$firstProduct, $account]), [
            'external_product_id' => 96,
        ])->assertOk();

        $this->postJson(route('products.integrations.prestashop.store', [$secondProduct, $account]), [
            'external_product_id' => 96,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('external_product_id');

        $this->assertDatabaseCount('prestashop_product_links', 1);
    }

    public function test_same_external_product_cannot_be_added_twice_to_one_oms_product(): void
    {
        $account = $this->account();
        $product = $this->product();

        $this->postJson(route('products.integrations.prestashop.store', [$product, $account]), [
            'external_product_id' => 96,
        ])->assertOk();

        $this->postJson(route('products.integrations.prestashop.store', [$product, $account]), [
            'external_product_id' => 96,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('external_product_id')
            ->assertJsonPath(
                'errors.external_product_id.0',
                'Ta oferta PrestaShop jest już powiązana z tą pozycją OMS.',
            );

        $this->assertDatabaseCount('prestashop_product_links', 1);
    }

    public function test_link_cannot_be_edited_through_another_product(): void
    {
        $account = $this->account();
        $owner = $this->product(['name' => 'Właściciel powiązania']);
        $otherProduct = $this->product(['name' => 'Inny produkt']);
        $link = $owner->prestashopProductLinks()->create([
            'prestashop_account_id' => $account->getKey(),
            'external_product_id' => 96,
            'external_product_attribute_id' => 0,
        ]);

        $this->patchJson(
            route('products.integrations.prestashop.update', [$otherProduct, $account, $link]),
            ['external_product_id' => 97],
        )->assertNotFound();

        $this->assertDatabaseHas('prestashop_product_links', [
            'id' => $link->getKey(),
            'product_id' => $owner->getKey(),
            'external_product_id' => 96,
        ]);
    }

    public function test_invalid_id_and_inactive_integration_are_rejected(): void
    {
        $activeAccount = $this->account();
        $inactiveAccount = $this->account([
            'account_key' => 'inactive',
            'name' => 'Nieaktywna integracja',
            'is_active' => false,
        ]);
        $product = $this->product();

        $this->postJson(route('products.integrations.prestashop.store', [$product, $activeAccount]), [
            'external_product_id' => 0,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('external_product_id');

        $this->postJson(route('products.integrations.prestashop.store', [$product, $inactiveAccount]), [
            'external_product_id' => 96,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('integration');

        $this->assertDatabaseCount('prestashop_product_links', 0);
    }

    public function test_link_can_be_deleted_without_deleting_product_or_integration(): void
    {
        $account = $this->account();
        $product = $this->product();
        $link = $product->prestashopProductLinks()->create([
            'prestashop_account_id' => $account->getKey(),
            'external_product_id' => 96,
            'external_product_attribute_id' => 0,
        ]);

        $this->deleteJson(route('products.integrations.prestashop.destroy', [$product, $account, $link]))
            ->assertOk()
            ->assertJsonPath('message', 'Powiązanie zostało usunięte.');

        $this->assertDatabaseMissing('prestashop_product_links', ['id' => $link->getKey()]);
        $this->assertDatabaseHas('products', ['id' => $product->getKey()]);
        $this->assertDatabaseHas('prestashop_accounts', ['id' => $account->getKey()]);
    }

    public function test_schema_and_cascade_keep_product_links_consistent(): void
    {
        $this->assertTrue(Schema::hasColumns('prestashop_product_links', [
            'product_id',
            'prestashop_account_id',
            'external_product_id',
            'external_product_attribute_id',
        ]));

        $account = $this->account();
        $product = $this->product();
        $link = $product->prestashopProductLinks()->create([
            'prestashop_account_id' => $account->getKey(),
            'external_product_id' => 96,
            'external_product_attribute_id' => 0,
        ]);

        $product->delete();

        $this->assertDatabaseMissing('prestashop_product_links', ['id' => $link->getKey()]);
        $this->assertDatabaseHas('prestashop_accounts', ['id' => $account->getKey()]);
    }

    /** @param array<string, mixed> $overrides */
    private function account(array $overrides = []): PrestashopAccount
    {
        return PrestashopAccount::query()->create(array_merge([
            'account_key' => PrestashopAccount::DEFAULT_ACCOUNT_KEY,
            'name' => 'multi-click.pl',
            'base_url' => 'https://multi-click.pl',
            'api_key' => '12345678901234567890123456789012',
            'is_active' => true,
        ], $overrides));
    }

    /** @param array<string, mixed> $overrides */
    private function product(array $overrides = []): Product
    {
        return Product::query()->create(array_merge([
            'name' => 'Produkt testowy',
            'price_gross' => '99.99',
            'currency' => 'PLN',
            'vat_rate' => '23.00',
            'stock_quantity' => 10,
            'is_active' => true,
        ], $overrides));
    }
}
