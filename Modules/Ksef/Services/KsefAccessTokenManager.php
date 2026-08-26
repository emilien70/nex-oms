<?php

namespace Modules\Ksef\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\Ksef\Enums\KsefEnvironment;
use Modules\Ksef\Exceptions\KsefApiException;
use Modules\Ksef\Models\KsefCredential;
use Throwable;

class KsefAccessTokenManager
{
    public function __construct(
        private readonly KsefHttpClient $http,
        private readonly KsefAuthenticationService $authentication,
        private readonly KsefSettingsService $settings,
        private readonly KsefTokenValidityNormalizer $validity,
    ) {}

    public function getValidAccessToken(
        KsefEnvironment $environment,
        ?string $expectedContextNip = null,
    ): string {
        $settings = $this->settings->get();
        $this->assertExpectedContext($settings->context_nip, $expectedContextNip);
        $credential = KsefCredential::query()
            ->where('environment', $environment->value)
            ->first();

        if ($credential === null) {
            throw new KsefApiException(
                'Najpierw zapisz Token KSeF dla wybranego środowiska.',
                'api_token_missing',
            );
        }

        $threshold = CarbonImmutable::now()->addSeconds(
            max(0, (int) config('ksef.access_token_refresh_skew_seconds', 60)),
        );

        if (is_string($credential->access_token)
            && $credential->access_token !== ''
            && $credential->access_token_valid_until?->greaterThan($threshold)) {
            return $credential->access_token;
        }

        if (is_string($credential->refresh_token)
            && $credential->refresh_token !== ''
            && $credential->refresh_token_valid_until?->isFuture()) {
            try {
                return $this->refresh($credential);
            } catch (KsefApiException $exception) {
                if (! $exception->isRefreshAuthorizationFailure()) {
                    throw $exception;
                }

                $this->clearRuntimeAuthentication($environment);
                $credential->refresh();
            }
        }

        $pair = $this->authentication->authenticate($credential, (string) $settings->context_nip);

        return $pair->accessToken;
    }

    private function assertExpectedContext(mixed $currentContextNip, ?string $expectedContextNip): void
    {
        if ($expectedContextNip === null) {
            return;
        }

        if (preg_match('/^\d{10}$/', $expectedContextNip) !== 1
            || ! is_string($currentContextNip)
            || ! hash_equals($expectedContextNip, $currentContextNip)) {
            throw new KsefApiException(
                'Kontekst KSeF zmienił się od przygotowania Faktury. Przygotuj nową próbę wysyłki.',
                'ksef_submission_context_changed',
            );
        }
    }

    private function refresh(KsefCredential $credential): string
    {
        $refreshToken = $credential->refresh_token;
        $response = $this->http->post(
            $credential->environment,
            '/auth/token/refresh',
            bearerToken: $refreshToken,
        );
        $accessToken = data_get($response->data, 'accessToken.token');
        $validUntil = data_get($response->data, 'accessToken.validUntil');

        if (! is_string($accessToken) || $accessToken === '' || ! is_string($validUntil) || $validUntil === '') {
            throw new KsefApiException(
                'KSeF zwrócił niekompletną odpowiedź odświeżenia tokenu.',
                'refresh_response_incomplete',
            );
        }

        try {
            $validUntil = $this->validity->parseRemote($validUntil);
        } catch (Throwable) {
            throw new KsefApiException(
                'KSeF zwrócił nieprawidłową datę ważności odświeżonego tokenu.',
                'refresh_validity_invalid',
            );
        }

        DB::transaction(function () use ($credential, $refreshToken, $accessToken, $validUntil): void {
            $managed = KsefCredential::query()
                ->whereKey($credential->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if (! is_string($managed->refresh_token)
                || ! hash_equals($refreshToken, $managed->refresh_token)) {
                throw new KsefApiException(
                    'Dane uwierzytelnienia KSeF zmieniły się podczas odświeżania. Spróbuj ponownie.',
                    'configuration_changed',
                );
            }

            $managed->forceFill([
                'access_token' => $accessToken,
                'access_token_valid_until' => $validUntil,
            ])->save();
        });

        return $accessToken;
    }

    private function clearRuntimeAuthentication(KsefEnvironment $environment): void
    {
        KsefCredential::query()
            ->where('environment', $environment->value)
            ->update([
                'access_token' => null,
                'access_token_valid_until' => null,
                'refresh_token' => null,
                'refresh_token_valid_until' => null,
            ]);
    }
}
