<?php

namespace Tests\Feature\Ksef;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Modules\Ksef\Enums\KsefAuthenticationMethod;
use Modules\Ksef\Enums\KsefEnvironment;
use Modules\Ksef\Exceptions\KsefApiException;
use Modules\Ksef\Models\KsefCredential;
use Modules\Ksef\Services\KsefSettingsService;
use Modules\Ksef\Services\KsefTokenAuthenticationService;
use phpseclib3\Crypt\RSA;
use Tests\Support\KsefApiFake;
use Tests\TestCase;

class KsefTokenAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_full_auth_uses_mf_timestamp_encrypted_token_and_redeems_once(): void
    {
        config()->set('ksef.auth_poll_interval_ms', 0);
        $fake = new KsefApiFake;
        $fake->statusCodes = [100, 200];
        Http::preventStrayRequests();
        Http::fake(fn (Request $request) => $fake($request));
        $credential = $this->credential();

        $pair = app(KsefTokenAuthenticationService::class)->authenticate($credential, '1234567890');

        $this->assertSame(KsefApiFake::ACCESS_TOKEN, $pair->accessToken);
        $this->assertSame(KsefApiFake::REFRESH_TOKEN, $pair->refreshToken);
        $this->assertSame(2, $fake->statusCalls);
        $this->assertSame(1, $fake->redeemCalls);

        Http::assertSent(function (Request $request) use ($fake): bool {
            if (! str_ends_with($request->url(), '/auth/ksef-token')) {
                return false;
            }

            $payload = $request->data();
            $encryptedToken = $payload['encryptedToken'] ?? '';
            $plaintext = $fake->privateKey
                ->withPadding(RSA::ENCRYPTION_OAEP)
                ->withHash('sha256')
                ->withMGFHash('sha256')
                ->decrypt(base64_decode($encryptedToken, true));

            return ($payload['challenge'] ?? null) === 'CHALLENGE-123'
                && data_get($payload, 'contextIdentifier.type') === 'Nip'
                && data_get($payload, 'contextIdentifier.value') === '1234567890'
                && ($payload['publicKeyId'] ?? null) === 'PUBLIC-KEY-ID'
                && $encryptedToken !== KsefApiFake::API_TOKEN
                && $plaintext === KsefApiFake::API_TOKEN.'|1752236636015'
                && ! in_array(KsefApiFake::API_TOKEN, $payload, true);
        });

        $credential->refresh();
        $this->assertSame(KsefApiFake::ACCESS_TOKEN, $credential->access_token);
        $this->assertSame(KsefApiFake::REFRESH_TOKEN, $credential->refresh_token);
        $this->assertNotSame(
            KsefApiFake::ACCESS_TOKEN,
            DB::table('ksef_credentials')->where('id', $credential->getKey())->value('access_token'),
        );
        $this->assertNotSame(
            KsefApiFake::REFRESH_TOKEN,
            DB::table('ksef_credentials')->where('id', $credential->getKey())->value('refresh_token'),
        );
        $this->assertArrayNotHasKey('api_token', $credential->toArray());
        $this->assertArrayNotHasKey('access_token', $credential->toArray());
        $this->assertArrayNotHasKey('refresh_token', $credential->toArray());
    }

    public function test_permission_or_token_terminal_status_never_redeems_or_persists_runtime_tokens(): void
    {
        $this->assertTerminalStatusIsRejected(415);
    }

    public function test_invalid_token_terminal_status_never_redeems_or_persists_runtime_tokens(): void
    {
        $this->assertTerminalStatusIsRejected(450);
    }

    public function test_polling_timeout_is_bounded_and_does_not_redeem(): void
    {
        config()->set('ksef.auth_poll_interval_ms', 0);
        config()->set('ksef.auth_poll_max_attempts', 3);
        $fake = new KsefApiFake;
        $fake->statusCodes = [100, 100, 100];
        Http::preventStrayRequests();
        Http::fake(fn (Request $request) => $fake($request));

        try {
            app(KsefTokenAuthenticationService::class)->authenticate($this->credential(), '1234567890');
            $this->fail('Expected polling timeout.');
        } catch (KsefApiException $exception) {
            $this->assertSame('auth_poll_timeout', $exception->safeCode);
            $this->assertStringContainsString('oczekiwanym czasie', $exception->getMessage());
        }

        $this->assertSame(3, $fake->statusCalls);
        $this->assertSame(0, $fake->redeemCalls);
    }

    public function test_already_redeemed_status_is_rejected_without_another_redeem(): void
    {
        config()->set('ksef.auth_poll_interval_ms', 0);
        $fake = new KsefApiFake;
        $fake->isTokenRedeemed = true;
        Http::preventStrayRequests();
        Http::fake(fn (Request $request) => $fake($request));

        try {
            app(KsefTokenAuthenticationService::class)->authenticate($this->credential(), '1234567890');
            $this->fail('Expected already-redeemed authentication state failure.');
        } catch (KsefApiException $exception) {
            $this->assertSame('auth_token_already_redeemed', $exception->safeCode);
        }

        $this->assertSame(0, $fake->redeemCalls);
    }

    public function test_redeem_failure_is_not_retried(): void
    {
        config()->set('ksef.auth_poll_interval_ms', 0);
        $fake = new KsefApiFake;
        $fake->failures['/auth/token/redeem'] = ['status' => 500];
        Http::preventStrayRequests();
        Http::fake(function (Request $request) use ($fake) {
            if (str_ends_with(parse_url($request->url(), PHP_URL_PATH) ?: '', '/auth/token/redeem')) {
                $fake->redeemCalls++;
            }

            return $fake($request);
        });

        $this->expectException(KsefApiException::class);

        try {
            app(KsefTokenAuthenticationService::class)->authenticate($this->credential(), '1234567890');
        } finally {
            $this->assertSame(1, $fake->redeemCalls);
        }
    }

    public function test_configuration_race_after_successful_completion_keeps_token_auth_warning_safe(): void
    {
        $secret = 'FAKE_TOKEN_FLOW_ACCESS_SECRET';
        config()->set('ksef.auth_poll_interval_ms', 0);
        $fake = new KsefApiFake;
        $fake->redeemResponse = [
            'accessToken' => [
                'token' => $secret,
                'validUntil' => now()->addMinutes(15)->toIso8601String(),
            ],
            'refreshToken' => [
                'token' => KsefApiFake::REFRESH_TOKEN,
                'validUntil' => now()->addDays(7)->toIso8601String(),
            ],
        ];
        $fake->warnings['/auth/token/redeem'] = 'warning '.$secret.' code=ABC';
        $credential = $this->credential();
        Http::preventStrayRequests();
        Http::fake(function (Request $request) use ($fake, $credential) {
            $response = $fake($request);

            if (str_ends_with($request->url(), '/auth/token/redeem')) {
                KsefCredential::query()
                    ->findOrFail($credential->getKey())
                    ->forceFill(['api_token' => 'CHANGED_FAKE_API_TOKEN'])
                    ->save();
            }

            return $response;
        });

        try {
            app(KsefTokenAuthenticationService::class)->authenticate($credential, '1234567890');
            $this->fail('Expected configuration race guard failure.');
        } catch (KsefApiException $exception) {
            $this->assertSame('configuration_changed', $exception->safeCode);
            $this->assertSame('warning [ukryto] code=ABC', $exception->systemWarning);
            $this->assertStringNotContainsString($secret, (string) $exception->systemWarning);
        }

        $this->assertSame(1, $fake->redeemCalls);
        $credential->refresh();
        $this->assertNull($credential->access_token);
        $this->assertNull($credential->refresh_token);
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

    private function assertTerminalStatusIsRejected(int $statusCode): void
    {
        config()->set('ksef.auth_poll_interval_ms', 0);
        $fake = new KsefApiFake;
        $fake->statusCodes = [$statusCode];
        Http::preventStrayRequests();
        Http::fake(fn (Request $request) => $fake($request));
        $credential = $this->credential();

        try {
            app(KsefTokenAuthenticationService::class)->authenticate($credential, '1234567890');
            $this->fail('Expected terminal authentication failure.');
        } catch (KsefApiException $exception) {
            $this->assertSame('auth_status_'.$statusCode, $exception->safeCode);
            $this->assertStringNotContainsString(KsefApiFake::API_TOKEN, $exception->getMessage());
        }

        $this->assertSame(0, $fake->redeemCalls);
        $credential->refresh();
        $this->assertNull($credential->access_token);
        $this->assertNull($credential->refresh_token);
    }
}
