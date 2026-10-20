<?php

namespace Tests\Unit;

use Modules\Integrations\Allegro\Support\AllegroOrderUrl;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class AllegroOrderUrlTest extends TestCase
{
    #[DataProvider('orderUrls')]
    public function test_only_valid_order_identifiers_and_known_environments_have_links(string $environment, ?string $id, ?string $expected): void
    {
        $this->assertSame($expected, AllegroOrderUrl::make($environment, $id));
    }

    public static function orderUrls(): array
    {
        $id = '21151530-cbf2-11f1-97d5-392e2c42e1bf';

        return [
            'production' => ['production', $id, 'https://salescenter.allegro.com/orders/'.$id],
            'sandbox' => ['sandbox', $id, 'https://salescenter.allegro.com.allegrosandbox.pl/orders/'.$id],
            'uppercase UUID' => ['production', strtoupper($id), 'https://salescenter.allegro.com/orders/'.$id],
            'unknown environment' => ['unknown', $id, null],
            'missing identifier' => ['production', null, null],
            'empty identifier' => ['production', '', null],
            'invalid identifier' => ['production', '12345', null],
            'unsafe identifier' => ['production', '../../settings', null],
        ];
    }
}
