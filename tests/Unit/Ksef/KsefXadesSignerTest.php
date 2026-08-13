<?php

namespace Tests\Unit\Ksef;

use DOMDocument;
use DOMElement;
use DOMXPath;
use Modules\Ksef\Services\KsefAuthTokenRequestBuilder;
use Modules\Ksef\Services\KsefXadesSigner;
use phpseclib3\File\X509;
use phpseclib3\Math\BigInteger;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\KsefCertificateFixtureFactory;
use Tests\TestCase;

class KsefXadesSignerTest extends TestCase
{
    #[DataProvider('certificateKinds')]
    public function test_xades_signature_and_references_are_cryptographically_valid(string $kind): void
    {
        $fixture = KsefCertificateFixtureFactory::$kind();
        $unsigned = app(KsefAuthTokenRequestBuilder::class)->build('CHALLENGE-123', '1234567890');
        $signed = app(KsefXadesSigner::class)->sign(
            $unsigned,
            $fixture['certificate'],
            $fixture['private_key'],
        );
        $document = $this->document($signed);
        $xpath = $this->xpath($document);
        $signedInfo = $xpath->query('//ds:SignedInfo')?->item(0);
        $signatureValue = base64_decode(trim($xpath->evaluate('string(//ds:SignatureValue)')), true);

        $this->assertInstanceOf(DOMElement::class, $signedInfo);
        $this->assertIsString($signatureValue);
        $this->assertSame(
            $kind === 'ec'
                ? 'http://www.w3.org/2001/04/xmldsig-more#ecdsa-sha256'
                : 'http://www.w3.org/2001/04/xmldsig-more#rsa-sha256',
            $xpath->evaluate('string(//ds:SignatureMethod/@Algorithm)'),
        );

        if ($kind === 'ec') {
            $this->assertSame(64, strlen($signatureValue));
            $signatureValue = $this->rawEcdsaToDer($signatureValue);
        } else {
            $this->assertSame(256, strlen($signatureValue));
        }

        $publicKey = openssl_pkey_get_public($fixture['certificate']);
        $this->assertSame(1, openssl_verify(
            $signedInfo->C14N(true, false),
            $signatureValue,
            $publicKey,
            OPENSSL_ALGO_SHA256,
        ));

        $rootReferenceDigest = $xpath->evaluate('string(//ds:Reference[@URI=""]/ds:DigestValue)');
        $copy = $document->cloneNode(true);
        $copyXpath = $this->xpath($copy);
        $signature = $copyXpath->query('//ds:Signature')?->item(0);
        $signature?->parentNode?->removeChild($signature);
        $this->assertSame(
            $rootReferenceDigest,
            base64_encode(hash('sha256', $copy->documentElement->C14N(true, false), true)),
        );

        $signedProperties = $xpath->query('//*[@Id="SignedProperties"]')?->item(0);
        $this->assertInstanceOf(DOMElement::class, $signedProperties);
        $this->assertSame(
            $xpath->evaluate('string(//ds:Reference[@URI="#SignedProperties"]/ds:DigestValue)'),
            base64_encode(hash('sha256', $signedProperties->C14N(true, false), true)),
        );
        $this->assertSame(
            KsefXadesSigner::SIGNED_PROPERTIES_TYPE,
            $xpath->evaluate('string(//ds:Reference[@URI="#SignedProperties"]/@Type)'),
        );
        $this->assertSame(
            base64_encode(KsefCertificateFixtureFactory::certificateDer($fixture['certificate'])),
            $xpath->evaluate('string(//ds:X509Certificate)'),
        );
        $this->assertSame(
            base64_encode(hash(
                'sha256',
                KsefCertificateFixtureFactory::certificateDer($fixture['certificate']),
                true,
            )),
            $xpath->evaluate('string(//xades:SigningCertificate//ds:DigestValue)'),
        );

        $x509 = new X509;
        $x509->loadX509($fixture['certificate']);
        $this->assertSame(
            $x509->getIssuerDN(X509::DN_STRING),
            $xpath->evaluate('string(//ds:X509IssuerName)'),
        );
        $parsed = openssl_x509_parse($fixture['certificate'], false);
        $this->assertSame(
            (new BigInteger($parsed['serialNumberHex'], 16))->toString(),
            $xpath->evaluate('string(//ds:X509SerialNumber)'),
        );
        $this->assertNotSame('', $xpath->evaluate('string(//xades:SigningTime)'));
        $this->assertStringNotContainsString('PRIVATE KEY', $signed);
    }

    public static function certificateKinds(): array
    {
        return [
            'EC P-256' => ['ec'],
            'RSA 2048' => ['rsa'],
        ];
    }

    private function document(string $xml): DOMDocument
    {
        $document = new DOMDocument;
        $this->assertTrue($document->loadXML($xml, LIBXML_NONET));

        return $document;
    }

    private function xpath(DOMDocument $document): DOMXPath
    {
        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('ds', KsefXadesSigner::DS_NAMESPACE);
        $xpath->registerNamespace('xades', KsefXadesSigner::XADES_NAMESPACE);

        return $xpath;
    }

    private function rawEcdsaToDer(string $signature): string
    {
        $size = intdiv(strlen($signature), 2);
        $r = $this->derInteger(substr($signature, 0, $size));
        $s = $this->derInteger(substr($signature, $size));
        $sequence = "\x02".chr(strlen($r)).$r."\x02".chr(strlen($s)).$s;

        return "\x30".chr(strlen($sequence)).$sequence;
    }

    private function derInteger(string $value): string
    {
        $value = ltrim($value, "\0");
        $value = $value === '' ? "\0" : $value;

        return (ord($value[0]) & 0x80) !== 0 ? "\0".$value : $value;
    }
}
