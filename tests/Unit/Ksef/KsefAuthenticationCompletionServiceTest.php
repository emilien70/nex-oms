<?php

namespace Tests\Unit\Ksef;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Modules\Ksef\Enums\KsefAuthenticationMethod;
use Modules\Ksef\Enums\KsefEnvironment;
use Modules\Ksef\Services\KsefAuthenticationCompletionService;
use Tests\Support\KsefApiFake;
use Tests\TestCase;

class KsefAuthenticationCompletionServiceTest extends TestCase
{
    public function test_successful_completion_returns_and_exposes_only_sanitized_warnings(): void
    {
        $authenticationToken = 'FAKE_RACE_AUTHENTICATION_TOKEN_SECRET';
        $accessToken = 'FAKE_RACE_ACCESS_TOKEN_SECRET';
        $refreshToken = 'FAKE_RACE_REFRESH_TOKEN_SECRET';
        $fake = new KsefApiFake;
        $fake->redeemResponse = [
            'accessToken' => [
                'token' => $accessToken,
                'validUntil' => '2026-08-26T10:00:00Z',
            ],
            'refreshToken' => [
                'token' => $refreshToken,
                'validUntil' => '2027-01-15T10:00:00Z',
            ],
        ];
        $fake->warnings['/auth/token/redeem'] = 'redeem '.$accessToken.' '.$refreshToken.' code=REDEEM';
        $warnings = ['init '.$authenticationToken.' code=AUTH'];
        config()->set('ksef.auth_poll_interval_ms', 0);
        Http::preventStrayRequests();
        Http::fake(fn (Request $request) => $fake($request));

        $pair = app(KsefAuthenticationCompletionService::class)->complete(
            KsefEnvironment::Test,
            KsefAuthenticationMethod::Certificate,
            [
                'referenceNumber' => 'AUTH-REFERENCE',
                'authenticationToken' => ['token' => $authenticationToken],
            ],
            $warnings,
        );

        $expected = [
            'init [ukryto] code=AUTH',
            'redeem [ukryto] [ukryto] code=REDEEM',
        ];
        $this->assertSame($expected, $pair->systemWarnings);
        $this->assertSame($expected, $warnings);
        $this->assertSame('Europe/Warsaw', $pair->accessTokenValidUntil->getTimezone()->getName());
        $this->assertSame('2026-08-26 10:00:00', $pair->accessTokenValidUntil->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('2027-01-15 10:00:00', $pair->refreshTokenValidUntil->utc()->format('Y-m-d H:i:s'));
        $this->assertSame(1, $fake->redeemCalls);
        $serialized = json_encode([$pair->systemWarnings, $warnings], JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString($authenticationToken, $serialized);
        $this->assertStringNotContainsString($accessToken, $serialized);
        $this->assertStringNotContainsString($refreshToken, $serialized);
    }
}
