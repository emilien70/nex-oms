<?php

namespace Modules\Ksef\Services;

use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use Modules\Ksef\ValueObjects\KsefCertificateMaterial;
use OpenSSLAsymmetricKey;
use OpenSSLCertificate;

final class KsefCertificateMaterialService
{
    public function inspect(
        string $certificateContents,
        string $privateKeyContents,
        ?string $passphrase = null,
    ): KsefCertificateMaterial {
        $certificate = $this->readCertificate($certificateContents);
        $certificateData = $this->parseCertificate($certificate);
        $privateKey = $this->readPrivateKey($privateKeyContents, $passphrase);
        $certificatePublicKey = openssl_pkey_get_public($certificate);

        if (! $certificatePublicKey instanceof OpenSSLAsymmetricKey) {
            $this->fail('authentication_certificate', 'Nie udało się odczytać klucza publicznego certyfikatu.');
        }

        $certificateKeyDetails = openssl_pkey_get_details($certificatePublicKey);
        $privateKeyDetails = openssl_pkey_get_details($privateKey);

        if (! is_array($certificateKeyDetails) || ! is_array($privateKeyDetails)) {
            $this->fail('authentication_private_key', 'Nie udało się odczytać parametrów klucza prywatnego.');
        }

        $this->assertMatchingKeyPair($certificateKeyDetails, $privateKeyDetails);
        $keyMetadata = $this->assertSupportedKey($certificateKeyDetails);
        $this->assertValidity($certificateData);
        $this->assertDigitalSignatureUsage($certificateData);

        $certificatePem = '';

        if (! openssl_x509_export($certificate, $certificatePem)) {
            $this->fail('authentication_private_key', 'Nie udało się przygotować materiału certyfikatu do bezpiecznego zapisu.');
        }

        $privateKeyPem = $this->exportPrivateKey($privateKey);

        return new KsefCertificateMaterial(
            $certificatePem,
            $privateKeyPem,
            $this->metadataFromParsedCertificate($certificate, $certificateData, $keyMetadata),
        );
    }

    public function metadata(string $certificatePem): ?array
    {
        try {
            $certificate = $this->readCertificate($certificatePem);
            $certificateData = $this->parseCertificate($certificate);
            $publicKey = openssl_pkey_get_public($certificate);

            if (! $publicKey instanceof OpenSSLAsymmetricKey) {
                return null;
            }

            $details = openssl_pkey_get_details($publicKey);

            if (! is_array($details)) {
                return null;
            }

            return $this->metadataFromParsedCertificate(
                $certificate,
                $certificateData,
                $this->keyMetadata($details),
            );
        } catch (ValidationException) {
            return null;
        }
    }

    private function readCertificate(string $contents): OpenSSLCertificate
    {
        $this->clearOpenSslErrors();
        $candidate = str_contains($contents, '-----BEGIN CERTIFICATE-----')
            ? $contents
            : "-----BEGIN CERTIFICATE-----\n"
                .chunk_split(base64_encode($contents), 64, "\n")
                ."-----END CERTIFICATE-----\n";
        $certificate = @openssl_x509_read($candidate);

        if (! $certificate instanceof OpenSSLCertificate) {
            $this->fail('authentication_certificate', 'Nie udało się odczytać certyfikatu X.509.');
        }

        return $certificate;
    }

    private function parseCertificate(OpenSSLCertificate $certificate): array
    {
        $certificateData = openssl_x509_parse($certificate, false);

        if (! is_array($certificateData)) {
            $this->fail('authentication_certificate', 'Nie udało się odczytać certyfikatu X.509.');
        }

        return $certificateData;
    }

    private function readPrivateKey(string $contents, ?string $passphrase): OpenSSLAsymmetricKey
    {
        $this->clearOpenSslErrors();
        $privateKey = @openssl_pkey_get_private($contents, $passphrase ?? '');

        if (! $privateKey instanceof OpenSSLAsymmetricKey) {
            $message = str_contains($contents, 'ENCRYPTED PRIVATE KEY')
                || str_contains($contents, 'Proc-Type: 4,ENCRYPTED')
                    ? 'Hasło klucza prywatnego jest nieprawidłowe.'
                    : 'Nie udało się odczytać klucza prywatnego.';

            $this->fail('authentication_private_key', $message);
        }

        return $privateKey;
    }

    private function assertMatchingKeyPair(array $certificateDetails, array $privateKeyDetails): void
    {
        $certificatePublicKey = $this->publicKeyDigest($certificateDetails['key'] ?? null);
        $privatePublicKey = $this->publicKeyDigest($privateKeyDetails['key'] ?? null);

        if ($certificatePublicKey === null
            || $privatePublicKey === null
            || ! hash_equals($certificatePublicKey, $privatePublicKey)) {
            $this->fail(
                'authentication_private_key',
                'Klucz prywatny nie odpowiada wybranemu certyfikatowi.',
            );
        }
    }

    private function assertSupportedKey(array $details): array
    {
        $metadata = $this->keyMetadata($details);

        if ($metadata === null) {
            $this->fail(
                'authentication_private_key',
                'Typ lub parametry klucza nie są obsługiwane przez KSeF.',
            );
        }

        return $metadata;
    }

    private function keyMetadata(array $details): ?array
    {
        $type = $details['type'] ?? null;
        $bits = $details['bits'] ?? null;

        if ($type === OPENSSL_KEYTYPE_RSA && $bits === 2048) {
            return [
                'key_type' => 'RSA',
                'key_size' => 2048,
                'curve' => null,
                'key_label' => 'RSA 2048',
            ];
        }

        $curve = is_array($details['ec'] ?? null)
            ? ($details['ec']['curve_name'] ?? null)
            : null;

        if ($type === OPENSSL_KEYTYPE_EC
            && is_string($curve)
            && in_array(strtolower($curve), ['prime256v1', 'secp256r1'], true)) {
            return [
                'key_type' => 'EC',
                'key_size' => 256,
                'curve' => 'P-256',
                'key_label' => 'EC P-256',
            ];
        }

        return null;
    }

    private function assertValidity(array $certificateData): void
    {
        $validFrom = $certificateData['validFrom_time_t'] ?? null;
        $validUntil = $certificateData['validTo_time_t'] ?? null;

        if (! is_int($validFrom) || ! is_int($validUntil)) {
            $this->fail('authentication_certificate', 'Nie udało się odczytać okresu ważności certyfikatu.');
        }

        $now = CarbonImmutable::now('UTC')->getTimestamp();

        if ($now < $validFrom) {
            $this->fail('authentication_certificate', 'Certyfikat nie jest jeszcze ważny.');
        }

        if ($now > $validUntil) {
            $this->fail('authentication_certificate', 'Certyfikat wygasł.');
        }
    }

    private function assertDigitalSignatureUsage(array $certificateData): void
    {
        $keyUsage = $certificateData['extensions']['keyUsage'] ?? null;

        if (! is_string($keyUsage)
            || ! in_array('digital signature', array_map(
                fn (string $usage): string => strtolower(trim($usage)),
                explode(',', $keyUsage),
            ), true)) {
            $this->fail(
                'authentication_certificate',
                'Certyfikat nie jest przeznaczony do podpisu cyfrowego.',
            );
        }
    }

    private function metadataFromParsedCertificate(
        OpenSSLCertificate $certificate,
        array $certificateData,
        ?array $keyMetadata,
    ): array {
        $fingerprint = openssl_x509_fingerprint($certificate, 'sha256');

        return array_merge($keyMetadata ?? [], [
            'valid_from' => $this->formattedTimestamp($certificateData['validFrom_time_t'] ?? null),
            'valid_until' => $this->formattedTimestamp($certificateData['validTo_time_t'] ?? null),
            'fingerprint_sha256' => is_string($fingerprint)
                ? strtoupper(implode(':', str_split($fingerprint, 2)))
                : null,
        ]);
    }

    private function formattedTimestamp(mixed $timestamp): ?string
    {
        return is_int($timestamp)
            ? CarbonImmutable::createFromTimestampUTC($timestamp)->format('d.m.Y H:i')
            : null;
    }

    private function publicKeyDigest(mixed $publicKeyPem): ?string
    {
        if (! is_string($publicKeyPem)) {
            return null;
        }

        $der = base64_decode((string) preg_replace(
            '/-----BEGIN PUBLIC KEY-----|-----END PUBLIC KEY-----|\s+/',
            '',
            $publicKeyPem,
        ), true);

        return is_string($der) ? hash('sha256', $der, true) : null;
    }

    private function exportPrivateKey(OpenSSLAsymmetricKey $privateKey): string
    {
        $configPath = tempnam(sys_get_temp_dir(), 'nex-ksef-openssl-');

        if (! is_string($configPath)) {
            $this->fail('authentication_private_key', 'Nie udało się przygotować klucza prywatnego do bezpiecznego zapisu.');
        }

        file_put_contents($configPath, "[ req ]\ndistinguished_name = req_distinguished_name\n[ req_distinguished_name ]\n");
        $privateKeyPem = '';

        try {
            if (! openssl_pkey_export($privateKey, $privateKeyPem, null, ['config' => $configPath])) {
                $this->fail('authentication_private_key', 'Nie udało się przygotować klucza prywatnego do bezpiecznego zapisu.');
            }
        } finally {
            @unlink($configPath);
        }

        return $privateKeyPem;
    }

    private function clearOpenSslErrors(): void
    {
        while (openssl_error_string() !== false) {
            // OpenSSL details are intentionally discarded to avoid leaking material.
        }
    }

    private function fail(string $field, string $message): never
    {
        $this->clearOpenSslErrors();

        throw ValidationException::withMessages([$field => $message]);
    }
}
