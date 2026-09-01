<?php

namespace Modules\Ksef\Services;

use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use Modules\Ksef\Enums\KsefOfflineCertificateKeyType;
use Modules\Ksef\ValueObjects\KsefOfflineCertificateMaterial;
use OpenSSLAsymmetricKey;
use OpenSSLCertificate;

final class KsefOfflineCertificateMaterialService
{
    public function inspect(
        string $certificateContents,
        string $privateKeyContents,
        ?string $passphrase = null,
    ): KsefOfflineCertificateMaterial {
        $certificate = $this->readCertificate($certificateContents);
        $certificateData = $this->parseCertificate($certificate);
        $privateKey = $this->readPrivateKey($privateKeyContents, $passphrase);

        if (! openssl_x509_check_private_key($certificate, $privateKey)) {
            $this->fail('offline_private_key', 'Klucz prywatny nie odpowiada wybranemu certyfikatowi Offline.');
        }

        $publicKey = openssl_pkey_get_public($certificate);
        $details = $publicKey instanceof OpenSSLAsymmetricKey
            ? openssl_pkey_get_details($publicKey)
            : false;

        if (! is_array($details)) {
            $this->fail('offline_certificate', 'Nie udało się odczytać parametrów certyfikatu Offline.');
        }

        [$keyType, $keySize, $curve] = $this->supportedKey($details);
        [$validFrom, $validUntil] = $this->validity($certificateData);
        $this->assertOfflineKeyUsage($certificateData);
        $serial = $this->certificateSerialNumber($certificateData);
        $fingerprint = openssl_x509_fingerprint($certificate, 'sha256');

        if (! is_string($fingerprint)) {
            $this->fail('offline_certificate', 'Nie udało się odczytać fingerprintu certyfikatu Offline.');
        }

        $certificatePem = '';

        if (! openssl_x509_export($certificate, $certificatePem)) {
            $this->fail('offline_certificate', 'Nie udało się przygotować certyfikatu Offline do bezpiecznego zapisu.');
        }

        return new KsefOfflineCertificateMaterial(
            $certificatePem,
            $this->exportPrivateKey($privateKey),
            $serial,
            $validFrom,
            $validUntil,
            strtoupper(str_replace(':', '', $fingerprint)),
            $keyType,
            $keySize,
            $curve,
        );
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
            $this->fail('offline_certificate', 'Nie udało się odczytać certyfikatu Offline X.509.');
        }

        return $certificate;
    }

    private function parseCertificate(OpenSSLCertificate $certificate): array
    {
        $certificateData = openssl_x509_parse($certificate, false);

        if (! is_array($certificateData)) {
            $this->fail('offline_certificate', 'Nie udało się odczytać certyfikatu Offline X.509.');
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
                    ? 'Hasło klucza prywatnego certyfikatu Offline jest nieprawidłowe.'
                    : 'Nie udało się odczytać klucza prywatnego certyfikatu Offline.';

            $this->fail('offline_private_key', $message);
        }

        return $privateKey;
    }

    private function supportedKey(array $details): array
    {
        $type = $details['type'] ?? null;
        $bits = $details['bits'] ?? null;

        if ($type === OPENSSL_KEYTYPE_RSA && $bits === 2048) {
            return [KsefOfflineCertificateKeyType::Rsa, 2048, null];
        }

        $curve = is_array($details['ec'] ?? null)
            ? ($details['ec']['curve_name'] ?? null)
            : null;

        if ($type === OPENSSL_KEYTYPE_EC
            && $bits === 256
            && is_string($curve)
            && in_array(strtolower($curve), ['prime256v1', 'secp256r1'], true)) {
            return [KsefOfflineCertificateKeyType::Ec, 256, 'P-256'];
        }

        $this->fail(
            'offline_private_key',
            'Certyfikat Offline musi używać klucza RSA 2048 albo EC P-256.',
        );
    }

    private function validity(array $certificateData): array
    {
        $validFrom = $certificateData['validFrom_time_t'] ?? null;
        $validUntil = $certificateData['validTo_time_t'] ?? null;

        if (! is_int($validFrom) || ! is_int($validUntil)) {
            $this->fail('offline_certificate', 'Nie udało się odczytać okresu ważności certyfikatu Offline.');
        }

        $now = CarbonImmutable::now('UTC')->getTimestamp();

        if ($now < $validFrom) {
            $this->fail('offline_certificate', 'Certyfikat Offline nie jest jeszcze ważny.');
        }

        if ($now > $validUntil) {
            $this->fail('offline_certificate', 'Certyfikat Offline wygasł.');
        }

        return [
            CarbonImmutable::createFromTimestampUTC($validFrom),
            CarbonImmutable::createFromTimestampUTC($validUntil),
        ];
    }

    private function assertOfflineKeyUsage(array $certificateData): void
    {
        $keyUsage = $certificateData['extensions']['keyUsage'] ?? null;

        if (! is_string($keyUsage)) {
            $this->fail('offline_certificate', 'Certyfikat nie ma wymaganego użycia Offline.');
        }

        $usages = array_map(
            fn (string $usage): string => strtolower((string) preg_replace('/[^a-z]/i', '', trim($usage))),
            explode(',', $keyUsage),
        );

        $hasOfflineUsage = in_array('nonrepudiation', $usages, true)
            || in_array('contentcommitment', $usages, true);

        if (! $hasOfflineUsage || in_array('digitalsignature', $usages, true)) {
            $this->fail(
                'offline_certificate',
                'Certyfikat nie jest certyfikatem Offline (wymagane Non-Repudiation / Content Commitment).',
            );
        }
    }

    private function certificateSerialNumber(array $certificateData): string
    {
        $serial = strtoupper((string) ($certificateData['serialNumberHex'] ?? ''));

        if (preg_match('/^[0-9A-F]{16}$/D', $serial) !== 1) {
            $this->fail(
                'offline_certificate',
                'Numer seryjny certyfikatu Offline musi mieć 16 znaków HEX.',
            );
        }

        return $serial;
    }

    private function exportPrivateKey(OpenSSLAsymmetricKey $privateKey): string
    {
        $configPath = tempnam(sys_get_temp_dir(), 'nex-ksef-offline-openssl-');

        if (! is_string($configPath)) {
            $this->fail('offline_private_key', 'Nie udało się przygotować klucza prywatnego do bezpiecznego zapisu.');
        }

        file_put_contents($configPath, "[ req ]\ndistinguished_name = req_distinguished_name\n[ req_distinguished_name ]\n");
        $privateKeyPem = '';

        try {
            if (! openssl_pkey_export($privateKey, $privateKeyPem, null, ['config' => $configPath])) {
                $this->fail('offline_private_key', 'Nie udało się przygotować klucza prywatnego do bezpiecznego zapisu.');
            }
        } finally {
            @unlink($configPath);
        }

        return $privateKeyPem;
    }

    private function clearOpenSslErrors(): void
    {
        while (openssl_error_string() !== false) {
            // OpenSSL diagnostics are discarded because they may contain key details.
        }
    }

    private function fail(string $field, string $message): never
    {
        $this->clearOpenSslErrors();

        throw ValidationException::withMessages([$field => $message]);
    }
}
