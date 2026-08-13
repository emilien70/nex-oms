<?php

namespace Tests\Unit\Ksef;

use DOMDocument;
use DOMXPath;
use Modules\Ksef\Services\KsefAuthTokenRequestBuilder;
use Tests\TestCase;

class KsefAuthTokenRequestBuilderTest extends TestCase
{
    public function test_it_builds_auth_token_request_2_1_for_certificate_subject(): void
    {
        $xml = app(KsefAuthTokenRequestBuilder::class)->build('CHALLENGE-123', '1234567890');
        $document = new DOMDocument;

        $this->assertTrue($document->loadXML($xml, LIBXML_NONET));
        $this->assertSame(KsefAuthTokenRequestBuilder::NAMESPACE, $document->documentElement?->namespaceURI);
        $this->assertSame('AuthTokenRequest', $document->documentElement?->localName);

        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('auth', KsefAuthTokenRequestBuilder::NAMESPACE);
        $this->assertSame('CHALLENGE-123', $xpath->evaluate('string(/auth:AuthTokenRequest/auth:Challenge)'));
        $this->assertSame('1234567890', $xpath->evaluate('string(/auth:AuthTokenRequest/auth:ContextIdentifier/auth:Nip)'));
        $this->assertSame('certificateSubject', $xpath->evaluate('string(/auth:AuthTokenRequest/auth:SubjectIdentifierType)'));
        $this->assertSame(0, $xpath->query('//auth:AuthorizationPolicy')?->length);
        $this->assertStringNotContainsString('PRIVATE KEY', $xml);
        $this->assertStringNotContainsString('api_token', $xml);
    }
}
