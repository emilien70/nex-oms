<?php

namespace Tests\Unit\Ksef;

use Illuminate\Support\Facades\Http;
use Modules\Ksef\Enums\KsefEnvironment;
use Modules\Ksef\Exceptions\KsefApiException;
use Modules\Ksef\Services\KsefHttpClient;
use Tests\TestCase;

class KsefHttpClientTest extends TestCase
{
    public function test_each_environment_uses_its_official_v2_base_url_and_required_headers(): void
    {
        Http::preventStrayRequests();
        Http::fake(['*' => Http::response(['challenge' => 'ok'])]);
        $client = app(KsefHttpClient::class);

        foreach ([
            [KsefEnvironment::Test, 'https://api-test.ksef.mf.gov.pl/v2/auth/challenge'],
            [KsefEnvironment::Demo, 'https://api-demo.ksef.mf.gov.pl/v2/auth/challenge'],
            [KsefEnvironment::Production, 'https://api.ksef.mf.gov.pl/v2/auth/challenge'],
        ] as [$environment, $expectedUrl]) {
            $client->post($environment, '/auth/challenge');
            Http::assertSent(fn ($request): bool => $request->url() === $expectedUrl
                && $request->hasHeader('Accept', 'application/json')
                && $request->hasHeader('X-Error-Format', 'problem-details'));
        }

        Http::assertSentCount(3);
    }

    public function test_rate_limit_uses_retry_after_without_retrying(): void
    {
        Http::preventStrayRequests();
        Http::fake(['*' => Http::response([
            'status' => 429,
            'title' => 'Too many requests',
            'detail' => 'Do not expose raw response details',
            'reasonCode' => 'RATE_LIMIT',
        ], 429, [
            'Content-Type' => 'application/problem+json',
            'Retry-After' => '30',
        ])]);

        try {
            app(KsefHttpClient::class)->post(KsefEnvironment::Test, '/auth/challenge');
            $this->fail('Expected KsefApiException was not thrown.');
        } catch (KsefApiException $exception) {
            $this->assertSame('rate_limited', $exception->safeCode);
            $this->assertSame('RATE_LIMIT', $exception->reasonCode);
            $this->assertSame(30, $exception->retryAfterSeconds);
            $this->assertStringContainsString('30 s', $exception->getMessage());
            $this->assertStringNotContainsString('raw response', $exception->getMessage());
        }

        Http::assertSentCount(1);
    }

    public function test_problem_details_are_reduced_to_safe_metadata_without_exposing_raw_detail(): void
    {
        Http::preventStrayRequests();
        Http::fake(['*' => Http::response([
            'status' => 403,
            'title' => 'Forbidden',
            'detail' => 'Raw diagnostic body with SECRET_ACCESS_TOKEN_789',
            'reasonCode' => 'AUTH_FORBIDDEN',
            'errors' => [['description' => 'Sensitive detail']],
            'timestamp' => now()->toIso8601String(),
            'unknownFutureField' => ['accepted' => true],
        ], 403, ['Content-Type' => 'application/problem+json'])]);

        try {
            app(KsefHttpClient::class)->get(
                KsefEnvironment::Test,
                '/protected',
                'SECRET_ACCESS_TOKEN_789',
            );
            $this->fail('Expected safe API failure.');
        } catch (KsefApiException $exception) {
            $this->assertSame(403, $exception->httpStatus);
            $this->assertSame('http_403', $exception->safeCode);
            $this->assertSame('AUTH_FORBIDDEN', $exception->reasonCode);
            $this->assertStringNotContainsString('Raw diagnostic', $exception->getMessage());
            $this->assertStringNotContainsString('SECRET_ACCESS_TOKEN_789', $exception->getMessage());
        }

        Http::assertSentCount(1);
    }
}
