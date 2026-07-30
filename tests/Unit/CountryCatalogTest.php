<?php

namespace Tests\Unit;

use App\Support\CountryCatalog;
use PHPUnit\Framework\TestCase;

class CountryCatalogTest extends TestCase
{
    public function test_catalog_uses_polish_names_and_normalizes_codes(): void
    {
        $catalog = new CountryCatalog;

        $this->assertSame('Polska', $catalog->name('PL'));
        $this->assertSame('Niemcy', $catalog->name('DE'));
        $this->assertSame('PL', $catalog->normalize(' pl '));
        $this->assertTrue($catalog->exists('pl'));
        $this->assertFalse($catalog->exists('XX'));
        $this->assertNull($catalog->name('XX'));
        $this->assertNull($catalog->normalize(null));
        $this->assertNull($catalog->normalize('   '));
        $this->assertFalse($catalog->exists(null));
        $this->assertNull($catalog->name(''));
    }

    public function test_poland_is_first_and_country_codes_are_unique(): void
    {
        $catalog = new CountryCatalog;
        $countries = $catalog->all();
        $codes = $catalog->codes();

        $this->assertSame('PL', array_key_first($countries));
        $this->assertSame('Polska', $countries['PL']);
        $this->assertCount(count(array_unique($codes)), $codes);

        foreach ($codes as $code) {
            $this->assertMatchesRegularExpression('/^[A-Z]{2}$/', $code);
        }
    }
}
