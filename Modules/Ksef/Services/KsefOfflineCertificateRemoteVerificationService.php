<?php

namespace Modules\Ksef\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\Ksef\Enums\KsefOfflineCertificateRemoteStatus;
use Modules\Ksef\Exceptions\KsefApiException;
use Modules\Ksef\Models\KsefOfflineCertificate;
use OpenSSLAsymmetricKey;
use OpenSSLCertificate;
use Throwable;

final class KsefOfflineCertificateRemoteVerificationService
{
    public function __construct(
        private readonly KsefOfflineCertificateRemoteOperationPolicy $environments,
        private readonly KsefCertificateManagementAccessTokenProvider $certificateManagementAccessTokens,
        private readonly KsefHttpClient $http,
        private readonly KsefInstantStorageNormalizer $instantStorage,
    ) {}

    public function verify(KsefOfflineCertificate $certificate): KsefOfflineCertificate
    {
        $snapshot = $this->localSnapshot($certificate);
        $this->environments->assertAllowed($snapshot['environment']);

        $accessToken = $this->certificateManagementAccessTokens
            ->getValidAccessToken($snapshot['environment']);
        $queryResponse = $this->http->post(
            $snapshot['environment'],
            '/certificates/query',
            [
                'certificateSerialNumber' => $snapshot['serial'],
                'type' => 'Offline',
            ],
            $accessToken,
            [
                'pageSize' => 10,
                'pageOffset' => 0,
            ],
        );
        $remote = $this->queryMetadata($queryResponse->data, $snapshot);
        $this->persistFreshQueryObservation($snapshot, $remote, CarbonImmutable::now('UTC'));

        $retrieveResponse = $this->http->post(
            $snapshot['environment'],
            '/certificates/retrieve',
            ['certificateSerialNumbers' => [$snapshot['serial']]],
            $accessToken,
        );
        $this->verifyRetrievedCertificate($retrieveResponse->data, $snapshot);

        return $this->persistRemoteSnapshot($snapshot, $remote);
    }

    private function localSnapshot(KsefOfflineCertificate $certificate): array
    {
        $certificatePem = $certificate->certificate_pem;
        $privateKeyPem = $certificate->private_key_pem;

        if (! is_string($certificatePem) || $certificatePem === ''
            || ! is_string($privateKeyPem) || $privateKeyPem === '') {
            throw new KsefApiException(
                'Lokalny materiał certyfikatu Offline jest niekompletny.',
                'offline_certificate_material_missing',
            );
        }

        return [
            'id' => (int) $certificate->getKey(),
            'environment' => $certificate->environment,
            'serial' => $certificate->certificate_serial_number,
            'fingerprint' => $certificate->fingerprint_sha256,
            'certificate_pem' => $certificatePem,
            'private_key_pem' => $privateKeyPem,
            'certificate_digest' => hash('sha256', $certificatePem),
            'private_key_digest' => hash('sha256', $privateKeyPem),
        ];
    }

    private function queryMetadata(array $data, array $snapshot): array
    {
        $certificates = $data['certificates'] ?? null;
        $hasMore = $data['hasMore'] ?? null;

        if (! is_array($certificates) || ! array_is_list($certificates) || ! is_bool($hasMore)) {
            $this->malformedResponse();
        }

        if ($hasMore || count($certificates) !== 1) {
            $this->identityFailure(
                $snapshot,
                'offline_certificate_query_not_unique',
                'KSeF nie zwrócił dokładnie jednego certyfikatu Offline o wskazanym numerze.',
            );
        }

        $certificate = $certificates[0];
        if (! is_array($certificate)) {
            $this->malformedResponse();
        }

        $serial = $this->requiredString($certificate, 'certificateSerialNumber', 16);
        $type = $this->requiredString($certificate, 'type', 30);

        if (! hash_equals($snapshot['serial'], $serial) || $type !== 'Offline') {
            $this->identityFailure(
                $snapshot,
                'offline_certificate_query_identity_mismatch',
                'Dane certyfikatu zwrócone przez KSeF nie odpowiadają lokalnemu certyfikatowi Offline.',
            );
        }

        $status = $this->requiredSafeStatus($certificate['status'] ?? null);
        $name = $this->requiredSafeName($certificate['name'] ?? null);
        $validFrom = $this->requiredInstant($certificate['validFrom'] ?? null);
        $validUntil = $this->requiredInstant($certificate['validTo'] ?? null);

        if ($validFrom->greaterThan($validUntil)) {
            $this->malformedResponse();
        }

        return [
            'status' => $status,
            'name' => $name,
            'valid_from' => $validFrom,
            'valid_until' => $validUntil,
        ];
    }

    private function verifyRetrievedCertificate(array $data, array $snapshot): void
    {
        $certificates = $data['certificates'] ?? null;

        if (! is_array($certificates) || ! array_is_list($certificates)) {
            $this->malformedResponse();
        }

        if (count($certificates) !== 1) {
            $this->identityFailure(
                $snapshot,
                'offline_certificate_retrieve_not_unique',
                'KSeF nie zwrócił dokładnie jednego certyfikatu do weryfikacji.',
            );
        }

        $remote = $certificates[0];
        if (! is_array($remote)) {
            $this->malformedResponse();
        }

        $serial = $this->requiredString($remote, 'certificateSerialNumber', 16);
        $type = $this->requiredString($remote, 'certificateType', 30);

        if (! hash_equals($snapshot['serial'], $serial) || $type !== 'Offline') {
            $this->identityFailure(
                $snapshot,
                'offline_certificate_retrieve_identity_mismatch',
                'Certyfikat pobrany z KSeF nie odpowiada lokalnemu certyfikatowi Offline.',
            );
        }

        $encoded = $remote['certificate'] ?? null;
        if (! is_string($encoded) || $encoded === '') {
            $this->malformedResponse();
        }

        $der = base64_decode($encoded, true);
        if (! is_string($der) || $der === '') {
            $this->malformedResponse();
        }

        $this->clearOpenSslErrors();
        $pem = "-----BEGIN CERTIFICATE-----\n"
            .chunk_split(base64_encode($der), 64, "\n")
            ."-----END CERTIFICATE-----\n";
        $x509 = @openssl_x509_read($pem);

        if (! $x509 instanceof OpenSSLCertificate) {
            $this->malformedResponse();
        }

        $parsed = openssl_x509_parse($x509, false);
        $fingerprint = openssl_x509_fingerprint($x509, 'sha256');

        if (! is_array($parsed) || ! is_string($fingerprint)) {
            $this->malformedResponse();
        }

        $x509Serial = strtoupper((string) ($parsed['serialNumberHex'] ?? ''));
        $fingerprint = strtoupper(str_replace(':', '', $fingerprint));

        if (! hash_equals($snapshot['serial'], $x509Serial)
            || ! hash_equals($snapshot['fingerprint'], $fingerprint)) {
            $this->identityFailure(
                $snapshot,
                'offline_certificate_fingerprint_mismatch',
                'Tożsamość certyfikatu pobranego z KSeF nie odpowiada lokalnemu certyfikatowi Offline.',
            );
        }

        $privateKey = @openssl_pkey_get_private($snapshot['private_key_pem']);
        if (! $privateKey instanceof OpenSSLAsymmetricKey
            || ! openssl_x509_check_private_key($x509, $privateKey)) {
            $this->identityFailure(
                $snapshot,
                'offline_certificate_private_key_mismatch',
                'Lokalny klucz prywatny nie odpowiada certyfikatowi Offline zwróconemu przez KSeF.',
            );
        }

        $this->clearOpenSslErrors();
    }

    private function persistRemoteSnapshot(array $snapshot, array $remote): KsefOfflineCertificate
    {
        return DB::transaction(function () use ($snapshot, $remote): KsefOfflineCertificate {
            $certificate = KsefOfflineCertificate::query()
                ->whereKey($snapshot['id'])
                ->lockForUpdate()
                ->first();

            $this->assertIdentityUnchanged($certificate, $snapshot);

            $certificate->forceFill([
                'remote_status' => $remote['status'],
                'remote_certificate_name' => $remote['name'],
                'remote_valid_from' => $this->instantStorage->forStorage($remote['valid_from']),
                'remote_valid_until' => $this->instantStorage->forStorage($remote['valid_until']),
                'remote_verified_at' => $this->instantStorage->forStorage(CarbonImmutable::now('UTC')),
            ])->save();

            return $certificate;
        });
    }

    private function persistFreshQueryObservation(
        array $snapshot,
        array $remote,
        CarbonImmutable $now,
    ): void {
        DB::transaction(function () use ($snapshot, $remote, $now): void {
            $certificate = KsefOfflineCertificate::query()
                ->whereKey($snapshot['id'])
                ->lockForUpdate()
                ->first();

            $this->assertIdentityUnchanged($certificate, $snapshot);

            $attributes = [
                'remote_status' => $remote['status'],
                'remote_certificate_name' => $remote['name'],
                'remote_valid_from' => $this->instantStorage->forStorage($remote['valid_from']),
                'remote_valid_until' => $this->instantStorage->forStorage($remote['valid_until']),
            ];

            if (! $this->canPreserveRemoteVerification($certificate, $remote, $now)) {
                $attributes['remote_verified_at'] = null;
            }

            $certificate->forceFill($attributes)->save();
        });
    }

    private function canPreserveRemoteVerification(
        KsefOfflineCertificate $certificate,
        array $remote,
        CarbonImmutable $now,
    ): bool {
        return $certificate->remote_verified_at !== null
            && $certificate->remote_status === KsefOfflineCertificateRemoteStatus::Active->value
            && $remote['status'] === KsefOfflineCertificateRemoteStatus::Active->value
            && ! $remote['valid_from']->greaterThan($now)
            && ! $remote['valid_until']->lessThan($now);
    }

    private function invalidateRemoteTrust(array $snapshot): void
    {
        DB::transaction(function () use ($snapshot): void {
            $certificate = KsefOfflineCertificate::query()
                ->whereKey($snapshot['id'])
                ->lockForUpdate()
                ->first();

            $this->assertIdentityUnchanged($certificate, $snapshot);

            $certificate->forceFill([
                'remote_status' => null,
                'remote_certificate_name' => null,
                'remote_valid_from' => null,
                'remote_valid_until' => null,
                'remote_verified_at' => null,
            ])->save();
        });
    }

    private function assertIdentityUnchanged(?KsefOfflineCertificate $certificate, array $snapshot): void
    {
        if ($certificate === null
            || $certificate->environment !== $snapshot['environment']
            || ! hash_equals($snapshot['serial'], $certificate->certificate_serial_number)
            || ! hash_equals($snapshot['fingerprint'], $certificate->fingerprint_sha256)
            || ! hash_equals($snapshot['certificate_digest'], hash('sha256', (string) $certificate->certificate_pem))
            || ! hash_equals($snapshot['private_key_digest'], hash('sha256', (string) $certificate->private_key_pem))) {
            throw new KsefApiException(
                'Lokalny certyfikat Offline zmienił się podczas weryfikacji. Spróbuj ponownie.',
                'offline_certificate_configuration_changed',
            );
        }
    }

    private function identityFailure(array $snapshot, string $safeCode, string $message): never
    {
        $this->invalidateRemoteTrust($snapshot);

        throw new KsefApiException($message, $safeCode);
    }

    private function requiredString(array $data, string $key, int $maxLength): string
    {
        $value = $data[$key] ?? null;

        if (! is_string($value) || $value === '' || mb_strlen($value) > $maxLength) {
            $this->malformedResponse();
        }

        return $value;
    }

    private function requiredSafeStatus(mixed $value): string
    {
        if (! is_string($value)
            || preg_match('/^[A-Za-z][A-Za-z0-9_-]{0,49}$/D', $value) !== 1) {
            $this->malformedResponse();
        }

        return $value;
    }

    private function requiredSafeName(mixed $value): string
    {
        if (! is_string($value)
            || trim($value) === ''
            || mb_strlen($value) > 120
            || preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
            $this->malformedResponse();
        }

        return trim($value);
    }

    private function requiredInstant(mixed $value): CarbonImmutable
    {
        if (! is_string($value)
            || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$/D', $value) !== 1) {
            $this->malformedResponse();
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (Throwable) {
            $this->malformedResponse();
        }
    }

    private function malformedResponse(): never
    {
        $this->clearOpenSslErrors();

        throw new KsefApiException(
            'KSeF zwrócił niekompletną lub nieprawidłową odpowiedź certyfikatową.',
            'offline_certificate_malformed_response',
        );
    }

    private function clearOpenSslErrors(): void
    {
        while (openssl_error_string() !== false) {
            // OpenSSL diagnostics are discarded because they may contain key details.
        }
    }
}
