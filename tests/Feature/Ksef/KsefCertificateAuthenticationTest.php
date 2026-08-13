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
use Modules\Ksef\Services\KsefCertificateAuthenticationService;
use Modules\Ksef\Services\KsefSettingsService;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\KsefApiFake;
use Tests\Support\KsefCertificateFixtureFactory;
use Tests\TestCase;

class KsefCertificateAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_full_ec_xades_auth_polls_and_redeems_once_without_transmitting_private_key(): void
    {
        $fixture = KsefCertificateFixtureFactory::ec();
        $credential = $this->credential($fixture);
        $fake = $this->fakeApi();
        $fake->statusCodes = [100, 200];

        $pair = app(KsefCertificateAuthenticationService::class)->authenticate(
            $credential,
            '1234567890',
        );

        $this->assertSame(KsefApiFake::ACCESS_TOKEN, $pair->accessToken);
        $this->assertSame(KsefApiFake::REFRESH_TOKEN, $pair->refreshToken);
        $this->assertSame(1, $fake->challengeCalls);
        $this->assertSame(1, $fake->xadesInitCalls);
        $this->assertSame(2, $fake->statusCalls);
        $this->assertSame(1, $fake->redeemCalls);
        $this->assertSame(0, $fake->tokenInitCalls);
        $this->assertSame(0, $fake->publicKeyCalls);
        $this->assertNotNull($fake->lastSignedXml);
        $this->assertStringContainsString('AuthTokenRequest', $fake->lastSignedXml);
        $this->assertStringContainsString('SignatureValue', $fake->lastSignedXml);
        $this->assertStringNotContainsString('PRIVATE KEY', $fake->lastSignedXml);
        $this->assertStringNotContainsString(KsefApiFake::API_TOKEN, $fake->lastSignedXml);

        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/auth/xades-signature')
            && $request->hasHeader('Content-Type', 'application/xml'));

        $credential->refresh();
        $this->assertSame(KsefApiFake::ACCESS_TOKEN, $credential->access_token);
        $this->assertSame(KsefApiFake::REFRESH_TOKEN, $credential->refresh_token);
        $stored = json_encode(
            DB::table('ksef_credentials')->where('id', $credential->getKey())->first(),
            JSON_THROW_ON_ERROR,
        );
        $this->assertStringNotContainsString(KsefApiFake::AUTHENTICATION_TOKEN, $stored);
    }

    #[DataProvider('terminalStatuses')]
    public function test_terminal_status_never_redeems_and_uses_method_specific_message(
        int $status,
        string $message,
    ): void {
        $credential = $this->credential(KsefCertificateFixtureFactory::ec());
        $fake = $this->fakeApi();
        $fake->statusCodes = [$status];

        try {
            app(KsefCertificateAuthenticationService::class)->authenticate($credential, '1234567890');
            $this->fail('Expected terminal certificate authentication failure.');
        } catch (KsefApiException $exception) {
            $this->assertSame('auth_status_'.$status, $exception->safeCode);
            $this->assertStringContainsString($message, $exception->getMessage());
            $this->assertStringNotContainsString('Token KSeF jest nieprawidłowy', $exception->getMessage());
        }

        $this->assertSame(0, $fake->redeemCalls);
        $credential->refresh();
        $this->assertNull($credential->access_token);
        $this->assertNull($credential->refresh_token);
    }

    public static function terminalStatuses(): array
    {
        return [
            'certificate rejected' => [460, 'odrzucił certyfikat'],
            'context forbidden' => [415, 'wskazanego kontekstu'],
        ];
    }

    public function test_xades_init_failure_does_not_poll_or_redeem(): void
    {
        $credential = $this->credential(KsefCertificateFixtureFactory::ec());
        $fake = $this->fakeApi();
        $fake->failures['/auth/xades-signature'] = ['status' => 403];

        $this->expectException(KsefApiException::class);

        try {
            app(KsefCertificateAuthenticationService::class)->authenticate($credential, '1234567890');
        } finally {
            $this->assertSame(0, $fake->statusCalls);
            $this->assertSame(0, $fake->redeemCalls);
        }
    }

    public function test_malformed_init_response_does_not_poll_or_redeem(): void
    {
        $credential = $this->credential(KsefCertificateFixtureFactory::ec());
        $fake = $this->fakeApi();
        $fake->xadesInitResponse = ['referenceNumber' => 'AUTH-REFERENCE'];

        try {
            app(KsefCertificateAuthenticationService::class)->authenticate($credential, '1234567890');
            $this->fail('Expected malformed init response.');
        } catch (KsefApiException $exception) {
            $this->assertSame('auth_response_incomplete', $exception->safeCode);
        }

        $this->assertSame(0, $fake->statusCalls);
        $this->assertSame(0, $fake->redeemCalls);
    }

    public function test_poll_timeout_is_bounded_without_redeem(): void
    {
        config()->set('ksef.auth_poll_max_attempts', 3);
        $credential = $this->credential(KsefCertificateFixtureFactory::ec());
        $fake = $this->fakeApi();
        $fake->statusCodes = [100, 100, 100];

        try {
            app(KsefCertificateAuthenticationService::class)->authenticate($credential, '1234567890');
            $this->fail('Expected bounded polling timeout.');
        } catch (KsefApiException $exception) {
            $this->assertSame('auth_poll_timeout', $exception->safeCode);
        }

        $this->assertSame(3, $fake->statusCalls);
        $this->assertSame(0, $fake->redeemCalls);
    }

    public function test_malformed_status_does_not_redeem_or_persist_runtime_tokens(): void
    {
        $credential = $this->credential(KsefCertificateFixtureFactory::ec());
        $fake = $this->fakeApi();
        $fake->statusCodes = ['not-a-status-code'];

        try {
            app(KsefCertificateAuthenticationService::class)->authenticate($credential, '1234567890');
            $this->fail('Expected malformed authentication status response.');
        } catch (KsefApiException $exception) {
            $this->assertSame('auth_status_malformed', $exception->safeCode);
        }

        $this->assertSame(1, $fake->statusCalls);
        $this->assertSame(0, $fake->redeemCalls);
        $credential->refresh();
        $this->assertNull($credential->access_token);
        $this->assertNull($credential->refresh_token);
    }

    public function test_incomplete_redeem_response_does_not_persist_partial_runtime_tokens(): void
    {
        $credential = $this->credential(KsefCertificateFixtureFactory::ec());
        $fake = $this->fakeApi();
        $fake->redeemResponse = [
            'accessToken' => [
                'token' => KsefApiFake::ACCESS_TOKEN,
                'validUntil' => now()->addMinutes(15)->toIso8601String(),
            ],
        ];

        try {
            app(KsefCertificateAuthenticationService::class)->authenticate($credential, '1234567890');
            $this->fail('Expected incomplete redeem response.');
        } catch (KsefApiException $exception) {
            $this->assertSame('auth_response_incomplete', $exception->safeCode);
        }

        $this->assertSame(1, $fake->redeemCalls);
        $credential->refresh();
        $this->assertNull($credential->access_token);
        $this->assertNull($credential->refresh_token);
    }

    public function test_invalid_stored_certificate_material_fails_before_http(): void
    {
        $credential = $this->credential(KsefCertificateFixtureFactory::ec());
        $credential->forceFill([
            'authentication_certificate' => 'INVALID STORED CERTIFICATE',
        ])->save();
        Http::preventStrayRequests();

        try {
            app(KsefCertificateAuthenticationService::class)->authenticate($credential, '1234567890');
            $this->fail('Expected invalid stored certificate material.');
        } catch (KsefApiException $exception) {
            $this->assertSame('certificate_material_invalid', $exception->safeCode);
        }

        Http::assertNothingSent();
        $credential->refresh();
        $this->assertNull($credential->access_token);
        $this->assertNull($credential->refresh_token);
    }

    #[DataProvider('configurationChanges')]
    public function test_runtime_is_not_persisted_when_certificate_configuration_changes(string $change): void
    {
        $fixture = KsefCertificateFixtureFactory::ec();
        $credential = $this->credential($fixture);
        $fake = new KsefApiFake;
        config()->set('ksef.auth_poll_interval_ms', 0);
        Http::preventStrayRequests();
        Http::fake(function (Request $request) use ($fake, $credential, $change) {
            $response = $fake($request);

            if (str_ends_with($request->url(), '/auth/token/redeem')) {
                if ($change === 'context') {
                    app(KsefSettingsService::class)->get()->forceFill(['context_nip' => '0987654321'])->save();
                } else {
                    $managed = KsefCredential::query()->findOrFail($credential->getKey());
                    $managed->forceFill(match ($change) {
                        'method' => ['authentication_method' => KsefAuthenticationMethod::Token],
                        'certificate' => ['authentication_certificate' => 'CHANGED_CERTIFICATE'],
                        'private_key' => ['authentication_private_key' => 'CHANGED_PRIVATE_KEY'],
                    })->save();
                }
            }

            return $response;
        });

        try {
            app(KsefCertificateAuthenticationService::class)->authenticate($credential, '1234567890');
            $this->fail('Expected configuration race guard failure.');
        } catch (KsefApiException $exception) {
            $this->assertSame('configuration_changed', $exception->safeCode);
        }

        $credential->refresh();
        $this->assertNull($credential->access_token);
        $this->assertNull($credential->refresh_token);
    }

    public static function configurationChanges(): array
    {
        return [
            'context NIP' => ['context'],
            'authentication method' => ['method'],
            'certificate' => ['certificate'],
            'private key' => ['private_key'],
        ];
    }

    private function credential(array $fixture): KsefCredential
    {
        $settings = app(KsefSettingsService::class)->get();
        $settings->forceFill([
            'environment' => KsefEnvironment::Test,
            'context_nip' => '1234567890',
        ])->save();

        return KsefCredential::query()->create([
            'environment' => KsefEnvironment::Test,
            'authentication_method' => KsefAuthenticationMethod::Certificate,
            'authentication_certificate' => $fixture['certificate'],
            'authentication_private_key' => $fixture['private_key'],
        ]);
    }

    private function fakeApi(): KsefApiFake
    {
        config()->set('ksef.auth_poll_interval_ms', 0);
        $fake = new KsefApiFake;
        Http::preventStrayRequests();
        Http::fake(fn (Request $request) => $fake($request));

        return $fake;
    }
}
