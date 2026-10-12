<?php

namespace Tests\Unit\PayNow;

use Modules\Integrations\PayNow\Services\PayNowSignatureService;
use PHPUnit\Framework\TestCase;

class PayNowSignatureServiceTest extends TestCase
{
    public function test_it_matches_the_official_paynow_v3_signature_vector(): void
    {
        $signature = (new PayNowSignatureService)->generateV3(
            '97a55694-5478-43b5-b406-fb49ebfdd2b5',
            'b305b996-bca5-4404-a0b7-2ccea3d2b64b',
            'd243fdb3-c287-484a-bb9c-58536f2794c1',
        );

        $this->assertSame('fXwLZRwo0WiGll90PPl5oULX9VKA0gpFA/3+E+NRp5E=', $signature);
    }

    public function test_it_normalizes_query_parameters_like_the_http_query_string(): void
    {
        $service = new PayNowSignatureService;

        $signature = $service->generateV3(
            'api-key',
            'signature-key',
            'request-key',
            parameters: ['currency' => 'PLN', 'amount' => 100, 'applePayEnabled' => true],
        );

        $payload = json_encode([
            'headers' => [
                'Api-Key' => 'api-key',
                'Idempotency-Key' => 'request-key',
            ],
            'parameters' => [
                'amount' => ['100'],
                'applePayEnabled' => ['1'],
                'currency' => ['PLN'],
            ],
            'body' => '',
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        $this->assertSame(
            base64_encode(hash_hmac('sha256', $payload, 'signature-key', true)),
            $signature,
        );
    }

    public function test_it_verifies_notification_signature_against_the_exact_raw_body(): void
    {
        $service = new PayNowSignatureService;
        $body = '{"paymentId":"PBLA-1","status":"CONFIRMED"}';
        $signature = base64_encode(hash_hmac('sha256', $body, 'signature-key', true));

        $this->assertTrue($service->verifyNotification($body, 'signature-key', $signature));
        $this->assertFalse($service->verifyNotification($body."\n", 'signature-key', $signature));
        $this->assertFalse($service->verifyNotification($body, 'wrong-key', $signature));
        $this->assertFalse($service->verifyNotification($body, 'signature-key', ''));
    }
}
