<?php

namespace Tests\Unit;

use Modules\Integrations\Allegro\Exceptions\AllegroApiException;
use Modules\Integrations\Allegro\Services\AllegroRefundNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\AllegroOrderFixture;
use Tests\Support\AllegroRefundFixture;
use Tests\TestCase;

class AllegroRefundNormalizerTest extends TestCase
{
    public function test_normalization_keeps_products_delivery_and_other_amounts_separate(): void
    {
        $raw = AllegroRefundFixture::payload();
        $data = app(AllegroRefundNormalizer::class)->normalize($raw);
        $this->assertSame('60.02', $data['products_value']);
        $this->assertSame('10.00', $data['delivery_value']);
        $this->assertSame('0.00', $data['other_value']);
        $this->assertSame($data['source_hash'], app(AllegroRefundNormalizer::class)->normalize($raw)['source_hash']);
        $raw['overpaid'] = ['value' => ['amount' => '0.01', 'currency' => 'PLN']];
        $data = app(AllegroRefundNormalizer::class)->normalize($raw);
        $this->assertSame('60.01', $data['products_value']);
        $this->assertSame('0.01', $data['other_value']);
    }

    public function test_no_delivery_refund_does_not_copy_original_shipping_cost(): void
    {
        $raw = AllegroRefundFixture::payload();
        unset($raw['delivery']);
        $raw['totalValue']['amount'] = '60.02';
        $data = app(AllegroRefundNormalizer::class)->normalize($raw);
        $this->assertSame('0.00', $data['delivery_value']);
        $this->assertSame('60.02', $data['products_value']);
    }

    public function test_unknown_breakdown_is_not_invented_and_status_is_preserved(): void
    {
        $raw = AllegroRefundFixture::payload();
        unset($raw['lineItems']);
        $raw['status'] = 'FUTURE_STATUS';
        $data = app(AllegroRefundNormalizer::class)->normalize($raw);
        $this->assertNull($data['products_value']);
        $this->assertSame('FUTURE_STATUS', $data['status']);
        $raw = AllegroRefundFixture::payload();
        $raw['lineItems'][0] = ['id' => $raw['lineItems'][0]['id'], 'type' => 'AMOUNT', 'value' => ['amount' => '40.00', 'currency' => 'PLN']];
        $this->assertNull(app(AllegroRefundNormalizer::class)->normalize($raw)['products_value']);
    }

    public function test_mixed_surcharge_refund_is_saved_without_inventing_product_subtotal(): void
    {
        $raw = AllegroRefundFixture::payload();
        $raw['surcharges'] = [['id' => AllegroOrderFixture::PAYMENT_ID, 'value' => ['amount' => '5.00', 'currency' => 'PLN']]];
        $data = app(AllegroRefundNormalizer::class)->normalize($raw);
        $this->assertNull($data['products_value']);
        $this->assertSame('70.02', $data['total_value']);
        $this->assertSame('0.00', $data['other_value']);
        $this->assertSame('5.00', $data['components']['surcharges'][0]['value']);
    }

    #[DataProvider('badData')]
    public function test_invalid_financial_payload_is_rejected(string $key, mixed $value): void
    {
        $raw = AllegroRefundFixture::payload();
        data_set($raw, $key, $value);
        $this->expectException(AllegroApiException::class);
        app(AllegroRefundNormalizer::class)->normalize($raw);
    }

    public static function badData(): array
    {
        return [['id', 'bad'], ['payment.id', 'bad'], ['order.id', 'bad'], ['totalValue.amount', 70.02],
            ['totalValue.amount', '-1.00'], ['totalValue.amount', '1.001'], ['delivery.value.currency', 'EUR'],
            ['delivery.value.amount', '999.00'], ['lineItems.0.quantity', 0], ['lineItems.0.type', 'OTHER'],
            ['lineItems', 'bad'], ['createdAt', '2026-02-30T10:00:00Z'], ['createdAt', 'yesterday'], ['status', '']];
    }
}
