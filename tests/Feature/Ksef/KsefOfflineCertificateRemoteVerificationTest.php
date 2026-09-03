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
use Modules\Ksef\Models\KsefOfflineCertificate;
use Modules\Ksef\Models\KsefOfflineCertificateSelection;
use Modules\Ksef\Services\KsefOfflineCertificateReadinessService;
use Modules\Ksef\Services\KsefOfflineCertificateRemoteVerificationService;
use Modules\Ksef\Services\KsefOfflineCertificateService;
use Modules\Ksef\Services\KsefSettingsService;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\KsefCertificateFixtureFactory;
use Tests\TestCase;

class KsefOfflineCertificateRemoteVerificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_active_certificate_is_verified_by_exact_query_and_retrieve_using_certificate_auth_cache(): void
    {
        Http::preventStrayRequests();
        config()->set('app.timezone', 'Europe/Warsaw');
        $fixture = KsefCertificateFixtureFactory::offlineRsa();
        $certificate = $this->importCertificate($fixture);
        $this->seedCachedToken(KsefEnvironment::Test);
        $validFrom = '2026-09-01T10:15:00Z';
        $validUntil = '2026-10-01T10:15:00Z';
        $this->fakeSuccessfulVerification(
            $certificate,
            $fixture,
            validFrom: $validFrom,
            validUntil: $validUntil,
        );

        $verified = app(KsefOfflineCertificateRemoteVerificationService::class)
            ->verify($certificate);
        $raw = DB::table('ksef_offline_certificates')->find($certificate->getKey());
        $reloaded = $verified->fresh();

        $this->assertSame('Active', $reloaded->remote_status);
        $this->assertSame('Certyfikat Offline MF', $reloaded->remote_certificate_name);
        $this->assertNotNull($reloaded->remote_verified_at);
        $this->assertSame('2026-09-01 12:15:00', $raw->remote_valid_from);
        $this->assertSame('2026-10-01 12:15:00', $raw->remote_valid_until);
        $this->assertSame('2026-09-01 10:15:00', $reloaded->remote_valid_from->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-01 10:15:00', $reloaded->remote_valid_until->utc()->format('Y-m-d H:i:s'));
        $this->assertTrue(app(KsefOfflineCertificateReadinessService::class)->isReady($reloaded));
        $this->assertCertificateRequests($certificate, KsefEnvironment::Test);
        $this->assertNoMutatingCertificateRequests();
    }

    #[DataProvider('tokenAuthenticationRuntimeStates')]
    public function test_token_authentication_is_blocked_before_any_http(string $runtimeState): void
    {
        Http::preventStrayRequests();
        $fixture = KsefCertificateFixtureFactory::offlineRsa();
        $certificate = $this->importCertificate($fixture);
        $credential = $this->seedTokenCredential(KsefEnvironment::Test);

        match ($runtimeState) {
            'valid_access' => $credential->forceFill([
                'access_token' => 'FAKE_TOKEN_AUTH_ACCESS',
                'access_token_valid_until' => now()->addMinutes(10),
            ])->save(),
            'valid_refresh' => $credential->forceFill([
                'access_token' => 'FAKE_EXPIRED_TOKEN_AUTH_ACCESS',
                'access_token_valid_until' => now()->subMinute(),
                'refresh_token' => 'FAKE_TOKEN_AUTH_REFRESH',
                'refresh_token_valid_until' => now()->addDay(),
            ])->save(),
            'no_runtime' => null,
        };

        try {
            app(KsefOfflineCertificateRemoteVerificationService::class)->verify($certificate);
            $this->fail('Expected certificate management authentication policy failure.');
        } catch (KsefApiException $exception) {
            $this->assertSame('certificate_management_requires_certificate_auth', $exception->safeCode);
            $this->assertStringContainsString('Authentication/XAdES', $exception->getMessage());
        }

        $this->assertNull($certificate->fresh()->remote_verified_at);
        Http::assertNothingSent();
    }

    public static function tokenAuthenticationRuntimeStates(): array
    {
        return [
            'valid cached access token' => ['valid_access'],
            'valid refresh token' => ['valid_refresh'],
            'no runtime tokens' => ['no_runtime'],
        ];
    }

    public function test_certificate_authentication_uses_valid_refresh_before_query_and_retrieve(): void
    {
        Http::preventStrayRequests();
        $fixture = KsefCertificateFixtureFactory::offlineRsa();
        $certificate = $this->importCertificate($fixture);
        $credential = $this->seedCachedToken(KsefEnvironment::Test);
        $credential->forceFill([
            'access_token' => 'FAKE_EXPIRED_CERTIFICATE_ACCESS',
            'access_token_valid_until' => now()->subMinute(),
            'refresh_token' => 'FAKE_CERTIFICATE_REFRESH',
            'refresh_token_valid_until' => now()->addDay(),
        ])->save();
        $refreshedAccessToken = 'FAKE_REFRESHED_CERTIFICATE_ACCESS';

        Http::fake(function (Request $request) use ($certificate, $fixture, $refreshedAccessToken) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);

            return match (true) {
                str_ends_with($path, '/auth/token/refresh') => Http::response([
                    'accessToken' => [
                        'token' => $refreshedAccessToken,
                        'validUntil' => now()->addMinutes(15)->toIso8601String(),
                    ],
                ]),
                str_ends_with($path, '/certificates/query') => Http::response([
                    'certificates' => [$this->queryItem($certificate)],
                    'hasMore' => false,
                ]),
                str_ends_with($path, '/certificates/retrieve') => Http::response([
                    'certificates' => [$this->retrieveItem($certificate, $fixture)],
                ]),
                default => Http::response(['reasonCode' => 'UNEXPECTED_TEST_REQUEST'], 500),
            };
        });

        $verified = app(KsefOfflineCertificateRemoteVerificationService::class)->verify($certificate);

        $this->assertNotNull($verified->remote_verified_at);
        Http::assertSentCount(3);
        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/auth/token/refresh')
            && $request->hasHeader('Authorization', 'Bearer FAKE_CERTIFICATE_REFRESH'));
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/certificates/query')
            && $request->hasHeader('Authorization', 'Bearer '.$refreshedAccessToken));
        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/certificates/retrieve')
            && $request->hasHeader('Authorization', 'Bearer '.$refreshedAccessToken));
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/auth/challenge')
            || str_contains($request->url(), '/auth/xades-signature')
            || str_contains($request->url(), '/auth/ksef-token'));
    }

    #[DataProvider('missingAuthenticationMaterials')]
    public function test_certificate_authentication_requires_a_complete_saved_authentication_pair(
        string $missing,
    ): void {
        Http::preventStrayRequests();
        $fixture = KsefCertificateFixtureFactory::offlineRsa();
        $authentication = KsefCertificateFixtureFactory::ec();
        $certificate = $this->importCertificate($fixture);
        app(KsefSettingsService::class)->get()->forceFill(['context_nip' => '1234567890'])->save();
        KsefCredential::query()->create([
            'environment' => KsefEnvironment::Test,
            'authentication_method' => KsefAuthenticationMethod::Certificate,
            'authentication_certificate' => $missing === 'certificate' ? null : $authentication['certificate'],
            'authentication_private_key' => $missing === 'private_key' ? null : $authentication['private_key'],
            'access_token' => 'FAKE_CACHED_ACCESS_MUST_NOT_BE_USED',
            'access_token_valid_until' => now()->addMinutes(10),
        ]);

        try {
            app(KsefOfflineCertificateRemoteVerificationService::class)->verify($certificate);
            $this->fail('Expected incomplete Authentication certificate pair failure.');
        } catch (KsefApiException $exception) {
            $this->assertSame('certificate_management_requires_certificate_auth', $exception->safeCode);
        }

        Http::assertNothingSent();
    }

    public static function missingAuthenticationMaterials(): array
    {
        return [
            'certificate missing' => ['certificate'],
            'private key missing' => ['private_key'],
        ];
    }

    public function test_certificate_management_authentication_never_falls_back_between_environments(): void
    {
        Http::preventStrayRequests();
        $fixture = KsefCertificateFixtureFactory::offlineRsa();
        $certificate = $this->importCertificate($fixture, KsefEnvironment::Demo);
        $this->seedCachedToken(KsefEnvironment::Test);

        try {
            app(KsefOfflineCertificateRemoteVerificationService::class)->verify($certificate);
            $this->fail('Expected missing DEMO Certificate authentication failure.');
        } catch (KsefApiException $exception) {
            $this->assertSame('certificate_management_requires_certificate_auth', $exception->safeCode);
        }

        Http::assertNothingSent();
    }

    public function test_demo_certificate_uses_only_demo_environment_without_fallback(): void
    {
        Http::preventStrayRequests();
        $fixture = KsefCertificateFixtureFactory::offlineRsa();
        $certificate = $this->importCertificate($fixture, KsefEnvironment::Demo);
        $this->seedCachedToken(KsefEnvironment::Demo);
        $this->fakeSuccessfulVerification($certificate, $fixture);

        app(KsefOfflineCertificateRemoteVerificationService::class)->verify($certificate);

        $this->assertCertificateRequests($certificate, KsefEnvironment::Demo);
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'api-test.ksef.mf.gov.pl')
            || str_contains($request->url(), 'api.ksef.mf.gov.pl'));
    }

    public function test_production_verification_is_blocked_before_http(): void
    {
        Http::preventStrayRequests();
        $fixture = KsefCertificateFixtureFactory::offlineRsa();
        $certificate = $this->importCertificate($fixture, KsefEnvironment::Production);

        $response = $this->post(route('integrations.ksef.offline-certificates.verify', $certificate));

        $response
            ->assertRedirect(route('integrations.ksef.edit', ['tab' => 'offline-certificates']))
            ->assertSessionHasErrors('offline_certificate_remote');
        $this->assertNull($certificate->fresh()->remote_verified_at);
        Http::assertNothingSent();
    }

    #[DataProvider('remoteStatuses')]
    public function test_known_and_unknown_remote_statuses_are_synchronized_fail_closed(
        string $status,
        bool $expectedReady,
    ): void {
        Http::preventStrayRequests();
        $fixture = KsefCertificateFixtureFactory::offlineRsa();
        $certificate = $this->importCertificate($fixture);
        $this->seedCachedToken(KsefEnvironment::Test);
        $this->fakeSuccessfulVerification($certificate, $fixture, status: $status);

        $verified = app(KsefOfflineCertificateRemoteVerificationService::class)->verify($certificate);

        $this->assertSame($status, $verified->remote_status);
        $this->assertNotNull($verified->remote_verified_at);
        $this->assertSame(
            $expectedReady,
            app(KsefOfflineCertificateReadinessService::class)->isReady($verified),
        );
    }

    public static function remoteStatuses(): array
    {
        return [
            'Active' => ['Active', true],
            'Blocked' => ['Blocked', false],
            'Revoked' => ['Revoked', false],
            'Expired' => ['Expired', false],
            'unknown future value' => ['Suspended', false],
        ];
    }

    public function test_locally_expired_certificate_is_not_ready_despite_active_remote_snapshot(): void
    {
        Http::preventStrayRequests();
        $fixture = KsefCertificateFixtureFactory::offlineRsa();
        $certificate = $this->importCertificate($fixture);
        $this->seedCachedToken(KsefEnvironment::Test);
        $remoteValidUntil = CarbonImmutable::createFromTimestampUTC($fixture['valid_until'])
            ->addYear()
            ->format('Y-m-d\TH:i:s\Z');
        $this->fakeSuccessfulVerification(
            $certificate,
            $fixture,
            validUntil: $remoteValidUntil,
        );
        $verified = app(KsefOfflineCertificateRemoteVerificationService::class)->verify($certificate);

        $this->travelTo(CarbonImmutable::createFromTimestampUTC($fixture['valid_until'])->addSecond());

        try {
            $this->assertFalse(app(KsefOfflineCertificateReadinessService::class)->isReady($verified->fresh()));
        } finally {
            $this->travelBack();
        }
    }

    #[DataProvider('queryIdentityFailures')]
    public function test_definitive_query_identity_failure_invalidates_old_trust(string $case): void
    {
        Http::preventStrayRequests();
        $fixture = KsefCertificateFixtureFactory::offlineRsa();
        $certificate = $this->importCertificate($fixture);
        $this->seedCachedToken(KsefEnvironment::Test);
        $this->trustSnapshot($certificate);
        $item = $this->queryItem($certificate);
        $response = match ($case) {
            'not_found' => ['certificates' => [], 'hasMore' => false],
            'ambiguous' => ['certificates' => [$item, $item], 'hasMore' => false],
            'has_more' => ['certificates' => [$item], 'hasMore' => true],
            'serial_mismatch' => ['certificates' => [array_replace($item, [
                'certificateSerialNumber' => '08F20A5D352AE599',
            ])], 'hasMore' => false],
            'type_mismatch' => ['certificates' => [array_replace($item, [
                'type' => 'Authentication',
            ])], 'hasMore' => false],
        };
        Http::fake(['*' => Http::response($response)]);

        $this->expectSafeVerificationFailure($certificate);

        $certificate->refresh();
        $this->assertNull($certificate->remote_status);
        $this->assertNull($certificate->remote_verified_at);
        $this->assertFalse(app(KsefOfflineCertificateReadinessService::class)->isReady($certificate));
        Http::assertSentCount(1);
    }

    public static function queryIdentityFailures(): array
    {
        return [
            'not found' => ['not_found'],
            'ambiguous result' => ['ambiguous'],
            'unexpected next page' => ['has_more'],
            'serial mismatch' => ['serial_mismatch'],
            'type mismatch' => ['type_mismatch'],
        ];
    }

    #[DataProvider('retrieveIdentityFailures')]
    public function test_definitive_retrieve_identity_failure_invalidates_old_trust(string $case): void
    {
        Http::preventStrayRequests();
        $fixture = KsefCertificateFixtureFactory::offlineRsa();
        $certificate = $this->importCertificate($fixture);
        $this->seedCachedToken(KsefEnvironment::Test);
        $this->trustSnapshot($certificate);
        $remoteFixture = $case === 'fingerprint'
            ? KsefCertificateFixtureFactory::offlineRsa()
            : $fixture;
        $retrieve = $this->retrieveItem($certificate, $remoteFixture);

        if ($case === 'serial') {
            $retrieve['certificateSerialNumber'] = '08F20A5D352AE599';
        } elseif ($case === 'type') {
            $retrieve['certificateType'] = 'Authentication';
        }

        $this->fakeResponses(
            $certificate,
            ['certificates' => [$this->queryItem($certificate)], 'hasMore' => false],
            ['certificates' => [$retrieve]],
        );

        $this->expectSafeVerificationFailure($certificate);

        $certificate->refresh();
        $this->assertNull($certificate->remote_status);
        $this->assertNull($certificate->remote_verified_at);
        Http::assertSentCount(2);
    }

    public static function retrieveIdentityFailures(): array
    {
        return [
            'serial mismatch' => ['serial'],
            'type mismatch' => ['type'],
            'fingerprint mismatch' => ['fingerprint'],
        ];
    }

    public function test_private_key_mismatch_invalidates_old_trust_without_exposing_key(): void
    {
        Http::preventStrayRequests();
        $fixture = KsefCertificateFixtureFactory::offlineRsa();
        $other = KsefCertificateFixtureFactory::offlineRsa(serial: 0x08F20A5D352AE599);
        $certificate = $this->importCertificate($fixture);
        $this->seedCachedToken(KsefEnvironment::Test);
        $this->trustSnapshot($certificate);
        $certificate->forceFill(['private_key_pem' => $other['private_key']])->save();
        $this->fakeSuccessfulVerification($certificate, $fixture);

        try {
            app(KsefOfflineCertificateRemoteVerificationService::class)->verify($certificate);
            $this->fail('Expected private key mismatch.');
        } catch (KsefApiException $exception) {
            $this->assertSame('offline_certificate_private_key_mismatch', $exception->safeCode);
            $this->assertStringNotContainsString('PRIVATE KEY', $exception->getMessage());
        }

        $this->assertNull($certificate->fresh()->remote_verified_at);
    }

    #[DataProvider('malformedRetrievedCertificates')]
    public function test_malformed_remote_certificate_never_creates_verified_snapshot(string $certificateData): void
    {
        Http::preventStrayRequests();
        $fixture = KsefCertificateFixtureFactory::offlineRsa();
        $certificate = $this->importCertificate($fixture);
        $this->seedCachedToken(KsefEnvironment::Test);
        $retrieve = $this->retrieveItem($certificate, $fixture);
        $retrieve['certificate'] = $certificateData;
        $this->fakeResponses(
            $certificate,
            ['certificates' => [$this->queryItem($certificate)], 'hasMore' => false],
            ['certificates' => [$retrieve]],
        );

        try {
            app(KsefOfflineCertificateRemoteVerificationService::class)->verify($certificate);
            $this->fail('Expected malformed certificate response.');
        } catch (KsefApiException $exception) {
            $this->assertSame('offline_certificate_malformed_response', $exception->safeCode);
        }

        $this->assertNull($certificate->fresh()->remote_verified_at);
        Http::assertSentCount(2);
    }

    public static function malformedRetrievedCertificates(): array
    {
        return [
            'invalid Base64' => ['%%%NOT-BASE64%%%'],
            'malformed DER' => [base64_encode('NOT-DER')],
            'valid DER but not X509' => [base64_encode("\x30\x03\x02\x01\x01")],
        ];
    }

    #[DataProvider('transientFailures')]
    public function test_transient_failure_preserves_last_trusted_snapshot(string $case): void
    {
        Http::preventStrayRequests();
        $fixture = KsefCertificateFixtureFactory::offlineRsa();
        $certificate = $this->importCertificate($fixture);
        $this->seedCachedToken(KsefEnvironment::Test);
        $verifiedAt = $this->trustSnapshot($certificate);

        Http::fake(function (Request $request) use ($case) {
            return match ($case) {
                'network' => (Http::failedConnection('FAKE NETWORK FAILURE'))($request),
                'rate_limit' => Http::response([], 429, ['Retry-After' => '30']),
                'server_error' => Http::response([], 503),
                'malformed_json' => Http::response('NOT JSON', 200, ['Content-Type' => 'application/json']),
                'malformed_schema' => Http::response(['certificates' => 'INVALID', 'hasMore' => false]),
            };
        });

        $this->expectSafeVerificationFailure($certificate);

        $certificate->refresh();
        $this->assertSame('Active', $certificate->remote_status);
        $this->assertSame($verifiedAt->getTimestamp(), $certificate->remote_verified_at->getTimestamp());
        $this->assertNotNull($certificate->remote_valid_from);
        $this->assertNotNull($certificate->remote_valid_until);
        Http::assertSentCount(1);
    }

    public static function transientFailures(): array
    {
        return [
            'network error' => ['network'],
            'rate limit' => ['rate_limit'],
            'server error' => ['server_error'],
            'malformed JSON' => ['malformed_json'],
            'malformed response schema' => ['malformed_schema'],
        ];
    }

    #[DataProvider('unsafeQueryRetrieveFailures')]
    public function test_unsafe_exact_query_degrades_old_trust_before_retrieve_failure(
        string $status,
        int $validFromDays,
        int $validUntilDays,
        string $failure,
    ): void {
        Http::preventStrayRequests();
        $fixture = KsefCertificateFixtureFactory::offlineRsa();
        $certificate = $this->importCertificate($fixture);
        $this->seedCachedToken(KsefEnvironment::Test);
        $this->trustSnapshot($certificate);
        $this->assertTrue(app(KsefOfflineCertificateReadinessService::class)->isReady($certificate->fresh()));
        $now = CarbonImmutable::now('UTC')->startOfSecond();
        $validFrom = $now->addDays($validFromDays);
        $validUntil = $now->addDays($validUntilDays);
        $query = [
            'certificates' => [$this->queryItem(
                $certificate,
                $status,
                $validFrom->format('Y-m-d\TH:i:s\Z'),
                $validUntil->format('Y-m-d\TH:i:s\Z'),
            )],
            'hasMore' => false,
        ];
        $this->fakeQueryThenRetrieveFailure($query, $failure);

        $this->expectSafeVerificationFailure($certificate);

        $certificate->refresh();
        $this->assertSame($status, $certificate->remote_status);
        $this->assertSame('Certyfikat Offline MF', $certificate->remote_certificate_name);
        $this->assertSame($validFrom->getTimestamp(), $certificate->remote_valid_from->getTimestamp());
        $this->assertSame($validUntil->getTimestamp(), $certificate->remote_valid_until->getTimestamp());
        $this->assertNull($certificate->remote_verified_at);
        $this->assertFalse(app(KsefOfflineCertificateReadinessService::class)->isReady($certificate));
        Http::assertSentCount(2);

        if ($status === 'Revoked' && $failure === 'server_error') {
            $this->get(route('integrations.ksef.edit', ['tab' => 'offline-certificates']))
                ->assertOk()
                ->assertSeeText('Unieważniony')
                ->assertSeeText('Brak')
                ->assertSee('data-ksef-offline-ready', false);
        }
    }

    public static function unsafeQueryRetrieveFailures(): array
    {
        return [
            'revoked and retrieve 503' => ['Revoked', -1, 1, 'server_error'],
            'blocked and retrieve 429' => ['Blocked', -1, 1, 'rate_limit'],
            'expired and retrieve network error' => ['Expired', -1, 1, 'network'],
            'unknown and retrieve 503' => ['Suspended', -1, 1, 'server_error'],
            'active but remotely expired and retrieve 503' => ['Active', -2, -1, 'server_error'],
            'active but not yet remotely valid and retrieve 429' => ['Active', 1, 2, 'rate_limit'],
            'revoked and malformed retrieve' => ['Revoked', -1, 1, 'malformed_schema'],
        ];
    }

    #[DataProvider('safeActiveMetadataChanges')]
    public function test_safe_active_query_persists_fresh_metadata_before_transient_retrieve(
        int $oldValidFromDays,
        int $oldValidUntilDays,
        int $freshValidFromDays,
        int $freshValidUntilDays,
        string $retrieveFailure,
        ?int $readinessCheckAfterDays,
    ): void {
        Http::preventStrayRequests();
        $fixture = KsefCertificateFixtureFactory::offlineRsa();
        $certificate = $this->importCertificate($fixture);
        $this->seedCachedToken(KsefEnvironment::Test);
        $now = CarbonImmutable::now('UTC')->startOfSecond();
        $verifiedAt = $this->trustSnapshot($certificate);
        $certificate->forceFill([
            'remote_valid_from' => $now->addDays($oldValidFromDays),
            'remote_valid_until' => $now->addDays($oldValidUntilDays),
        ])->save();
        $freshValidFrom = $now->addDays($freshValidFromDays);
        $freshValidUntil = $now->addDays($freshValidUntilDays);
        $query = [
            'certificates' => [$this->queryItem(
                $certificate,
                validFrom: $freshValidFrom->format('Y-m-d\TH:i:s\Z'),
                validUntil: $freshValidUntil->format('Y-m-d\TH:i:s\Z'),
            )],
            'hasMore' => false,
        ];
        $this->fakeQueryThenRetrieveFailure($query, $retrieveFailure);

        $this->expectSafeVerificationFailure($certificate);

        $certificate->refresh();
        $this->assertSame('Active', $certificate->remote_status);
        $this->assertSame('Certyfikat Offline MF', $certificate->remote_certificate_name);
        $this->assertSame($verifiedAt->getTimestamp(), $certificate->remote_verified_at->getTimestamp());
        $this->assertSame($freshValidFrom->getTimestamp(), $certificate->remote_valid_from->getTimestamp());
        $this->assertSame($freshValidUntil->getTimestamp(), $certificate->remote_valid_until->getTimestamp());
        $this->assertTrue(app(KsefOfflineCertificateReadinessService::class)->isReady($certificate));
        Http::assertSentCount(2);

        if ($readinessCheckAfterDays !== null) {
            $this->travelTo($now->addDays($readinessCheckAfterDays));

            try {
                $this->assertFalse(
                    app(KsefOfflineCertificateReadinessService::class)->isReady($certificate->fresh()),
                );
            } finally {
                $this->travelBack();
            }
        }
    }

    public static function safeActiveMetadataChanges(): array
    {
        return [
            'shorter validTo and retrieve 503' => [-10, 10, -10, 2, 'server_error', 3],
            'longer validTo and retrieve 503' => [-10, 2, -10, 10, 'server_error', null],
            'later validFrom and retrieve network failure' => [-10, 10, -2, 10, 'network', null],
            'earlier validFrom and malformed retrieve' => [-2, 10, -10, 10, 'malformed_schema', null],
        ];
    }

    public function test_safe_active_query_without_previous_full_verification_stays_unready_after_retrieve_failure(): void
    {
        Http::preventStrayRequests();
        $fixture = KsefCertificateFixtureFactory::offlineRsa();
        $certificate = $this->importCertificate($fixture);
        $this->seedCachedToken(KsefEnvironment::Test);
        $now = CarbonImmutable::now('UTC')->startOfSecond();
        $validFrom = $now->subDay();
        $validUntil = $now->addDay();
        $query = [
            'certificates' => [$this->queryItem(
                $certificate,
                validFrom: $validFrom->format('Y-m-d\TH:i:s\Z'),
                validUntil: $validUntil->format('Y-m-d\TH:i:s\Z'),
            )],
            'hasMore' => false,
        ];
        $this->fakeQueryThenRetrieveFailure($query, 'server_error');

        $this->expectSafeVerificationFailure($certificate);

        $certificate->refresh();
        $this->assertSame('Active', $certificate->remote_status);
        $this->assertSame($validFrom->getTimestamp(), $certificate->remote_valid_from->getTimestamp());
        $this->assertSame($validUntil->getTimestamp(), $certificate->remote_valid_until->getTimestamp());
        $this->assertNull($certificate->remote_verified_at);
        $this->assertFalse(app(KsefOfflineCertificateReadinessService::class)->isReady($certificate));
        Http::assertSentCount(2);
    }

    public function test_fresh_active_query_does_not_restore_old_non_active_trust_after_retrieve_failure(): void
    {
        Http::preventStrayRequests();
        $fixture = KsefCertificateFixtureFactory::offlineRsa();
        $certificate = $this->importCertificate($fixture);
        $this->seedCachedToken(KsefEnvironment::Test);
        $this->trustSnapshot($certificate, 'Revoked');
        $now = CarbonImmutable::now('UTC')->startOfSecond();
        $validFrom = $now->subDay();
        $validUntil = $now->addDay();
        $query = [
            'certificates' => [$this->queryItem(
                $certificate,
                validFrom: $validFrom->format('Y-m-d\TH:i:s\Z'),
                validUntil: $validUntil->format('Y-m-d\TH:i:s\Z'),
            )],
            'hasMore' => false,
        ];
        $this->fakeQueryThenRetrieveFailure($query, 'server_error');

        $this->expectSafeVerificationFailure($certificate);

        $certificate->refresh();
        $this->assertSame('Active', $certificate->remote_status);
        $this->assertSame($validFrom->getTimestamp(), $certificate->remote_valid_from->getTimestamp());
        $this->assertSame($validUntil->getTimestamp(), $certificate->remote_valid_until->getTimestamp());
        $this->assertNull($certificate->remote_verified_at);
        $this->assertFalse(app(KsefOfflineCertificateReadinessService::class)->isReady($certificate));
        Http::assertSentCount(2);
    }

    public function test_fresh_active_query_and_successful_retrieve_restore_full_verification_from_non_active_state(): void
    {
        Http::preventStrayRequests();
        $fixture = KsefCertificateFixtureFactory::offlineRsa();
        $certificate = $this->importCertificate($fixture);
        $this->seedCachedToken(KsefEnvironment::Test);
        $oldVerifiedAt = $this->trustSnapshot($certificate, 'Revoked');
        $this->fakeSuccessfulVerification($certificate, $fixture);

        $verified = app(KsefOfflineCertificateRemoteVerificationService::class)->verify($certificate);

        $this->assertSame('Active', $verified->remote_status);
        $this->assertNotNull($verified->remote_verified_at);
        $this->assertNotSame($oldVerifiedAt->getTimestamp(), $verified->remote_verified_at->getTimestamp());
        $this->assertTrue(app(KsefOfflineCertificateReadinessService::class)->isReady($verified));
        Http::assertSentCount(2);
    }

    public function test_non_active_query_and_successful_retrieve_finish_full_identity_verification(): void
    {
        Http::preventStrayRequests();
        $fixture = KsefCertificateFixtureFactory::offlineRsa();
        $certificate = $this->importCertificate($fixture);
        $this->seedCachedToken(KsefEnvironment::Test);
        $oldVerifiedAt = $this->trustSnapshot($certificate);
        $this->fakeSuccessfulVerification($certificate, $fixture, status: 'Revoked');

        $verified = app(KsefOfflineCertificateRemoteVerificationService::class)->verify($certificate);

        $this->assertSame('Revoked', $verified->remote_status);
        $this->assertNotNull($verified->remote_verified_at);
        $this->assertNotSame($oldVerifiedAt->getTimestamp(), $verified->remote_verified_at->getTimestamp());
        $this->assertFalse(app(KsefOfflineCertificateReadinessService::class)->isReady($verified));
        Http::assertSentCount(2);
    }

    public function test_non_active_query_and_retrieve_fingerprint_mismatch_clear_all_remote_trust(): void
    {
        Http::preventStrayRequests();
        $fixture = KsefCertificateFixtureFactory::offlineRsa();
        $other = KsefCertificateFixtureFactory::offlineRsa();
        $certificate = $this->importCertificate($fixture);
        $this->seedCachedToken(KsefEnvironment::Test);
        $this->trustSnapshot($certificate);
        $this->fakeResponses(
            $certificate,
            ['certificates' => [$this->queryItem($certificate, 'Revoked')], 'hasMore' => false],
            ['certificates' => [$this->retrieveItem($certificate, $other)]],
        );

        $this->expectSafeVerificationFailure($certificate);

        $certificate->refresh();
        $this->assertNull($certificate->remote_status);
        $this->assertNull($certificate->remote_certificate_name);
        $this->assertNull($certificate->remote_valid_from);
        $this->assertNull($certificate->remote_valid_until);
        $this->assertNull($certificate->remote_verified_at);
        Http::assertSentCount(2);
    }

    #[DataProvider('localIdentityChanges')]
    public function test_local_identity_change_before_fresh_query_persistence_is_rejected(string $change): void
    {
        Http::preventStrayRequests();
        $fixture = KsefCertificateFixtureFactory::offlineRsa();
        $other = KsefCertificateFixtureFactory::offlineRsa(serial: 0x08F20A5D352AE599);
        $certificate = $this->importCertificate($fixture);
        $this->seedCachedToken(KsefEnvironment::Test);
        $verifiedAt = $this->trustSnapshot($certificate);
        $query = ['certificates' => [$this->queryItem($certificate)], 'hasMore' => false];

        Http::fake(function (Request $request) use ($certificate, $other, $query, $change) {
            if (str_ends_with((string) parse_url($request->url(), PHP_URL_PATH), '/certificates/query')) {
                $this->changeLocalIdentity($certificate, $other, $change);

                return Http::response($query);
            }

            return Http::response(['reasonCode' => 'RETRIEVE_MUST_NOT_RUN'], 500);
        });

        try {
            app(KsefOfflineCertificateRemoteVerificationService::class)->verify($certificate);
            $this->fail('Expected configuration race guard before fresh query persistence.');
        } catch (KsefApiException $exception) {
            $this->assertSame('offline_certificate_configuration_changed', $exception->safeCode);
        }

        $certificate->refresh();
        $this->assertSame('Active', $certificate->remote_status);
        $this->assertSame('Poprzedni zaufany snapshot', $certificate->remote_certificate_name);
        $this->assertSame($verifiedAt->getTimestamp(), $certificate->remote_verified_at->getTimestamp());
        Http::assertSentCount(1);
    }

    public static function localIdentityChanges(): array
    {
        return [
            'environment' => ['environment'],
            'serial' => ['serial'],
            'fingerprint' => ['fingerprint'],
            'certificate' => ['certificate'],
            'private key' => ['private_key'],
        ];
    }

    public function test_identity_change_during_http_prevents_remote_snapshot_persistence(): void
    {
        Http::preventStrayRequests();
        $fixture = KsefCertificateFixtureFactory::offlineRsa();
        $certificate = $this->importCertificate($fixture);
        $this->seedCachedToken(KsefEnvironment::Test);
        $query = ['certificates' => [$this->queryItem($certificate)], 'hasMore' => false];
        $retrieve = ['certificates' => [$this->retrieveItem($certificate, $fixture)]];

        Http::fake(function (Request $request) use ($certificate, $query, $retrieve) {
            if (str_ends_with((string) parse_url($request->url(), PHP_URL_PATH), '/certificates/query')) {
                return Http::response($query);
            }

            KsefOfflineCertificate::query()
                ->whereKey($certificate->getKey())
                ->update(['fingerprint_sha256' => str_repeat('B', 64)]);

            return Http::response($retrieve);
        });

        try {
            app(KsefOfflineCertificateRemoteVerificationService::class)->verify($certificate);
            $this->fail('Expected configuration race guard.');
        } catch (KsefApiException $exception) {
            $this->assertSame('offline_certificate_configuration_changed', $exception->safeCode);
        }

        $this->assertNull($certificate->fresh()->remote_verified_at);
        Http::assertSentCount(2);
    }

    public function test_offline_tab_shows_manual_post_status_and_preferred_is_not_ready(): void
    {
        Http::preventStrayRequests();
        $fixture = KsefCertificateFixtureFactory::offlineRsa();
        $certificate = $this->importCertificate($fixture);
        $verifiedAt = $this->trustSnapshot($certificate, 'Revoked');
        KsefOfflineCertificateSelection::query()->create([
            'environment' => KsefEnvironment::Test,
            'offline_certificate_id' => $certificate->getKey(),
        ]);
        $this->assertFalse(app(KsefOfflineCertificateReadinessService::class)->isReady($certificate->fresh()));

        $response = $this->get(route('integrations.ksef.edit', ['tab' => 'offline-certificates']));

        $response
            ->assertOk()
            ->assertSeeText($certificate->certificate_serial_number)
            ->assertSeeText('Unieważniony')
            ->assertSeeText($verifiedAt->format('d.m.Y H:i:s'))
            ->assertSee('data-ksef-offline-ready', false)
            ->assertSeeText('Tak')
            ->assertSeeText('Nie')
            ->assertSee('method="POST"', false)
            ->assertSee(route('integrations.ksef.offline-certificates.verify', $certificate), false)
            ->assertSee('name="_token"', false)
            ->assertSeeText('Sprawdź w KSeF')
            ->assertDontSee($fixture['private_key'])
            ->assertDontSee($fixture['certificate'])
            ->assertDontSee('subjectIdentifier');
        Http::assertNothingSent();
    }

    private function importCertificate(
        array $fixture,
        KsefEnvironment $environment = KsefEnvironment::Test,
    ): KsefOfflineCertificate {
        return app(KsefOfflineCertificateService::class)->import(
            $environment,
            'Certyfikat Offline MF',
            $fixture['certificate'],
            $fixture['private_key'],
            null,
        );
    }

    private function seedCachedToken(KsefEnvironment $environment): KsefCredential
    {
        app(KsefSettingsService::class)->get()->forceFill(['context_nip' => '1234567890'])->save();
        $authentication = KsefCertificateFixtureFactory::ec();

        return KsefCredential::query()->create([
            'environment' => $environment,
            'authentication_method' => KsefAuthenticationMethod::Certificate,
            'authentication_certificate' => $authentication['certificate'],
            'authentication_private_key' => $authentication['private_key'],
            'access_token' => 'FAKE_OFFLINE_QUERY_ACCESS_TOKEN',
            'access_token_valid_until' => now()->addMinutes(10),
        ]);
    }

    private function seedTokenCredential(KsefEnvironment $environment): KsefCredential
    {
        app(KsefSettingsService::class)->get()->forceFill(['context_nip' => '1234567890'])->save();

        return KsefCredential::query()->create([
            'environment' => $environment,
            'authentication_method' => KsefAuthenticationMethod::Token,
            'api_token' => 'FAKE_OFFLINE_QUERY_API_TOKEN',
        ]);
    }

    private function fakeSuccessfulVerification(
        KsefOfflineCertificate $certificate,
        array $fixture,
        string $status = 'Active',
        string $validFrom = '2026-09-01T10:15:00Z',
        string $validUntil = '2026-10-01T10:15:00Z',
    ): void {
        $this->fakeResponses(
            $certificate,
            [
                'certificates' => [$this->queryItem(
                    $certificate,
                    $status,
                    $validFrom,
                    $validUntil,
                )],
                'hasMore' => false,
            ],
            ['certificates' => [$this->retrieveItem($certificate, $fixture)]],
        );
    }

    private function fakeResponses(
        KsefOfflineCertificate $certificate,
        array $queryResponse,
        array $retrieveResponse,
    ): void {
        Http::fake(function (Request $request) use ($certificate, $queryResponse, $retrieveResponse) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);

            if (str_ends_with($path, '/certificates/query')) {
                return Http::response($queryResponse);
            }

            if (str_ends_with($path, '/certificates/retrieve')) {
                return Http::response($retrieveResponse);
            }

            return Http::response([
                'reasonCode' => 'UNEXPECTED_TEST_REQUEST',
                'certificateSerialNumber' => $certificate->certificate_serial_number,
            ], 500);
        });
    }

    private function fakeQueryThenRetrieveFailure(array $queryResponse, string $failure): void
    {
        Http::fake(function (Request $request) use ($queryResponse, $failure) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);

            if (str_ends_with($path, '/certificates/query')) {
                return Http::response($queryResponse);
            }

            return match ($failure) {
                'network' => (Http::failedConnection('FAKE RETRIEVE NETWORK FAILURE'))($request),
                'rate_limit' => Http::response([], 429, ['Retry-After' => '30']),
                'server_error' => Http::response([], 503),
                'malformed_schema' => Http::response(['certificates' => 'INVALID']),
            };
        });
    }

    private function changeLocalIdentity(
        KsefOfflineCertificate $certificate,
        array $other,
        string $change,
    ): void {
        $current = $certificate->fresh();

        match ($change) {
            'environment' => $current->forceFill(['environment' => KsefEnvironment::Demo])->save(),
            'serial' => $current->forceFill(['certificate_serial_number' => $other['serial']])->save(),
            'fingerprint' => $current->forceFill(['fingerprint_sha256' => str_repeat('B', 64)])->save(),
            'certificate' => $current->forceFill(['certificate_pem' => $other['certificate']])->save(),
            'private_key' => $current->forceFill(['private_key_pem' => $other['private_key']])->save(),
        };
    }

    private function queryItem(
        KsefOfflineCertificate $certificate,
        string $status = 'Active',
        string $validFrom = '2026-09-01T10:15:00Z',
        string $validUntil = '2026-10-01T10:15:00Z',
    ): array {
        return [
            'certificateSerialNumber' => $certificate->certificate_serial_number,
            'name' => 'Certyfikat Offline MF',
            'type' => 'Offline',
            'commonName' => 'NEX-OMS test fixture',
            'status' => $status,
            'subjectIdentifier' => ['type' => 'Nip', 'value' => '1234567890'],
            'validFrom' => $validFrom,
            'validTo' => $validUntil,
        ];
    }

    private function retrieveItem(KsefOfflineCertificate $certificate, array $fixture): array
    {
        return [
            'certificate' => base64_encode(KsefCertificateFixtureFactory::certificateDer($fixture['certificate'])),
            'certificateName' => 'Certyfikat Offline MF',
            'certificateSerialNumber' => $certificate->certificate_serial_number,
            'certificateType' => 'Offline',
        ];
    }

    private function trustSnapshot(
        KsefOfflineCertificate $certificate,
        string $status = 'Active',
    ): CarbonImmutable {
        $verifiedAt = now()->subHour()->toImmutable();
        $certificate->forceFill([
            'remote_status' => $status,
            'remote_certificate_name' => 'Poprzedni zaufany snapshot',
            'remote_valid_from' => now()->subDay(),
            'remote_valid_until' => now()->addDay(),
            'remote_verified_at' => $verifiedAt,
        ])->save();

        return $verifiedAt;
    }

    private function expectSafeVerificationFailure(KsefOfflineCertificate $certificate): void
    {
        try {
            app(KsefOfflineCertificateRemoteVerificationService::class)->verify($certificate);
            $this->fail('Expected controlled remote certificate verification failure.');
        } catch (KsefApiException $exception) {
            $this->assertStringNotContainsString('FAKE_OFFLINE_QUERY_ACCESS_TOKEN', $exception->getMessage());
            $this->assertNotSame('', $exception->safeCode);
        }
    }

    private function assertCertificateRequests(
        KsefOfflineCertificate $certificate,
        KsefEnvironment $environment,
    ): void {
        $baseUrl = config('ksef.base_urls.'.$environment->value);

        Http::assertSentCount(2);
        Http::assertSent(function (Request $request) use ($certificate, $baseUrl): bool {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return $request->method() === 'POST'
                && str_starts_with($request->url(), $baseUrl.'/certificates/query')
                && $request->data() === [
                    'certificateSerialNumber' => $certificate->certificate_serial_number,
                    'type' => 'Offline',
                ]
                && $query === ['pageSize' => '10', 'pageOffset' => '0']
                && $request->hasHeader('Authorization', 'Bearer FAKE_OFFLINE_QUERY_ACCESS_TOKEN');
        });
        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && $request->url() === $baseUrl.'/certificates/retrieve'
            && $request->data() === [
                'certificateSerialNumbers' => [$certificate->certificate_serial_number],
            ]
            && $request->hasHeader('Authorization', 'Bearer FAKE_OFFLINE_QUERY_ACCESS_TOKEN'));
    }

    private function assertNoMutatingCertificateRequests(): void
    {
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/certificates/enrollments')
            || str_contains($request->url(), '/revoke'));
    }
}
