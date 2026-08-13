<?php

namespace Tests\Feature\Ksef;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Modules\Ksef\Enums\KsefAuthenticationMethod;
use Modules\Ksef\Enums\KsefEnvironment;
use Modules\Ksef\Exceptions\KsefApiException;
use Modules\Ksef\Models\KsefCredential;
use Modules\Ksef\Services\KsefAccessTokenManager;
use Modules\Ksef\Services\KsefSettingsService;
use Tests\Support\KsefApiFake;
use Tests\Support\KsefCertificateFixtureFactory;
use Tests\TestCase;

class KsefAccessTokenManagerTest extends TestCase
{
    use RefreshDatabase;

    public function test_valid_access_token_is_returned_without_http(): void
    {
        Http::preventStrayRequests();
        $credential = $this->credential();
        $credential->forceFill([
            'access_token' => 'VALID_ACCESS_TOKEN',
            'access_token_valid_until' => now()->addMinutes(10),
            'refresh_token' => 'VALID_REFRESH_TOKEN',
            'refresh_token_valid_until' => now()->addDay(),
        ])->save();

        $token = app(KsefAccessTokenManager::class)->getValidAccessToken(KsefEnvironment::Test);

        $this->assertSame('VALID_ACCESS_TOKEN', $token);
        Http::assertNothingSent();
    }

    public function test_expiring_access_token_is_refreshed_and_existing_refresh_token_is_preserved(): void
    {
        Http::preventStrayRequests();
        Http::fake(['*' => Http::response([
            'accessToken' => [
                'token' => 'NEW_ACCESS_TOKEN',
                'validUntil' => now()->addMinutes(15)->toIso8601String(),
            ],
        ])]);
        $credential = $this->credential();
        $credential->forceFill([
            'access_token' => 'EXPIRING_ACCESS_TOKEN',
            'access_token_valid_until' => now()->addSeconds(30),
            'refresh_token' => 'VALID_REFRESH_TOKEN',
            'refresh_token_valid_until' => now()->addDay(),
        ])->save();

        $token = app(KsefAccessTokenManager::class)->getValidAccessToken(KsefEnvironment::Test);

        $this->assertSame('NEW_ACCESS_TOKEN', $token);
        $credential->refresh();
        $this->assertSame('NEW_ACCESS_TOKEN', $credential->access_token);
        $this->assertSame('VALID_REFRESH_TOKEN', $credential->refresh_token);
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/auth/token/refresh')
            && $request->hasHeader('Authorization', 'Bearer VALID_REFRESH_TOKEN'));
    }

    public function test_expired_refresh_token_uses_a_fresh_full_authentication_flow(): void
    {
        config()->set('ksef.auth_poll_interval_ms', 0);
        $fake = new KsefApiFake;
        Http::preventStrayRequests();
        Http::fake(fn (Request $request) => $fake($request));
        $credential = $this->credential();
        $credential->forceFill([
            'access_token' => 'EXPIRED_ACCESS_TOKEN',
            'access_token_valid_until' => now()->subMinute(),
            'refresh_token' => 'EXPIRED_REFRESH_TOKEN',
            'refresh_token_valid_until' => now()->subMinute(),
        ])->save();

        $token = app(KsefAccessTokenManager::class)->getValidAccessToken(KsefEnvironment::Test);

        $this->assertSame(KsefApiFake::ACCESS_TOKEN, $token);
        $this->assertSame(0, $fake->refreshCalls);
        $this->assertSame(1, $fake->redeemCalls);
        $credential->refresh();
        $this->assertSame(KsefApiFake::REFRESH_TOKEN, $credential->refresh_token);
    }

    public function test_refresh_authorization_failure_clears_runtime_and_uses_one_full_authentication_flow(): void
    {
        config()->set('ksef.auth_poll_interval_ms', 0);
        $fake = new KsefApiFake;
        $fake->failures['/auth/token/refresh'] = ['status' => 401];
        Http::preventStrayRequests();
        Http::fake(fn (Request $request) => $fake($request));
        $credential = $this->credential();
        $credential->forceFill([
            'access_token' => 'EXPIRED_ACCESS_TOKEN',
            'access_token_valid_until' => now()->subMinute(),
            'refresh_token' => 'REJECTED_REFRESH_TOKEN',
            'refresh_token_valid_until' => now()->addDay(),
        ])->save();

        $token = app(KsefAccessTokenManager::class)->getValidAccessToken(KsefEnvironment::Test);

        $this->assertSame(KsefApiFake::ACCESS_TOKEN, $token);
        $this->assertSame(1, $fake->redeemCalls);
        Http::assertSentCount(6);
    }

    public function test_refresh_rate_limit_is_propagated_without_falling_back_to_full_authentication(): void
    {
        $fake = new KsefApiFake;
        $fake->failures['/auth/token/refresh'] = [
            'status' => 429,
            'headers' => ['Retry-After' => '30'],
        ];
        Http::preventStrayRequests();
        Http::fake(fn (Request $request) => $fake($request));
        $credential = $this->credential();
        $credential->forceFill([
            'access_token' => 'EXPIRED_ACCESS_TOKEN',
            'access_token_valid_until' => now()->subMinute(),
            'refresh_token' => 'RATE_LIMITED_REFRESH_TOKEN',
            'refresh_token_valid_until' => now()->addDay(),
        ])->save();

        try {
            app(KsefAccessTokenManager::class)->getValidAccessToken(KsefEnvironment::Test);
            $this->fail('Expected rate limit failure.');
        } catch (KsefApiException $exception) {
            $this->assertSame('rate_limited', $exception->safeCode);
            $this->assertSame(30, $exception->retryAfterSeconds);
        }

        Http::assertSentCount(1);
        $credential->refresh();
        $this->assertSame('RATE_LIMITED_REFRESH_TOKEN', $credential->refresh_token);
    }

    public function test_certificate_method_without_runtime_uses_fresh_xades_authentication(): void
    {
        config()->set('ksef.auth_poll_interval_ms', 0);
        $fake = new KsefApiFake;
        Http::preventStrayRequests();
        Http::fake(fn (Request $request) => $fake($request));
        $credential = $this->certificateCredential();

        $token = app(KsefAccessTokenManager::class)->getValidAccessToken(KsefEnvironment::Test);

        $this->assertSame(KsefApiFake::ACCESS_TOKEN, $token);
        $this->assertSame(1, $fake->xadesInitCalls);
        $this->assertSame(0, $fake->tokenInitCalls);
        $this->assertSame(0, $fake->publicKeyCalls);
        $this->assertSame(1, $fake->redeemCalls);
        $credential->refresh();
        $this->assertSame(KsefApiFake::REFRESH_TOKEN, $credential->refresh_token);
    }

    public function test_certificate_method_uses_valid_refresh_without_xades(): void
    {
        $fake = new KsefApiFake;
        Http::preventStrayRequests();
        Http::fake(fn (Request $request) => $fake($request));
        $credential = $this->certificateCredential();
        $credential->forceFill([
            'access_token' => 'EXPIRED_ACCESS_TOKEN',
            'access_token_valid_until' => now()->subMinute(),
            'refresh_token' => 'VALID_CERT_REFRESH_TOKEN',
            'refresh_token_valid_until' => now()->addDay(),
        ])->save();

        $token = app(KsefAccessTokenManager::class)->getValidAccessToken(KsefEnvironment::Test);

        $this->assertSame('NEW_'.KsefApiFake::ACCESS_TOKEN, $token);
        $this->assertSame(1, $fake->refreshCalls);
        $this->assertSame(0, $fake->challengeCalls);
        $this->assertSame(0, $fake->xadesInitCalls);
    }

    public function test_certificate_refresh_authorization_failure_falls_back_to_xades_not_token_auth(): void
    {
        config()->set('ksef.auth_poll_interval_ms', 0);
        $fake = new KsefApiFake;
        $fake->failures['/auth/token/refresh'] = ['status' => 401];
        Http::preventStrayRequests();
        Http::fake(fn (Request $request) => $fake($request));
        $credential = $this->certificateCredential();
        $credential->forceFill([
            'access_token' => 'EXPIRED_ACCESS_TOKEN',
            'access_token_valid_until' => now()->subMinute(),
            'refresh_token' => 'REJECTED_CERT_REFRESH_TOKEN',
            'refresh_token_valid_until' => now()->addDay(),
        ])->save();

        $token = app(KsefAccessTokenManager::class)->getValidAccessToken(KsefEnvironment::Test);

        $this->assertSame(KsefApiFake::ACCESS_TOKEN, $token);
        $this->assertSame(1, $fake->xadesInitCalls);
        $this->assertSame(0, $fake->tokenInitCalls);
        $this->assertSame(0, $fake->publicKeyCalls);
        $this->assertSame(1, $fake->redeemCalls);
    }

    private function credential(): KsefCredential
    {
        $settings = app(KsefSettingsService::class)->get();
        $settings->forceFill([
            'environment' => KsefEnvironment::Test,
            'context_nip' => '1234567890',
        ])->save();

        return KsefCredential::query()->create([
            'environment' => KsefEnvironment::Test,
            'authentication_method' => KsefAuthenticationMethod::Token,
            'api_token' => KsefApiFake::API_TOKEN,
        ]);
    }

    private function certificateCredential(): KsefCredential
    {
        $settings = app(KsefSettingsService::class)->get();
        $settings->forceFill([
            'environment' => KsefEnvironment::Test,
            'context_nip' => '1234567890',
        ])->save();
        $fixture = KsefCertificateFixtureFactory::ec();

        return KsefCredential::query()->create([
            'environment' => KsefEnvironment::Test,
            'authentication_method' => KsefAuthenticationMethod::Certificate,
            'authentication_certificate' => $fixture['certificate'],
            'authentication_private_key' => $fixture['private_key'],
        ]);
    }
}
