<?php

namespace Modules\Ksef\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\Ksef\Enums\KsefEnvironment;
use Modules\Ksef\Exceptions\KsefApiException;
use Modules\Ksef\Models\KsefCredential;
use Modules\Ksef\Models\KsefSetting;
use Modules\Ksef\ValueObjects\KsefTokenPair;
use Throwable;

class KsefTokenAuthenticationService
{
    public function __construct(
        private readonly KsefHttpClient $http,
        private readonly KsefPublicKeyResolver $publicKeys,
        private readonly KsefTokenEncryptor $encryptor,
    ) {}

    public function authenticate(KsefCredential $credential, string $contextNip): KsefTokenPair
    {
        $apiToken = $credential->api_token;

        if (! is_string($apiToken) || $apiToken === '') {
            throw new KsefApiException(
                'Najpierw zapisz Token KSeF dla wybranego środowiska.',
                'api_token_missing',
            );
        }

        if (preg_match('/^\d{10}$/', $contextNip) !== 1) {
            throw new KsefApiException(
                'Najpierw zapisz prawidłowy NIP kontekstu KSeF.',
                'context_nip_missing',
            );
        }

        $warnings = [];

        try {
            return $this->performAuthentication($credential, $contextNip, $apiToken, $warnings);
        } catch (KsefApiException $exception) {
            $this->addWarning($warnings, $exception->systemWarning);

            throw new KsefApiException(
                $exception->getMessage(),
                $exception->safeCode,
                $exception->httpStatus,
                $exception->reasonCode,
                $exception->retryAfterSeconds,
                $this->joinedWarnings($warnings, [$apiToken]),
            );
        }
    }

    private function performAuthentication(
        KsefCredential $credential,
        string $contextNip,
        string $apiToken,
        array &$warnings,
    ): KsefTokenPair {
        $environment = $credential->environment;
        $challengeResponse = $this->http->post($environment, '/auth/challenge');
        $this->addWarning($warnings, $challengeResponse->systemWarning);
        $challenge = $this->requiredString($challengeResponse->data, 'challenge');
        $timestampMs = $this->requiredTimestampMs($challengeResponse->data);

        $certificatesResponse = $this->http->get($environment, '/security/public-key-certificates');
        $this->addWarning($warnings, $certificatesResponse->systemWarning);
        $certificate = $this->publicKeys->resolve($certificatesResponse->data);
        $encryptedToken = $this->encryptor->encrypt($apiToken, $timestampMs, $certificate->certificate);

        $initResponse = $this->http->post($environment, '/auth/ksef-token', [
            'challenge' => $challenge,
            'contextIdentifier' => [
                'type' => 'Nip',
                'value' => $contextNip,
            ],
            'encryptedToken' => $encryptedToken,
            'publicKeyId' => $certificate->publicKeyId,
        ]);
        $this->addWarning($warnings, $initResponse->systemWarning);
        $referenceNumber = $this->requiredString($initResponse->data, 'referenceNumber');
        $authenticationToken = $this->requiredString($initResponse->data, 'authenticationToken.token');

        $this->waitForAuthentication($environment, $referenceNumber, $authenticationToken, $warnings);

        // Redeem is intentionally called once. Ambiguous transport failures require a fresh auth flow.
        $redeemResponse = $this->http->post(
            $environment,
            '/auth/token/redeem',
            bearerToken: $authenticationToken,
        );
        $this->addWarning($warnings, $redeemResponse->systemWarning);

        $accessToken = $this->requiredString($redeemResponse->data, 'accessToken.token');
        $refreshToken = $this->requiredString($redeemResponse->data, 'refreshToken.token');
        $pair = new KsefTokenPair(
            $accessToken,
            $this->requiredDate($redeemResponse->data, 'accessToken.validUntil'),
            $refreshToken,
            $this->requiredDate($redeemResponse->data, 'refreshToken.validUntil'),
            $this->sanitizedWarnings($warnings, [$apiToken, $accessToken, $refreshToken]),
        );

        $this->persist($credential, $contextNip, $apiToken, $pair);

        return $pair;
    }

    private function waitForAuthentication(
        KsefEnvironment $environment,
        string $referenceNumber,
        string $authenticationToken,
        array &$warnings,
    ): void {
        $maxAttempts = max(1, (int) config('ksef.auth_poll_max_attempts', 20));
        $intervalMs = max(0, (int) config('ksef.auth_poll_interval_ms', 500));

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $statusResponse = $this->http->get(
                $environment,
                '/auth/'.rawurlencode($referenceNumber),
                $authenticationToken,
            );
            $this->addWarning($warnings, $statusResponse->systemWarning);
            $statusCode = data_get($statusResponse->data, 'status.code');

            if (is_string($statusCode) && preg_match('/^\d+$/', $statusCode) === 1) {
                $statusCode = (int) $statusCode;
            }

            if (! is_int($statusCode)) {
                throw new KsefApiException(
                    'KSeF zwrócił nieprawidłowy status uwierzytelnienia.',
                    'auth_status_malformed',
                );
            }

            if ($statusCode === 200) {
                if (($statusResponse->data['isTokenRedeemed'] ?? false) === true) {
                    throw new KsefApiException(
                        'Stan uwierzytelnienia KSeF jest niespójny. Rozpocznij test ponownie.',
                        'auth_token_already_redeemed',
                    );
                }

                return;
            }

            if ($statusCode !== 100) {
                throw $this->terminalStatusException($statusCode, $statusResponse->systemWarning);
            }

            if ($attempt < $maxAttempts && $intervalMs > 0) {
                usleep($intervalMs * 1000);
            }
        }

        throw new KsefApiException(
            'KSeF nie zakończył uwierzytelniania w oczekiwanym czasie. Spróbuj ponownie.',
            'auth_poll_timeout',
        );
    }

    private function terminalStatusException(int $statusCode, ?string $warning): KsefApiException
    {
        $message = match ($statusCode) {
            415 => 'Nie udało się uwierzytelnić w KSeF. Brak aktywnych uprawnień do wskazanego kontekstu.',
            425 => 'Uwierzytelnienie w KSeF zostało unieważnione. Rozpocznij test ponownie.',
            450 => 'Nie udało się uwierzytelnić w KSeF. Token KSeF jest nieprawidłowy, nieaktywny lub został unieważniony.',
            460 => 'KSeF odrzucił certyfikat użyty do uwierzytelnienia.',
            470 => 'Uwierzytelnienie w KSeF zakończyło się niepowodzeniem.',
            480 => 'Uwierzytelnienie w KSeF zostało zablokowane.',
            500 => 'KSeF nie mógł zakończyć uwierzytelnienia z powodu błędu systemowego.',
            550 => 'Operacja uwierzytelnienia została anulowana przez KSeF.',
            default => 'KSeF odrzucił uwierzytelnienie.',
        };

        return new KsefApiException(
            $message,
            'auth_status_'.$statusCode,
            reasonCode: (string) $statusCode,
            systemWarning: $warning,
        );
    }

    private function persist(
        KsefCredential $credential,
        string $contextNip,
        string $apiToken,
        KsefTokenPair $pair,
    ): void {
        DB::transaction(function () use ($credential, $contextNip, $apiToken, $pair): void {
            $settings = KsefSetting::query()
                ->where('singleton_key', KsefSetting::SINGLETON_KEY)
                ->lockForUpdate()
                ->firstOrFail();

            $managedCredential = KsefCredential::query()
                ->whereKey($credential->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($settings->context_nip !== $contextNip
                || ! is_string($managedCredential->api_token)
                || ! hash_equals($apiToken, $managedCredential->api_token)) {
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

    private function requiredTimestampMs(array $data): int
    {
        $value = $data['timestampMs'] ?? null;

        if (is_string($value) && preg_match('/^\d+$/', $value) === 1) {
            $value = (int) $value;
        }

        if (! is_int($value) || $value <= 0) {
            throw new KsefApiException(
                'KSeF zwrócił nieprawidłowy znacznik czasu uwierzytelnienia.',
                'challenge_timestamp_invalid',
            );
        }

        return $value;
    }

    private function requiredDate(array $data, string $path): CarbonImmutable
    {
        $value = data_get($data, $path);

        if (! is_string($value) || $value === '') {
            throw new KsefApiException(
                'KSeF zwrócił niekompletne dane ważności tokenu.',
                'token_validity_missing',
            );
        }

        try {
            return CarbonImmutable::parse($value)->utc();
        } catch (Throwable) {
            throw new KsefApiException(
                'KSeF zwrócił nieprawidłową datę ważności tokenu.',
                'token_validity_invalid',
            );
        }
    }

    private function addWarning(array &$warnings, ?string $warning): void
    {
        if ($warning !== null && ! in_array($warning, $warnings, true)) {
            $warnings[] = $warning;
        }
    }

    private function joinedWarnings(array $warnings, array $secrets): ?string
    {
        $warnings = $this->sanitizedWarnings($warnings, $secrets);

        return $warnings === [] ? null : implode(' | ', $warnings);
    }

    private function sanitizedWarnings(array $warnings, array $secrets): array
    {
        $secrets = array_values(array_filter(
            $secrets,
            fn (mixed $secret): bool => is_string($secret) && $secret !== '',
        ));

        return collect($warnings)
            ->filter(fn (mixed $warning): bool => is_string($warning) && trim($warning) !== '')
            ->map(fn (string $warning): string => trim(str_replace($secrets, '[ukryto]', $warning)))
            ->unique()
            ->values()
            ->all();
    }
}
