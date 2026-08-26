<?php

namespace Tests\Feature\Ksef;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Modules\Ksef\Enums\KsefAuthenticationMethod;
use Modules\Ksef\Enums\KsefEnvironment;
use Modules\Ksef\Exceptions\KsefApiException;
use Modules\Ksef\Models\KsefCredential;
use Modules\Ksef\Services\KsefAccessTokenManager;
use Modules\Ksef\Services\KsefSettingsService;
use Modules\Ksef\Services\KsefTokenValidityNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
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

        $token = app(KsefAccessTokenManager::class)->getValidAccessToken(
            KsefEnvironment::Test,
            '1234567890',
        );

        $this->assertSame('VALID_ACCESS_TOKEN', $token);
        Http::assertNothingSent();
    }

    public function test_remote_access_validity_survives_database_roundtrip_and_is_reused_without_http(): void
    {
        Http::preventStrayRequests();
        $this->travelTo(CarbonImmutable::parse('2026-08-26 11:00:00 Europe/Warsaw'));

        try {
            $credential = $this->credential();
            $validity = app(KsefTokenValidityNormalizer::class);
            $credential->forceFill([
                'access_token' => 'ROUNDTRIP_ACCESS_TOKEN',
                'access_token_valid_until' => $validity->parseRemote('2026-08-26T10:00:00Z'),
                'refresh_token' => 'ROUNDTRIP_REFRESH_TOKEN',
                'refresh_token_valid_until' => $validity->parseRemote('2026-08-27T10:00:00Z'),
            ])->save();
            $credentialId = $credential->getKey();
            unset($credential);

            $reloaded = KsefCredential::query()->findOrFail($credentialId);

            $this->assertSame(
                '2026-08-26 12:00:00',
                DB::table('ksef_credentials')->where('id', $credentialId)->value('access_token_valid_until'),
            );
            $this->assertSame('Europe/Warsaw', $reloaded->access_token_valid_until->getTimezone()->getName());
            $this->assertSame(
                '2026-08-26 10:00:00',
                $reloaded->access_token_valid_until->utc()->format('Y-m-d H:i:s'),
            );
            $this->assertSame(
                'ROUNDTRIP_ACCESS_TOKEN',
                app(KsefAccessTokenManager::class)->getValidAccessToken(
                    KsefEnvironment::Test,
                    '1234567890',
                ),
            );
            Http::assertNothingSent();
        } finally {
            $this->travelBack();
        }
    }

    #[DataProvider('credentialSeparationCases')]
    public function test_access_token_never_falls_back_between_environments(
        KsefEnvironment $storedEnvironment,
        KsefEnvironment $requestedEnvironment,
    ): void {
        Http::preventStrayRequests();
        app(KsefSettingsService::class)->get()->forceFill([
            'environment' => $requestedEnvironment,
            'context_nip' => '1234567890',
        ])->save();
        $credential = KsefCredential::query()->create([
            'environment' => $storedEnvironment,
            'authentication_method' => KsefAuthenticationMethod::Token,
            'api_token' => 'STORED_ENVIRONMENT_API_TOKEN',
            'access_token' => 'STORED_ENVIRONMENT_ACCESS_TOKEN',
            'access_token_valid_until' => now()->addMinutes(10),
        ]);

        try {
            app(KsefAccessTokenManager::class)->getValidAccessToken(
                $requestedEnvironment,
                '1234567890',
            );
            $this->fail('Expected missing credential for requested environment.');
        } catch (KsefApiException $exception) {
            $this->assertSame('api_token_missing', $exception->safeCode);
        }

        $this->assertSame('STORED_ENVIRONMENT_ACCESS_TOKEN', $credential->fresh()->access_token);
        Http::assertNothingSent();
    }

    public static function credentialSeparationCases(): array
    {
        return [
            'TEST never supplies DEMO' => [KsefEnvironment::Test, KsefEnvironment::Demo],
            'DEMO never supplies TEST' => [KsefEnvironment::Demo, KsefEnvironment::Test],
            'DEMO never supplies PRODUCTION' => [KsefEnvironment::Demo, KsefEnvironment::Production],
        ];
    }

    public function test_expected_context_mismatch_blocks_valid_cached_token_without_http(): void
    {
        Http::preventStrayRequests();
        $credential = $this->credential();
        $credential->forceFill([
            'access_token' => 'VALID_ACCESS_TOKEN',
            'access_token_valid_until' => now()->addMinutes(10),
            'refresh_token' => 'VALID_REFRESH_TOKEN',
            'refresh_token_valid_until' => now()->addDay(),
        ])->save();
        app(KsefSettingsService::class)->get()->forceFill(['context_nip' => '0987654321'])->save();

        $this->expectContextChanged();

        Http::assertNothingSent();
    }

    public function test_expected_context_mismatch_blocks_refresh_without_http(): void
    {
        Http::preventStrayRequests();
        $credential = $this->credential();
        $credential->forceFill([
            'access_token' => 'EXPIRED_ACCESS_TOKEN',
            'access_token_valid_until' => now()->subMinute(),
            'refresh_token' => 'VALID_REFRESH_TOKEN',
            'refresh_token_valid_until' => now()->addDay(),
        ])->save();
        app(KsefSettingsService::class)->get()->forceFill(['context_nip' => '0987654321'])->save();

        $this->expectContextChanged();

        Http::assertNothingSent();
    }

    public function test_expected_context_mismatch_blocks_full_authentication_without_http(): void
    {
        Http::preventStrayRequests();
        $this->credential();
        app(KsefSettingsService::class)->get()->forceFill(['context_nip' => '0987654321'])->save();

        $this->expectContextChanged();

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

    public function test_refreshed_access_token_is_reused_after_reload_without_another_refresh(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-08-26 12:00:00 Europe/Warsaw'));

        try {
            $fake = new KsefApiFake;
            Http::preventStrayRequests();
            Http::fake(fn (Request $request) => $fake($request));
            $credential = $this->credential();
            $credential->forceFill([
                'access_token' => 'EXPIRED_ACCESS_TOKEN',
                'access_token_valid_until' => now()->subMinute(),
                'refresh_token' => 'VALID_REFRESH_TOKEN',
                'refresh_token_valid_until' => now()->addDay(),
            ])->save();

            $manager = app(KsefAccessTokenManager::class);
            $first = $manager->getValidAccessToken(KsefEnvironment::Test, '1234567890');
            $credentialId = $credential->getKey();
            unset($credential);
            $reloaded = KsefCredential::query()->findOrFail($credentialId);

            $this->assertSame('NEW_'.KsefApiFake::ACCESS_TOKEN, $first);
            $this->assertSame(1, $fake->refreshCalls);
            $this->assertSame(
                '2026-08-26 10:15:00',
                $reloaded->access_token_valid_until->utc()->format('Y-m-d H:i:s'),
            );
            $this->assertSame(
                $first,
                $manager->getValidAccessToken(KsefEnvironment::Test, '1234567890'),
            );
            $this->assertSame(1, $fake->refreshCalls);
            Http::assertSentCount(1);
        } finally {
            $this->travelBack();
        }
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
        $this->assertSame(
            $token,
            app(KsefAccessTokenManager::class)->getValidAccessToken(KsefEnvironment::Test),
        );
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
        $this->assertSame(
            $token,
            app(KsefAccessTokenManager::class)->getValidAccessToken(KsefEnvironment::Test),
        );
        $this->assertSame(0, $fake->refreshCalls);
        $this->assertSame(1, $fake->xadesInitCalls);
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

    private function expectContextChanged(): void
    {
        try {
            app(KsefAccessTokenManager::class)->getValidAccessToken(
                KsefEnvironment::Test,
                '1234567890',
            );
            $this->fail('Expected frozen KSeF context mismatch.');
        } catch (KsefApiException $exception) {
            $this->assertSame('ksef_submission_context_changed', $exception->safeCode);
            $this->assertStringNotContainsString('1234567890', $exception->getMessage());
            $this->assertStringNotContainsString('0987654321', $exception->getMessage());
        }
    }
}
