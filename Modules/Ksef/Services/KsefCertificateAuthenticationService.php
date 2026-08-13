<?php

namespace Modules\Ksef\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Ksef\Enums\KsefAuthenticationMethod;
use Modules\Ksef\Exceptions\KsefApiException;
use Modules\Ksef\Models\KsefCredential;
use Modules\Ksef\Models\KsefSetting;
use Modules\Ksef\ValueObjects\KsefTokenPair;

final class KsefCertificateAuthenticationService
{
    public function __construct(
        private readonly KsefHttpClient $http,
        private readonly KsefCertificateMaterialService $materials,
        private readonly KsefAuthTokenRequestBuilder $requestBuilder,
        private readonly KsefXadesSigner $signer,
        private readonly KsefAuthenticationCompletionService $completion,
    ) {}

    public function authenticate(KsefCredential $credential, string $contextNip): KsefTokenPair
    {
        $certificate = $credential->authentication_certificate;
        $privateKey = $credential->authentication_private_key;

        if (! is_string($certificate) || $certificate === ''
            || ! is_string($privateKey) || $privateKey === '') {
            throw new KsefApiException(
                'Najpierw zapisz certyfikat KSeF i klucz prywatny dla wybranego środowiska.',
                'certificate_material_missing',
            );
        }

        if (preg_match('/^\d{10}$/', $contextNip) !== 1) {
            throw new KsefApiException(
                'Najpierw zapisz prawidłowy NIP kontekstu KSeF.',
                'context_nip_missing',
            );
        }

        try {
            $material = $this->materials->inspect($certificate, $privateKey);
        } catch (ValidationException) {
            throw new KsefApiException(
                'Zapisany certyfikat KSeF jest nieprawidłowy lub utracił ważność.',
                'certificate_material_invalid',
            );
        }

        $warnings = [];

        try {
            $environment = $credential->environment;
            $challengeResponse = $this->http->post($environment, '/auth/challenge');
            $this->addWarning($warnings, $challengeResponse->systemWarning);
            $challenge = $this->requiredString($challengeResponse->data, 'challenge');
            $unsignedXml = $this->requestBuilder->build($challenge, $contextNip);
            $signedXml = $this->signer->sign(
                $unsignedXml,
                $material->certificatePem,
                $material->privateKeyPem,
            );
            $initResponse = $this->http->postXml(
                $environment,
                '/auth/xades-signature',
                $signedXml,
            );
            $this->addWarning($warnings, $initResponse->systemWarning);
            $pair = $this->completion->complete(
                $environment,
                KsefAuthenticationMethod::Certificate,
                $initResponse->data,
                $warnings,
            );
            $this->persist($credential, $contextNip, $certificate, $privateKey, $pair);

            return $pair;
        } catch (KsefApiException $exception) {
            $this->addWarning($warnings, $exception->systemWarning);

            throw new KsefApiException(
                $exception->getMessage(),
                $exception->safeCode,
                $exception->httpStatus,
                $exception->reasonCode,
                $exception->retryAfterSeconds,
                $warnings === [] ? null : implode(' | ', array_unique($warnings)),
            );
        }
    }

    private function persist(
        KsefCredential $credential,
        string $contextNip,
        string $certificate,
        string $privateKey,
        KsefTokenPair $pair,
    ): void {
        $certificateHash = hash('sha256', $certificate);
        $privateKeyHash = hash('sha256', $privateKey);

        DB::transaction(function () use (
            $credential,
            $contextNip,
            $certificateHash,
            $privateKeyHash,
            $pair,
        ): void {
            $settings = KsefSetting::query()
                ->where('singleton_key', KsefSetting::SINGLETON_KEY)
                ->lockForUpdate()
                ->firstOrFail();
            $managedCredential = KsefCredential::query()
                ->whereKey($credential->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            $managedCertificate = $managedCredential->authentication_certificate;
            $managedPrivateKey = $managedCredential->authentication_private_key;

            if ($settings->context_nip !== $contextNip
                || $managedCredential->authentication_method !== KsefAuthenticationMethod::Certificate
                || ! is_string($managedCertificate)
                || ! is_string($managedPrivateKey)
                || ! hash_equals($certificateHash, hash('sha256', $managedCertificate))
                || ! hash_equals($privateKeyHash, hash('sha256', $managedPrivateKey))) {
                throw new KsefApiException(
                    'Konfiguracja KSeF zmieniła się podczas uwierzytelniania. Rozpocznij test ponownie.',
                    'configuration_changed',
                );
            }

            $managedCredential->forceFill([
                'access_token' => $pair->accessToken,
                'access_token_valid_until' => $pair->accessTokenValidUntil,
                'refresh_token' => $pair->refreshToken,
                'refresh_token_valid_until' => $pair->refreshTokenValidUntil,
            ])->save();
        });
    }

    private function requiredString(array $data, string $path): string
    {
        $value = data_get($data, $path);

        if (! is_string($value) || $value === '') {
            throw new KsefApiException(
                'KSeF zwrócił niekompletną odpowiedź uwierzytelnienia.',
                'auth_response_incomplete',
            );
        }

        return $value;
    }

    private function addWarning(array &$warnings, ?string $warning): void
    {
        if ($warning !== null && ! in_array($warning, $warnings, true)) {
            $warnings[] = $warning;
        }
    }
}
