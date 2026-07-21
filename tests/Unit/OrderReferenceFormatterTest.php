<?php

namespace Tests\Unit;

use Modules\Shipments\Support\OrderReferenceFormatter;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class OrderReferenceFormatterTest extends TestCase
{
    #[DataProvider('references')]
    public function test_it_pads_short_order_ids_to_three_characters(int $orderId, string $expected): void
    {
        $this->assertSame($expected, OrderReferenceFormatter::format($orderId));
    }

    public static function references(): array
    {
        return [
            'one digit' => [1, '001'],
            'two digits' => [12, '012'],
            'three digits' => [123, '123'],
            'more than three digits' => [1234, '1234'],
        ];
    }
}
