<?php

namespace Modules\Integrations\Allegro\Services;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Modules\Integrations\Allegro\Exceptions\AllegroApiException;
use Modules\Integrations\Allegro\Models\AllegroAccount;
use Modules\Shipments\Models\IntegrationApiLog;
use Throwable;

class AllegroOAuthService
{
    private const DEVICE_GRANT = 'urn:ietf:params:oauth:grant-type:device_code';

    public function start(AllegroAccount $account): array
    {
        $this->assertApplicationCredentials($account);
        $query = http_build_query([
            'client_id' => $account->client_id,
            'scope' => (string) config('services.allegro.scopes'),
        ], '', '&', PHP_QUERY_RFC3986);

        $response = $this->oauthRequest(
            $account,
            'device_authorization_start',
            $account->deviceUrl().'?'.$query,
            [],
        );
        $payload = (array) $response->json();

        foreach (['device_code', 'user_code', 'verification_uri', 'expires_in', 'interval'] as $field) {
            if (blank($payload[$field] ?? null)) {
                throw new AllegroApiException('Allegro zwróciło niepełne dane autoryzacji Device Flow.');
            }
        }

        return [
            'device_code' => (string) $payload['device_code'],
            'user_code' => (string) $payload['user_code'],
            'verification_uri' => (string) $payload['verification_uri'],
            'verification_uri_complete' => (string) ($payload['verification_uri_complete'] ?? $payload['verification_uri']),
            'expires_at' => now()->addSeconds((int) $payload['expires_in'])->timestamp,
            'interval' => max(5, (int) $payload['interval']),
            'account_id' => $account->id,
        ];
    }

    public function poll(AllegroAccount $account, string $deviceCode): array
    {
        $this->assertApplicationCredentials($account);
        $response = $this->oauthRequest(
            $account,
            'device_authorization_poll',
            $account->authUrl(),
            ['grant_type' => self::DEVICE_GRANT, 'device_code' => $deviceCode],
            ['authorization_pending', 'slow_down'],
        );
        $payload = (array) $response->json();

        if ($response->status() === 400 && in_array($payload['error'] ?? null, ['authorization_pending', 'slow_down'], true)) {
            return ['status' => 'pending', 'slow_down' => ($payload['error'] ?? null) === 'slow_down'];
        }

        if (blank($payload['access_token'] ?? null) || blank($payload['refresh_token'] ?? null)) {
            throw new AllegroApiException('Allegro nie zwróciło tokenów OAuth po autoryzacji konta.');
        }

        $account->forceFill([
            'access_token' => (string) $payload['access_token'],
            'refresh_token' => (string) $payload['refresh_token'],
            'access_token_expires_at' => now()->addSeconds(max(60, (int) ($payload['expires_in'] ?? 43200))),
            'granted_scopes' => $this->parseScopes((string) ($payload['scope'] ?? '')),
            'is_active' => false,
            'last_test_status' => null,
            'last_error' => null,
        ])->save();

        return ['status' => 'authorized'];
    }

    public function accessToken(AllegroAccount $account): string
    {
        if (! $account->hasOAuthTokens()) {
            throw new AllegroApiException('Konto Allegro nie jest połączone.', 401);
        }

        if ($account->access_token_expires_at?->lessThanOrEqualTo(now()->addMinutes(5))) {
            $this->refresh($account);
            $account->refresh();
        }

        return (string) $account->access_token;
    }

    public function refresh(AllegroAccount $account): void
    {
        $this->assertApplicationCredentials($account);

        if (blank($account->refresh_token)) {
            throw new AllegroApiException('Brak refresh tokenu konta Allegro.', 401);
        }

        $response = $this->oauthRequest($account, 'refresh_token', $account->authUrl(), [
            'grant_type' => 'refresh_token',
            'refresh_token' => $account->refresh_token,
        ]);
        $payload = (array) $response->json();

        if (blank($payload['access_token'] ?? null)) {
            throw new AllegroApiException('Nie udało się odnowić tokenu OAuth Allegro.', $response->status(), $payload);
        }

        $account->forceFill([
            'access_token' => (string) $payload['access_token'],
            'refresh_token' => (string) ($payload['refresh_token'] ?? $account->refresh_token),
            'access_token_expires_at' => now()->addSeconds(max(60, (int) ($payload['expires_in'] ?? 43200))),
            'granted_scopes' => filled($payload['scope'] ?? null)
                ? $this->parseScopes((string) $payload['scope'])
                : $account->granted_scopes,
        ])->save();
    }

    private function oauthRequest(
        AllegroAccount $account,
        string $operation,
        string $url,
        array $data,
        array $acceptedErrors = [],
    ): Response {
        $requestId = (string) Str::uuid();
        $startedAt = hrtime(true);

        try {
            $response = Http::withBasicAuth((string) $account->client_id, (string) $account->client_secret)
                ->asForm()
                ->timeout((int) config('services.allegro.timeout', 25))
                ->post($url, $data);
            $payload = (array) $response->json();
            $accepted = $response->status() === 400 && in_array($payload['error'] ?? null, $acceptedErrors, true);
            $successful = $response->successful() || $accepted;
            $error = $successful ? null : $this->errorMessage($payload, $response->status());

            $this->log(
                $operation,
                $requestId,
                $url,
                collect($data)->except(['device_code', 'refresh_token'])->all(),
                $response->status(),
                collect($payload)
                    ->except(['device_code', 'access_token', 'refresh_token'])
                    ->when(isset($payload['access_token']), fn ($items) => $items->put('access_token_received', true))
                    ->when(isset($payload['refresh_token']), fn ($items) => $items->put('refresh_token_received', true))
                    ->all(),
                $startedAt,
                $successful,
                $error,
            );

            if (! $successful) {
                throw new AllegroApiException($error, $response->status(), $payload);
            }

            return $response;
        } catch (AllegroApiException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            $this->log($operation, $requestId, $url, null, null, null, $startedAt, false, $exception->getMessage());

            throw new AllegroApiException('Nie udało się połączyć z OAuth Allegro: '.$exception->getMessage());
        }
    }

    private function assertApplicationCredentials(AllegroAccount $account): void
    {
        if (! $account->hasApplicationCredentials()) {
            throw new AllegroApiException('Najpierw zapisz Client ID i Client Secret aplikacji Device Allegro.');
        }
    }

    private function parseScopes(string $scopes): array
    {
        return array_values(array_unique(array_filter(preg_split('/\s+/', trim($scopes)) ?: [])));
    }

    private function errorMessage(array $payload, int $status): string
    {
        return match ($payload['error'] ?? null) {
            'access_denied' => 'Odmówiono połączenia konta z Allegro.',
            'expired_token', 'invalid_grant', 'Invalid device code' => 'Kod łączenia z Allegro wygasł albo został już wykorzystany.',
            default => (string) ($payload['error_description'] ?? $payload['message'] ?? 'OAuth Allegro zwrócił błąd HTTP '.$status.'.'),
        };
    }

    private function log(
        string $operation,
        string $requestId,
        string $url,
        ?array $request,
        ?int $status,
        ?array $response,
        int $startedAt,
        bool $successful,
        ?string $error,
    ): void {
        IntegrationApiLog::query()->create([
            'integration' => 'allegro',
            'operation' => $operation,
            'request_id' => $requestId,
            'method' => 'POST',
            'url' => $url,
            'request_payload' => $request,
            'response_status' => $status,
            'response_payload' => $response,
            'duration_ms' => (int) round((hrtime(true) - $startedAt) / 1_000_000),
            'successful' => $successful,
            'error_message' => $error,
        ]);
    }
}
