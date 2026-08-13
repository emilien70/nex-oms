<?php

namespace Tests\Support;

use OpenSSLAsymmetricKey;
use OpenSSLCertificate;
use OpenSSLCertificateSigningRequest;
use RuntimeException;

final class KsefCertificateFixtureFactory
{
    public static function rsa(
        int $bits = 2048,
        string $keyUsage = 'digitalSignature',
        ?string $passphrase = null,
    ): array {
        return self::create([
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
            'private_key_bits' => $bits,
        ], $keyUsage, $passphrase);
    }

    public static function ec(?string $passphrase = null, ?string $subjectSerialNumber = null): array
    {
        return self::create([
            'private_key_type' => OPENSSL_KEYTYPE_EC,
            'curve_name' => 'prime256v1',
        ], 'digitalSignature', $passphrase, $subjectSerialNumber);
    }

    public static function certificateDer(string $certificatePem): string
    {
        $base64 = preg_replace(
            '/-----BEGIN CERTIFICATE-----|-----END CERTIFICATE-----|\s+/',
            '',
            $certificatePem,
        );
        $der = base64_decode((string) $base64, true);

        if (! is_string($der)) {
            throw new RuntimeException('Could not convert test certificate to DER.');
        }

        return $der;
    }

    private static function create(
        array $keyOptions,
        string $keyUsage,
        ?string $passphrase,
        ?string $subjectSerialNumber = null,
    ): array {
        $configPath = tempnam(sys_get_temp_dir(), 'nex-ksef-openssl-');

        if (! is_string($configPath)) {
            throw new RuntimeException('Could not create temporary OpenSSL config.');
        }

        file_put_contents($configPath, self::configuration($keyUsage));

        try {
            $options = array_merge([
                'private_key_bits' => 2048,
            ], $keyOptions, [
                'config' => $configPath,
                'digest_alg' => 'sha256',
                'req_extensions' => 'v3_req',
                'x509_extensions' => 'v3_req',
            ]);
            $privateKey = openssl_pkey_new($options);

            if (! $privateKey instanceof OpenSSLAsymmetricKey) {
                throw new RuntimeException('Could not generate test private key.');
            }

            $subject = [
                'commonName' => 'NEX-OMS KSeF Test Certificate',
                'countryName' => 'PL',
            ];

            if ($subjectSerialNumber !== null) {
                $subject['serialNumber'] = $subjectSerialNumber;
            }

            $csr = openssl_csr_new($subject, $privateKey, $options);

            if (! $csr instanceof OpenSSLCertificateSigningRequest) {
                throw new RuntimeException('Could not generate test CSR.');
            }

            $certificate = openssl_csr_sign($csr, null, $privateKey, 30, $options);

            if (! $certificate instanceof OpenSSLCertificate) {
                throw new RuntimeException('Could not generate test certificate.');
            }

            $certificatePem = '';
            $privateKeyPem = '';

            if (! openssl_x509_export($certificate, $certificatePem)) {
                throw new RuntimeException('Could not export test certificate.');
            }

            if (! openssl_pkey_export($privateKey, $privateKeyPem, $passphrase, $options)) {
                throw new RuntimeException('Could not export test private key.');
            }

            $parsed = openssl_x509_parse($certificate, false);

            if (! is_array($parsed)) {
                throw new RuntimeException('Could not parse generated test certificate.');
            }

            return [
                'certificate' => $certificatePem,
                'private_key' => $privateKeyPem,
                'valid_from' => $parsed['validFrom_time_t'],
                'valid_until' => $parsed['validTo_time_t'],
            ];
        } finally {
            @unlink($configPath);
        }
    }

    private static function configuration(string $keyUsage): string
    {
        return <<<INI
[ req ]
distinguished_name = req_distinguished_name
prompt = no
req_extensions = v3_req

[ req_distinguished_name ]
CN = NEX-OMS KSeF Test Certificate
C = PL

[ v3_req ]
basicConstraints = critical,CA:FALSE
keyUsage = critical,{$keyUsage}
INI;
    }
}
