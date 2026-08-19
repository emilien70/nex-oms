<?php

namespace Tests\Unit\Ksef;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Modules\Ksef\Enums\KsefEnvironment;
use Modules\Ksef\Exceptions\KsefApiException;
use Modules\Ksef\Models\KsefInvoiceSubmission;
use Modules\Ksef\Services\KsefHttpClient;
use Modules\Ksef\Services\KsefOnlineSessionRequestFactory;
use Modules\Ksef\ValueObjects\KsefOnlineSessionEncryptionData;
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

    public function test_system_warning_redacts_request_encrypted_token_and_bearer_token(): void
    {
        $encryptedToken = 'TEST_ENCRYPTED_TOKEN_SHOULD_NEVER_LEAK';
        $bearerToken = 'TEST_BEARER_TOKEN_SHOULD_NEVER_LEAK';
        Http::preventStrayRequests();
        Http::fake(['*' => Http::response(['reasonCode' => 'AUTH_FAILED'], 403, [
            'X-System-Warning' => "diagnostic {$encryptedToken} bearer={$bearerToken} code=ABC",
        ])]);

        try {
            app(KsefHttpClient::class)->post(
                KsefEnvironment::Test,
                '/auth/ksef-token',
                ['encryptedToken' => $encryptedToken],
                $bearerToken,
            );
            $this->fail('Expected safe API failure.');
        } catch (KsefApiException $exception) {
            $this->assertSame(
                'diagnostic [ukryto] bearer=[ukryto] code=ABC',
                $exception->systemWarning,
            );
            $this->assertStringNotContainsString($encryptedToken, $exception->systemWarning);
            $this->assertStringNotContainsString($bearerToken, $exception->systemWarning);
        }

        Http::assertSentCount(1);
    }

    public function test_post_xml_uses_xml_content_type_parses_json_and_redacts_signature_value(): void
    {
        $signature = 'FAKE_XML_SIGNATURE_SHOULD_NEVER_LEAK';
        $xml = '<?xml version="1.0" encoding="UTF-8"?>'
            .'<AuthTokenRequest><ds:Signature xmlns:ds="http://www.w3.org/2000/09/xmldsig#">'
            .'<ds:SignatureValue>'.$signature.'</ds:SignatureValue>'
            .'</ds:Signature></AuthTokenRequest>';
        Http::preventStrayRequests();
        Http::fake(['*' => Http::response([
            'referenceNumber' => 'AUTH-REFERENCE',
            'authenticationToken' => ['token' => 'AUTH-TOKEN'],
        ], 202, [
            'X-System-Warning' => 'signature='.$signature.' code=ABC',
        ])]);

        $response = app(KsefHttpClient::class)->postXml(
            KsefEnvironment::Test,
            '/auth/xades-signature',
            $xml,
        );

        $this->assertSame('AUTH-REFERENCE', $response->data['referenceNumber']);
        $this->assertSame('signature=[ukryto] code=ABC', $response->systemWarning);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && str_ends_with($request->url(), '/auth/xades-signature')
            && $request->hasHeader('Content-Type', 'application/xml')
            && $request->hasHeader('Accept', 'application/json')
            && $request->hasHeader('X-Error-Format', 'problem-details')
            && $request->body() === $xml);
        Http::assertSentCount(1);
    }

    public function test_online_submission_secrets_are_redacted_and_no_content_is_accepted(): void
    {
        $encryptedKey = 'FAKE_ENCRYPTED_SYMMETRIC_KEY_SECRET';
        $encryptedInvoice = 'FAKE_ENCRYPTED_INVOICE_CONTENT_SECRET';
        $bearer = 'FAKE_SUBMISSION_BEARER_SECRET';
        $encryption = new KsefOnlineSessionEncryptionData(
            encryptedSymmetricKey: $encryptedKey,
            initializationVector: 'FAKE_INITIALIZATION_VECTOR',
            publicKeyId: 'FAKE_PUBLIC_KEY_ID',
            encryptedInvoiceContent: $encryptedInvoice,
            encryptedInvoiceHash: 'FAKE_ENCRYPTED_INVOICE_HASH',
            encryptedInvoiceSize: 123,
            cipherKey: 'FAKE_TRANSIENT_CIPHER_KEY',
            cipherIv: 'FAKE_TRANSIENT_CIPHER_IV',
        );
        $factory = app(KsefOnlineSessionRequestFactory::class);
        $submission = new KsefInvoiceSubmission;
        $submission->forceFill([
            'invoice_hash' => 'FAKE_PLAIN_INVOICE_HASH',
            'invoice_size' => 100,
        ]);
        Http::preventStrayRequests();
        Http::fakeSequence()
            ->push(['reasonCode' => 'OPEN_FAILED'], 403, [
                'X-System-Warning' => "key={$encryptedKey} bearer={$bearer}",
            ])
            ->push(['reasonCode' => 'SEND_FAILED'], 403, [
                'X-System-Warning' => "invoice={$encryptedInvoice} bearer={$bearer}",
            ])
            ->push(null, 204);

        try {
            app(KsefHttpClient::class)->post(
                KsefEnvironment::Test,
                '/sessions/online',
                $factory->openSession($encryption),
                $bearer,
            );
            $this->fail('Expected safe API failure.');
        } catch (KsefApiException $exception) {
            $this->assertSame('key=[ukryto] bearer=[ukryto]', $exception->systemWarning);
            $this->assertStringNotContainsString($encryptedKey, $exception->systemWarning);
            $this->assertStringNotContainsString($bearer, $exception->systemWarning);
        }

        try {
            app(KsefHttpClient::class)->post(
                KsefEnvironment::Test,
                '/sessions/online/SESSION/invoices',
                $factory->sendInvoice($submission, $encryption),
                $bearer,
            );
            $this->fail('Expected safe invoice send failure.');
        } catch (KsefApiException $exception) {
            $this->assertSame('invoice=[ukryto] bearer=[ukryto]', $exception->systemWarning);
            $this->assertStringNotContainsString($encryptedInvoice, $exception->systemWarning);
            $this->assertStringNotContainsString($bearer, $exception->systemWarning);
        }

        $response = app(KsefHttpClient::class)->post(
            KsefEnvironment::Test,
            '/sessions/online/SESSION/close',
            bearerToken: $bearer,
        );

        $this->assertSame([], $response->data);
        Http::assertSentCount(3);
    }
}
