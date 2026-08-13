<?php

namespace Tests\Unit\Ksef;

use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use Modules\Ksef\Services\KsefCertificateMaterialService;
use Tests\Support\KsefCertificateFixtureFactory;
use Tests\TestCase;

class KsefCertificateMaterialServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_it_accepts_and_normalizes_matching_rsa_2048_material(): void
    {
        $fixture = KsefCertificateFixtureFactory::rsa();

        $material = $this->service()->inspect($fixture['certificate'], $fixture['private_key']);

        $this->assertStringStartsWith('-----BEGIN CERTIFICATE-----', $material->certificatePem);
        $this->assertStringStartsWith('-----BEGIN PRIVATE KEY-----', $material->privateKeyPem);
        $this->assertSame('RSA 2048', $material->metadata['key_label']);
        $this->assertMatchesRegularExpression(
            '/^(?:[0-9A-F]{2}:){31}[0-9A-F]{2}$/',
            $material->metadata['fingerprint_sha256'],
        );
    }

    public function test_it_accepts_der_certificate_and_encrypted_private_key_without_persisting_passphrase(): void
    {
        $fixture = KsefCertificateFixtureFactory::rsa(passphrase: 'TEST_PASSPHRASE');

        $material = $this->service()->inspect(
            KsefCertificateFixtureFactory::certificateDer($fixture['certificate']),
            $fixture['private_key'],
            'TEST_PASSPHRASE',
        );

        $this->assertStringStartsWith('-----BEGIN CERTIFICATE-----', $material->certificatePem);
        $this->assertStringStartsWith('-----BEGIN PRIVATE KEY-----', $material->privateKeyPem);
        $this->assertStringNotContainsString('ENCRYPTED PRIVATE KEY', $material->privateKeyPem);
        $this->assertStringNotContainsString('TEST_PASSPHRASE', $material->privateKeyPem);
    }

    public function test_it_accepts_ec_p_256_material(): void
    {
        $fixture = KsefCertificateFixtureFactory::ec();

        $material = $this->service()->inspect($fixture['certificate'], $fixture['private_key']);

        $this->assertSame('EC P-256', $material->metadata['key_label']);
        $this->assertSame('P-256', $material->metadata['curve']);
    }

    public function test_it_rejects_mismatched_private_key(): void
    {
        $certificate = KsefCertificateFixtureFactory::rsa();
        $otherKey = KsefCertificateFixtureFactory::rsa();

        $this->assertValidationMessage(
            fn () => $this->service()->inspect($certificate['certificate'], $otherKey['private_key']),
            'authentication_private_key',
            'Klucz prywatny nie odpowiada wybranemu certyfikatowi.',
        );
    }

    public function test_it_rejects_invalid_certificate_and_private_key(): void
    {
        $fixture = KsefCertificateFixtureFactory::rsa();

        $this->assertValidationMessage(
            fn () => $this->service()->inspect('NOT A CERTIFICATE', $fixture['private_key']),
            'authentication_certificate',
            'Nie udało się odczytać certyfikatu X.509.',
        );
        $this->assertValidationMessage(
            fn () => $this->service()->inspect($fixture['certificate'], 'NOT A PRIVATE KEY'),
            'authentication_private_key',
            'Nie udało się odczytać klucza prywatnego.',
        );
    }

    public function test_it_rejects_incorrect_private_key_passphrase(): void
    {
        $fixture = KsefCertificateFixtureFactory::rsa(passphrase: 'CORRECT_PASSPHRASE');

        $this->assertValidationMessage(
            fn () => $this->service()->inspect(
                $fixture['certificate'],
                $fixture['private_key'],
                'WRONG_PASSPHRASE',
            ),
            'authentication_private_key',
            'Hasło klucza prywatnego jest nieprawidłowe.',
        );
    }

    public function test_it_rejects_expired_and_not_yet_valid_certificates(): void
    {
        $fixture = KsefCertificateFixtureFactory::rsa();

        CarbonImmutable::setTestNow(CarbonImmutable::createFromTimestampUTC($fixture['valid_until'] + 1));
        $this->assertValidationMessage(
            fn () => $this->service()->inspect($fixture['certificate'], $fixture['private_key']),
            'authentication_certificate',
            'Certyfikat wygasł.',
        );

        CarbonImmutable::setTestNow(CarbonImmutable::createFromTimestampUTC($fixture['valid_from'] - 1));
        $this->assertValidationMessage(
            fn () => $this->service()->inspect($fixture['certificate'], $fixture['private_key']),
            'authentication_certificate',
            'Certyfikat nie jest jeszcze ważny.',
        );
    }

    public function test_it_rejects_offline_only_usage_and_unsupported_key_parameters(): void
    {
        $offline = KsefCertificateFixtureFactory::rsa(keyUsage: 'nonRepudiation');
        $unsupported = KsefCertificateFixtureFactory::rsa(bits: 1024);

        $this->assertValidationMessage(
            fn () => $this->service()->inspect($offline['certificate'], $offline['private_key']),
            'authentication_certificate',
            'Certyfikat nie jest przeznaczony do podpisu cyfrowego.',
        );
        $this->assertValidationMessage(
            fn () => $this->service()->inspect($unsupported['certificate'], $unsupported['private_key']),
            'authentication_private_key',
            'Typ lub parametry klucza nie są obsługiwane przez KSeF.',
        );
    }

    private function service(): KsefCertificateMaterialService
    {
        return new KsefCertificateMaterialService;
    }

    private function assertValidationMessage(
        callable $callback,
        string $field,
        string $message,
    ): void {
        try {
            $callback();
            $this->fail('Expected a validation exception.');
        } catch (ValidationException $exception) {
            $this->assertSame([$message], $exception->errors()[$field]);
        }
    }
}
