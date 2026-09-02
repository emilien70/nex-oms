<?php

namespace Modules\Ksef\Services;

use Carbon\CarbonImmutable;
use Modules\Ksef\Enums\KsefOfflineCertificateRemoteStatus;
use Modules\Ksef\Models\KsefOfflineCertificate;
use OpenSSLAsymmetricKey;
use OpenSSLCertificate;

final class KsefOfflineCertificateReadinessService
{
    public function isReady(KsefOfflineCertificate $certificate, ?CarbonImmutable $now = null): bool
    {
        $now ??= CarbonImmutable::now('UTC');

        if (! is_string($certificate->certificate_pem)
            || $certificate->certificate_pem === ''
            || ! is_string($certificate->private_key_pem)
            || $certificate->private_key_pem === ''
            || $certificate->valid_from === null
            || $certificate->valid_until === null
            || $certificate->remote_verified_at === null
            || $certificate->remote_valid_from === null
            || $certificate->remote_valid_until === null
            || KsefOfflineCertificateRemoteStatus::tryFrom((string) $certificate->remote_status)
                !== KsefOfflineCertificateRemoteStatus::Active
            || $certificate->valid_from->greaterThan($now)
            || $certificate->valid_until->lessThan($now)
            || $certificate->remote_valid_from->greaterThan($now)
            || $certificate->remote_valid_until->lessThan($now)) {
            return false;
        }

        return $this->localIdentityIsIntact($certificate);
    }

    private function localIdentityIsIntact(KsefOfflineCertificate $certificate): bool
    {
        $this->clearOpenSslErrors();
        $x509 = @openssl_x509_read($certificate->certificate_pem);
        $privateKey = @openssl_pkey_get_private($certificate->private_key_pem);

        if (! $x509 instanceof OpenSSLCertificate || ! $privateKey instanceof OpenSSLAsymmetricKey) {
            $this->clearOpenSslErrors();

            return false;
        }

        $parsed = openssl_x509_parse($x509, false);
        $fingerprint = openssl_x509_fingerprint($x509, 'sha256');
        $serial = is_array($parsed)
            ? strtoupper((string) ($parsed['serialNumberHex'] ?? ''))
            : '';
        $fingerprint = is_string($fingerprint)
            ? strtoupper(str_replace(':', '', $fingerprint))
            : '';
        $matches = hash_equals($certificate->certificate_serial_number, $serial)
            && hash_equals($certificate->fingerprint_sha256, $fingerprint)
            && openssl_x509_check_private_key($x509, $privateKey);

        $this->clearOpenSslErrors();

        return $matches;
    }

    private function clearOpenSslErrors(): void
    {
        while (openssl_error_string() !== false) {
            // OpenSSL diagnostics are discarded because they may contain key details.
        }
    }
}
