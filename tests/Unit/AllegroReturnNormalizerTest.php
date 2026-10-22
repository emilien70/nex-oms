<?php

namespace Tests\Unit;

use Modules\Integrations\Allegro\Exceptions\AllegroApiException;
use Modules\Integrations\Allegro\Services\AllegroReturnNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\AllegroReturnFixture as Fixture;
use Tests\TestCase;

class AllegroReturnNormalizerTest extends TestCase
{
    public function test_exact_amounts_and_minimized_payload(): void
    {
        $raw = Fixture::payload();
        $raw['items'][0]['price']['amount'] = '0.03';
        $raw['items'][0]['quantity'] = 3;
        $data = app(AllegroReturnNormalizer::class)->normalize($raw);
        $this->assertSame('0.09', $data['total_value']);
        $this->assertSame('0.03', $data['items'][0]['unit_price']);
        $this->assertStringNotContainsString('PRIVATE-BANK', json_encode($data));
        $this->assertSame($data['source_hash'], app(AllegroReturnNormalizer::class)->normalize($raw)['source_hash']);
    }

    #[DataProvider('invalidPayloads')]
    public function test_invalid_payload_is_rejected(string $path, mixed $value): void
    {
        $raw = Fixture::payload();
        data_set($raw, $path, $value);
        $this->expectException(AllegroApiException::class);
        app(AllegroReturnNormalizer::class)->normalize($raw);
    }

    public static function invalidPayloads(): array
    {
        return [
            ['id', 'not-uuid'], ['orderId', ''], ['items', []], ['items.0', 'invalid'],
            ['items.0.price.amount', 30.01], ['items.0.price.amount', '-1.00'],
            ['items.0.price.amount', '1.001'], ['items.0.quantity', 0], ['items.0.quantity', 1.5],
            ['items.0.price.currency', 'pln'], ['items.0.name', ''], ['createdAt', '2026-02-30T10:00:00Z'],
            ['createdAt', '2026-10-21 10:00:00'], ['parcels', null], ['parcels.0', 'bad'],
        ];
    }

    public function test_mixed_currency_is_rejected(): void
    {
        $raw = Fixture::payload();
        $raw['items'][] = $raw['items'][0];
        $raw['items'][1]['price']['currency'] = 'EUR';
        $this->expectException(AllegroApiException::class);
        app(AllegroReturnNormalizer::class)->normalize($raw);
    }
}
