<?php

namespace Tests\Unit\Ksef;

use Modules\Ksef\Enums\KsefContextIdentifierType;
use Modules\Ksef\Enums\KsefEnvironment;
use Modules\Ksef\Services\KsefEcdsaSignatureConverter;
use Modules\Ksef\Services\KsefOfflineCertificateVerificationLinkBuilder;
use Modules\Ksef\ValueObjects\KsefContextIdentifier;
use OpenSSLAsymmetricKey;
use phpseclib3\Crypt\PublicKeyLoader;
use phpseclib3\Crypt\RSA;
use phpseclib3\Crypt\RSA\PublicKey as RsaPublicKey;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\KsefCertificateFixtureFactory;
use Tests\TestCase;

class KsefOfflineCertificateVerificationLinkBuilderTest extends TestCase
{
    #[DataProvider('environments')]
    public function test_rsa_kod_ii_uses_exact_environment_path_and_pss_parameters(
        KsefEnvironment $environment,
        string $host,
    ): void {
        $fixture = KsefCertificateFixtureFactory::offlineRsa();
        $hash = $this->invoiceHash();
        $identifier = KsefContextIdentifier::make(KsefContextIdentifierType::Nip, '1111111111');
        $link = app(KsefOfflineCertificateVerificationLinkBuilder::class)->build(
            $environment,
            $identifier,
            '2222222222',
            $fixture['serial'],
            $hash,
            $fixture['private_key'],
        );
        $hashUrl = $this->base64Url(base64_decode($hash, true));
        $expectedPreSign = $host.'/certificate/Nip/1111111111/2222222222/'
            .$fixture['serial'].'/'.$hashUrl;

        $this->assertSame($expectedPreSign, $link->preSign);
        $this->assertSame('https://'.$expectedPreSign.'/'.$link->signatureBase64Url, $link->url);
        $this->assertSame($hashUrl, $link->invoiceHashBase64Url);
        $this->assertStringNotContainsString('https://', $link->preSign);
        $this->assertFalse(str_ends_with($link->preSign, '/'));
        $this->assertStringNotContainsString('=', $link->invoiceHashBase64Url);
        $this->assertStringNotContainsString('=', $link->signatureBase64Url);

        $signature = $this->base64UrlDecode($link->signatureBase64Url);
        $publicKey = $this->rsaPublicKey($fixture['certificate']);
        $verifier = $publicKey
            ->withPadding(RSA::SIGNATURE_PSS)
            ->withHash('sha256')
            ->withMGFHash('sha256')
            ->withSaltLength(32);

        $this->assertTrue($verifier->verify($link->preSign, $signature));
        $this->assertFalse($verifier->verify($link->preSign.'-tampered', $signature));
        $this->assertFalse($publicKey
            ->withPadding(RSA::SIGNATURE_PSS)
            ->withHash('sha256')
            ->withMGFHash('sha256')
            ->withSaltLength(20)
            ->verify($link->preSign, $signature));
    }

    public function test_ec_kod_ii_uses_p256_sha256_and_p1363_signature(): void
    {
        $fixture = KsefCertificateFixtureFactory::offlineEc();
        $identifier = KsefContextIdentifier::make(
            KsefContextIdentifierType::NipVatUe,
            '1111111111-IE1+12345A',
        );
        $link = app(KsefOfflineCertificateVerificationLinkBuilder::class)->build(
            KsefEnvironment::Demo,
            $identifier,
            '2222222222',
            $fixture['serial'],
            $this->invoiceHash(),
            $fixture['private_key'],
        );
        $rawSignature = $this->base64UrlDecode($link->signatureBase64Url);
        $derSignature = app(KsefEcdsaSignatureConverter::class)->rawToDer($rawSignature);

        $this->assertStringContainsString(
            '/certificate/NipVatUe/1111111111-IE1+12345A/2222222222/',
            $link->preSign,
        );
        $this->assertSame(64, strlen($rawSignature));
        $this->assertSame(1, openssl_verify(
            $link->preSign,
            $derSignature,
            $fixture['certificate'],
            OPENSSL_ALGO_SHA256,
        ));
        $this->assertSame(0, openssl_verify(
            $link->preSign.'-tampered',
            $derSignature,
            $fixture['certificate'],
            OPENSSL_ALGO_SHA256,
        ));
    }

    public function test_tampered_hash_produces_a_different_signed_path(): void
    {
        $fixture = KsefCertificateFixtureFactory::offlineRsa();
        $builder = app(KsefOfflineCertificateVerificationLinkBuilder::class);
        $identifier = KsefContextIdentifier::make(KsefContextIdentifierType::PeppolId, 'PPL123456');
        $original = $builder->build(
            KsefEnvironment::Test,
            $identifier,
            '2222222222',
            $fixture['serial'],
            $this->invoiceHash('original'),
            $fixture['private_key'],
        );
        $tampered = $builder->build(
            KsefEnvironment::Test,
            $identifier,
            '2222222222',
            $fixture['serial'],
            $this->invoiceHash('tampered'),
            $fixture['private_key'],
        );
        $signature = $this->base64UrlDecode($original->signatureBase64Url);

        $this->assertNotSame($original->preSign, $tampered->preSign);
        $this->assertFalse($this->rsaPublicKey($fixture['certificate'])
            ->withPadding(RSA::SIGNATURE_PSS)
            ->withHash('sha256')
            ->withMGFHash('sha256')
            ->withSaltLength(32)
            ->verify($tampered->preSign, $signature));
    }

    public function test_all_context_identifier_types_keep_official_canonical_path_values(): void
    {
        $fixture = KsefCertificateFixtureFactory::offlineEc();
        $values = [
            KsefContextIdentifierType::Nip->value => '1111111111',
            KsefContextIdentifierType::InternalId->value => '1111111111-12345',
            KsefContextIdentifierType::NipVatUe->value => '1111111111-IE1+12345A',
            KsefContextIdentifierType::PeppolId->value => 'PPL123456',
        ];

        foreach ($values as $type => $value) {
            $identifierType = KsefContextIdentifierType::from($type);
            $link = app(KsefOfflineCertificateVerificationLinkBuilder::class)->build(
                KsefEnvironment::Test,
                KsefContextIdentifier::make($identifierType, $value),
                '2222222222',
                $fixture['serial'],
                $this->invoiceHash(),
                $fixture['private_key'],
            );

            $this->assertStringContainsString("/certificate/{$type}/{$value}/2222222222/", $link->preSign);
        }
    }

    public static function environments(): array
    {
        return [
            'TEST' => [KsefEnvironment::Test, 'qr-test.ksef.mf.gov.pl'],
            'DEMO' => [KsefEnvironment::Demo, 'qr-demo.ksef.mf.gov.pl'],
            'PRODUCTION' => [KsefEnvironment::Production, 'qr.ksef.mf.gov.pl'],
        ];
    }

    private function invoiceHash(string $xml = '<Faktura/>'): string
    {
        return base64_encode(hash('sha256', $xml, true));
    }

    private function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function base64UrlDecode(string $value): string
    {
        return base64_decode(strtr($value, '-_', '+/').str_repeat('=', (4 - strlen($value) % 4) % 4), true);
    }

    private function rsaPublicKey(string $certificatePem): RsaPublicKey
    {
        $certificate = openssl_x509_read($certificatePem);
        $publicKey = $certificate === false ? false : openssl_pkey_get_public($certificate);
        $details = $publicKey instanceof OpenSSLAsymmetricKey
            ? openssl_pkey_get_details($publicKey)
            : false;
        $loaded = is_array($details) && is_string($details['key'] ?? null)
            ? PublicKeyLoader::loadPublicKey($details['key'])
            : null;

        $this->assertInstanceOf(RsaPublicKey::class, $loaded);

        return $loaded;
    }
}
