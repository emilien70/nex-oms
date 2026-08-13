<?php

namespace Modules\Ksef\Services;

use Carbon\CarbonImmutable;
use Modules\Ksef\Enums\KsefAuthenticationMethod;
use Modules\Ksef\Enums\KsefEnvironment;
use Modules\Ksef\Exceptions\KsefApiException;
use Modules\Ksef\ValueObjects\KsefTokenPair;
use Throwable;

final class KsefAuthenticationCompletionService
{
    public function __construct(
        private readonly KsefHttpClient $http,
    ) {}

    public function complete(
        KsefEnvironment $environment,
        KsefAuthenticationMethod $method,
        array $initData,
        array &$warnings,
        array $knownSecrets = [],
    ): KsefTokenPair {
        $secrets = [
            ...$knownSecrets,
            $this->optionalSecret($initData, 'authenticationToken.token'),
        ];

        try {
            $referenceNumber = $this->requiredString($initData, 'referenceNumber');
            $authenticationToken = $this->requiredString($initData, 'authenticationToken.token');
            $this->waitForAuthentication(
                $environment,
                $method,
                $referenceNumber,
                $authenticationToken,
                $warnings,
            );

            // Redeem is intentionally called once. Ambiguous failures require a fresh auth flow.
            $redeemResponse = $this->http->post(
                $environment,
                '/auth/token/redeem',
                bearerToken: $authenticationToken,
            );
            $this->addWarning($warnings, $redeemResponse->systemWarning);
            $secrets = [
                ...$secrets,
                $this->optionalSecret($redeemResponse->data, 'accessToken.token'),
                $this->optionalSecret($redeemResponse->data, 'refreshToken.token'),
            ];
            $accessToken = $this->requiredString($redeemResponse->data, 'accessToken.token');
            $refreshToken = $this->requiredString($redeemResponse->data, 'refreshToken.token');
            $warnings = $this->sanitizedWarnings($warnings, $secrets);

            return new KsefTokenPair(
                $accessToken,
                $this->requiredDate($redeemResponse->data, 'accessToken.validUntil'),
                $refreshToken,
                $this->requiredDate($redeemResponse->data, 'refreshToken.validUntil'),
                $warnings,
            );
        } catch (KsefApiException $exception) {
            $this->addWarning($warnings, $exception->systemWarning);
            $systemWarning = $this->joinedWarnings($warnings, $secrets);
            $warnings = $systemWarning === null ? [] : [$systemWarning];

            throw new KsefApiException(
                $exception->getMessage(),
                $exception->safeCode,
                $exception->httpStatus,
                $exception->reasonCode,
                $exception->retryAfterSeconds,
                $systemWarning,
            );
        }
    }

    private function waitForAuthentication(
        KsefEnvironment $environment,
        KsefAuthenticationMethod $method,
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
                throw $this->terminalStatusException($statusCode, $method, $statusResponse->systemWarning);
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

    private function terminalStatusException(
        int $statusCode,
        KsefAuthenticationMethod $method,
        ?string $warning,
    ): KsefApiException {
        $message = match ($statusCode) {
            415 => 'Nie udało się uwierzytelnić w KSeF. Brak aktywnych uprawnień do wskazanego kontekstu.',
            425 => 'Uwierzytelnienie w KSeF zostało unieważnione. Rozpocznij test ponownie.',
            450 => $method === KsefAuthenticationMethod::Token
                ? 'Nie udało się uwierzytelnić w KSeF. Token KSeF jest nieprawidłowy, nieaktywny lub został unieważniony.'
                : 'KSeF odrzucił uwierzytelnienie certyfikatem.',
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

    private function optionalSecret(array $data, string $path): ?string
    {
        $value = data_get($data, $path);

        return is_string($value) && $value !== '' ? $value : null;
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
        $secrets = array_values(array_unique(array_filter(
            $secrets,
            fn (mixed $secret): bool => is_string($secret) && $secret !== '',
        )));
        usort($secrets, fn (string $left, string $right): int => strlen($right) <=> strlen($left));

        return collect($warnings)
            ->filter(fn (mixed $warning): bool => is_string($warning) && trim($warning) !== '')
            ->map(fn (string $warning): string => trim(str_replace($secrets, '[ukryto]', $warning)))
            ->unique()
            ->values()
            ->all();
    }
}
