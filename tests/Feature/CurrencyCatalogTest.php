<?php

namespace Tests\Feature;

use App\Models\Currency;
use App\Support\CurrencyCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CurrencyCatalogTest extends TestCase
{
    use RefreshDatabase;

    public function test_migration_and_model_define_minimal_currency_catalog_with_pln(): void
    {
        $this->assertSame(['code', 'name', 'nbp_table'], Schema::getColumnListing('currencies'));
        $this->assertDatabaseHas('currencies', [
            'code' => 'PLN',
            'name' => 'PLN',
            'nbp_table' => null,
        ]);

        $currency = Currency::query()->findOrFail('PLN');
        $this->assertSame('PLN', $currency->getKey());
        $this->assertFalse($currency->getIncrementing());
        $this->assertFalse($currency->usesTimestamps());
    }

    public function test_catalog_normalizes_validates_and_orders_pln_first_then_codes(): void
    {
        Currency::query()->insert([
            ['code' => 'USD', 'name' => 'dolar amerykański', 'nbp_table' => 'A'],
            ['code' => 'EUR', 'name' => 'euro', 'nbp_table' => 'A'],
        ]);

        $catalog = app(CurrencyCatalog::class);

        $this->assertSame('PLN', $catalog->normalize(' pln '));
        $this->assertNull($catalog->normalize('   '));
        $this->assertFalse($catalog->hasValidFormat('EU'));
        $this->assertFalse($catalog->hasValidFormat('E1R'));
        $this->assertFalse($catalog->hasValidFormat('E-R'));
        $this->assertTrue($catalog->exists('pln'));
        $this->assertFalse($catalog->exists('XXX'));
        $this->assertSame(['PLN', 'EUR', 'USD'], $catalog->codes());
        $this->assertCount(count(array_unique($catalog->codes())), $catalog->codes());
        foreach ($catalog->codes() as $code) {
            $this->assertMatchesRegularExpression('/^[A-Z]{3}$/', $code);
        }
        $this->assertSame([
            'PLN' => 'PLN',
            'EUR' => 'EUR',
            'USD' => 'USD',
        ], $catalog->all());
    }
}
