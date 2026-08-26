<?php

namespace Tests\Unit;

use App\Support\AddressLineFormatter;
use PHPUnit\Framework\TestCase;

class AddressLineFormatterTest extends TestCase
{
    public function test_it_formats_address_line_with_apartment_number(): void
    {
        $this->assertSame('Testowa 12/6', AddressLineFormatter::formatAddressLine('Testowa', '12', '6'));
    }

    public function test_it_parses_address_line_with_apartment_number(): void
    {
        $this->assertSame([
            'street' => 'Testowa',
            'building_number' => '12',
            'apartment_number' => '6',
        ], AddressLineFormatter::parseAddressLine('Testowa 12/6'));
    }

    public function test_it_parses_address_line_without_apartment_number(): void
    {
        $this->assertSame([
            'street' => 'Testowa',
            'building_number' => '12',
            'apartment_number' => null,
        ], AddressLineFormatter::parseAddressLine('Testowa 12'));
    }

    public function test_it_parses_multi_word_street_name(): void
    {
        $this->assertSame([
            'street' => 'Aleja Jana Pawla II',
            'building_number' => '10',
            'apartment_number' => '4',
        ], AddressLineFormatter::parseAddressLine('Aleja Jana Pawla II 10/4'));
    }

    public function test_it_parses_building_and_apartment_when_gus_does_not_return_a_street(): void
    {
        $this->assertSame([
            'street' => null,
            'building_number' => '12A',
            'apartment_number' => '3',
        ], AddressLineFormatter::parseAddressLine('12A/3'));
    }

    public function test_it_parses_postal_city_with_multi_word_city(): void
    {
        $this->assertSame([
            'postal_code' => '00-001',
            'city' => 'Nowy Dwor Mazowiecki',
        ], AddressLineFormatter::parsePostalCity('00-001 Nowy Dwor Mazowiecki'));
    }
}
