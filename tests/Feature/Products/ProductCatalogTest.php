<?php

namespace Tests\Feature\Products;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Modules\Products\Models\Product;
use Tests\TestCase;

class ProductCatalogTest extends TestCase
{
    use RefreshDatabase;

    public function test_catalog_navigation_list_and_form_are_available(): void
    {
        $product = Product::query()->create($this->modelData([
            'name' => 'Produkt z numerem ID',
        ]));

        $this->get(route('products.index'))
            ->assertOk()
            ->assertSee('Lista produkt&oacute;w', false)
            ->assertSee('Dodaj produkt')
            ->assertSee('ID '.$product->getKey())
            ->assertDontSee('ID NEX')
            ->assertSee('Produktów na stronę')
            ->assertSee('Wyczyść filtry')
            ->assertSee(route('products.index', ['per_page' => '50']), false)
            ->assertSee(route('products.index'), false);

        $this->get(route('products.create'))
            ->assertOk()
            ->assertSee('Nowy produkt')
            ->assertSee('EAN')
            ->assertSee('Inna stawka')
            ->assertSee('Stan magazynowy')
            ->assertSee('Waga')
            ->assertSee('Długość');

        $this->get(route('products.edit', $product))
            ->assertOk()
            ->assertSee('ID '.$product->getKey())
            ->assertDontSee('ID NEX');
    }

    public function test_product_can_be_created_with_custom_vat_and_comma_decimals(): void
    {
        $this->post(route('products.store'), $this->payload([
            'name' => '  Dekoder testowy  ',
            'ean' => '590 123 456 789 0',
            'price_gross' => '129,99',
            'vat_rate_preset' => 'custom',
            'custom_vat_rate' => '7,50',
            'stock_quantity' => '-2',
            'package_weight_kg' => '1,250',
            'package_length_cm' => '24,50',
            'package_width_cm' => '18,25',
            'package_height_cm' => '6,00',
        ]))
            ->assertRedirect(route('products.edit', 1))
            ->assertSessionHas('success', 'Produkt został zapisany.');

        $product = Product::query()->sole();

        $this->assertSame(1, $product->getKey());
        $this->assertSame('Dekoder testowy', $product->name);
        $this->assertSame('5901234567890', $product->ean);
        $this->assertSame('129.99', $product->price_gross);
        $this->assertSame('7.50', $product->vat_rate);
        $this->assertSame(-2, $product->stock_quantity);
        $this->assertSame('1.250', $product->package_weight_kg);
        $this->assertSame('24.50', $product->package_length_cm);
        $this->assertSame('18.25', $product->package_width_cm);
        $this->assertSame('6.00', $product->package_height_cm);
        $this->assertSame('PLN', $product->currency);
        $this->assertTrue($product->is_active);
    }

    public function test_duplicate_ean_partial_dimensions_and_invalid_values_are_rejected(): void
    {
        Product::query()->create($this->modelData([
            'ean' => '5901234567890',
        ]));

        $this->from(route('products.create'))
            ->post(route('products.store'), $this->payload([
                'ean' => '5901234567890',
                'price_gross' => '12.999',
                'vat_rate_preset' => 'custom',
                'custom_vat_rate' => '100.01',
                'package_length_cm' => '10',
                'package_width_cm' => '',
                'package_height_cm' => '',
            ]))
            ->assertRedirect(route('products.create'))
            ->assertSessionHasErrors([
                'ean',
                'price_gross',
                'custom_vat_rate',
                'package_width_cm',
                'package_height_cm',
            ]);

        $this->assertDatabaseCount('products', 1);
    }

    public function test_product_image_and_thumbnail_are_private_and_can_be_served(): void
    {
        Storage::fake('local');

        $this->post(route('products.store'), $this->payload([
            'image' => UploadedFile::fake()->image('dekoder.png', 640, 480),
        ]))->assertRedirect(route('products.edit', 1));

        $product = Product::query()->sole();

        $this->assertNotNull($product->image_path);
        $this->assertNotNull($product->thumbnail_path);
        $this->assertSame('image/png', $product->image_mime_type);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) $product->image_checksum);
        Storage::disk('local')->assertExists($product->image_path);
        Storage::disk('local')->assertExists($product->thumbnail_path);

        $this->get(route('products.image', [$product, 'original']))
            ->assertOk()
            ->assertHeader('Content-Type', 'image/png');

        $this->get(route('products.image', [$product, 'thumbnail']))
            ->assertOk()
            ->assertHeader('Content-Type', 'image/jpeg');
    }

    public function test_image_can_be_replaced_and_removed_without_leaving_owned_files(): void
    {
        Storage::fake('local');

        $this->post(route('products.store'), $this->payload([
            'image' => UploadedFile::fake()->image('first.png', 400, 400),
        ]));

        $product = Product::query()->sole();
        $firstPaths = [$product->image_path, $product->thumbnail_path];

        $this->patch(route('products.update', $product), $this->payload([
            'name' => 'Produkt po zmianie',
            'image' => UploadedFile::fake()->image('second.jpg', 600, 500),
        ]))->assertRedirect(route('products.edit', $product));

        $product->refresh();
        $secondPaths = [$product->image_path, $product->thumbnail_path];

        $this->assertNotSame($firstPaths, $secondPaths);
        foreach ($firstPaths as $path) {
            Storage::disk('local')->assertMissing($path);
        }
        foreach ($secondPaths as $path) {
            Storage::disk('local')->assertExists($path);
        }

        $this->patch(route('products.update', $product), $this->payload([
            'name' => 'Produkt bez zdjęcia',
            'remove_image' => '1',
        ]))->assertRedirect(route('products.edit', $product));

        $product->refresh();
        $this->assertNull($product->image_path);
        $this->assertNull($product->thumbnail_path);
        $this->assertNull($product->image_mime_type);
        $this->assertNull($product->image_checksum);

        foreach ($secondPaths as $path) {
            Storage::disk('local')->assertMissing($path);
        }
    }

    public function test_invalid_image_is_rejected_without_writing_files(): void
    {
        Storage::fake('local');

        $this->from(route('products.create'))
            ->post(route('products.store'), $this->payload([
                'image' => UploadedFile::fake()->create('produkt.svg', 3, 'image/svg+xml'),
            ]))
            ->assertRedirect(route('products.create'))
            ->assertSessionHasErrors('image');

        $this->assertDatabaseCount('products', 0);
        $this->assertSame([], Storage::disk('local')->allFiles('products'));
    }

    public function test_product_can_be_hidden_and_reactivated(): void
    {
        $product = Product::query()->create($this->modelData());

        $this->patch(route('products.active', $product), ['is_active' => '0'])
            ->assertRedirect()
            ->assertSessionHas('success', 'Produkt został ukryty.');

        $this->assertFalse($product->refresh()->is_active);

        $this->patch(route('products.active', $product), ['is_active' => '1'])
            ->assertRedirect()
            ->assertSessionHas('success', 'Produkt został aktywowany.');

        $this->assertTrue($product->refresh()->is_active);
    }

    public function test_stock_quantity_can_be_updated_from_the_product_list_endpoint(): void
    {
        $product = Product::query()->create($this->modelData([
            'stock_quantity' => 12,
        ]));

        $this->get(route('products.index'))
            ->assertOk()
            ->assertSee(route('products.stock', $product), false)
            ->assertSee('data-stock-trigger', false)
            ->assertSee('data-stock-form', false);

        $this->patchJson(route('products.stock', $product), [
            'stock_quantity' => -7,
        ])
            ->assertOk()
            ->assertJson([
                'stock_quantity' => -7,
                'message' => 'Stan magazynowy został zapisany.',
            ]);

        $this->assertSame(-7, $product->refresh()->stock_quantity);
    }

    public function test_inline_stock_update_rejects_non_integer_and_out_of_range_values(): void
    {
        $product = Product::query()->create($this->modelData([
            'stock_quantity' => 12,
        ]));

        $this->patchJson(route('products.stock', $product), [
            'stock_quantity' => '1.5',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('stock_quantity');

        $this->patchJson(route('products.stock', $product), [
            'stock_quantity' => '2147483648',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('stock_quantity');

        $this->assertSame(12, $product->refresh()->stock_quantity);
    }

    public function test_list_filters_active_hidden_and_searches_by_name_id_and_ean(): void
    {
        $active = Product::query()->create($this->modelData([
            'name' => 'Dekoder Zgemma',
            'ean' => '5901234567890',
            'price_gross' => '99.90',
        ]));
        $hidden = Product::query()->create($this->modelData([
            'name' => 'Ukryty pilot',
            'ean' => '5900000000001',
            'is_active' => false,
        ]));

        $this->get(route('products.index'))
            ->assertOk()
            ->assertSee($active->name)
            ->assertSee('99,90 PLN')
            ->assertDontSee($hidden->name);

        $this->get(route('products.index', ['status' => 'inactive']))
            ->assertOk()
            ->assertSee($hidden->name)
            ->assertDontSee($active->name);

        $this->get(route('products.index', ['status' => 'all', 'q' => '5901234567890']))
            ->assertOk()
            ->assertSee($active->name)
            ->assertDontSee($hidden->name);

        $this->get(route('products.index', ['status' => 'all', 'q' => (string) $hidden->getKey()]))
            ->assertOk()
            ->assertSee($hidden->name);
    }

    public function test_list_supports_configurable_page_size_and_all_products(): void
    {
        foreach (range(1, 26) as $number) {
            Product::query()->create($this->modelData([
                'name' => sprintf('Produkt %02d', $number),
            ]));
        }

        $firstPage = $this->get(route('products.index', [
            'status' => 'all',
            'per_page' => '25',
        ]));

        $firstPage->assertOk();
        $this->assertSame(25, substr_count($firstPage->getContent(), 'data-product-row'));

        $secondPage = $this->get(route('products.index', [
            'status' => 'all',
            'per_page' => '25',
            'page' => 2,
        ]));

        $secondPage->assertOk();
        $this->assertSame(1, substr_count($secondPage->getContent(), 'data-product-row'));

        $allProducts = $this->get(route('products.index', [
            'status' => 'all',
            'per_page' => 'all',
        ]));

        $allProducts->assertOk();
        $this->assertSame(26, substr_count($allProducts->getContent(), 'data-product-row'));
    }

    public function test_image_route_refuses_a_path_outside_the_product_directory(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('other/image.jpg', 'not owned');

        $product = Product::query()->create($this->modelData([
            'image_path' => 'other/image.jpg',
            'image_mime_type' => 'image/jpeg',
        ]));

        $this->get(route('products.image', [$product, 'original']))->assertNotFound();
    }

    /** @param array<string, mixed> $overrides */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Produkt testowy',
            'ean' => '5901234567890',
            'price_gross' => '99.99',
            'currency' => 'PLN',
            'vat_rate_preset' => '23.00',
            'custom_vat_rate' => '',
            'stock_quantity' => '10',
            'package_weight_kg' => '',
            'package_length_cm' => '',
            'package_width_cm' => '',
            'package_height_cm' => '',
            'remove_image' => '0',
            'is_active' => '1',
        ], $overrides);
    }

    /** @param array<string, mixed> $overrides */
    private function modelData(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Produkt testowy',
            'ean' => null,
            'price_gross' => '99.99',
            'currency' => 'PLN',
            'vat_rate' => '23.00',
            'stock_quantity' => 10,
            'is_active' => true,
        ], $overrides);
    }
}
