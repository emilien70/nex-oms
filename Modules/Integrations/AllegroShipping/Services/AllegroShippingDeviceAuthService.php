<?php

namespace Modules\Integrations\AllegroShipping\Services;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Modules\Integrations\AllegroShipping\Exceptions\AllegroShippingApiException;
use Modules\Shipments\Models\CourierAccount;
use Modules\Shipments\Models\IntegrationApiLog;
use Throwable;

class AllegroShippingDeviceAuthService
{
    private const DEVICE_GRANT = 'urn:ietf:params:oauth:grant-type:device_code';

    public function start(CourierAccount $account): array
    {
        [$clientId, $clientSecret] = $this->credentials($account);
        $url = $this->endpoint($account, 'device_url');

        $response = $this->request(
            $account,
            'device_authorization_start',
            $url.'?client_id='.rawurlencode($clientId),
            ['client_id' => $clientId, 'scope' => (string) config('services.allegro_shipping.scopes')],
            $clientId,
            $clientSecret,
        );
        $payload = (array) $response->json();

        foreach (['device_code', 'user_code', 'verification_uri', 'expires_in', 'interval'] as $field) {
            if (blank($payload[$field] ?? null)) {
                throw new AllegroShippingApiException('Allegro zwrocilo niepelne dane autoryzacji Device Flow.');
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

    public function poll(CourierAccount $account, string $deviceCode): array
    {
        [$clientId, $clientSecret] = $this->credentials($account);
        $url = $this->endpoint($account, 'auth_url');
        $response = $this->request(
            $account,
            'device_authorization_poll',
            $url,
            ['grant_type' => self::DEVICE_GRANT, 'device_code' => $deviceCode],
            $clientId,
            $clientSecret,
            ['authorization_pending', 'slow_down'],
        );
        $payload = (array) $response->json();

        if ($response->status() === 400 && in_array($payload['error'] ?? null, ['authorization_pending', 'slow_down'], true)) {
            return ['status' => 'pending', 'slow_down' => ($payload['error'] ?? null) === 'slow_down'];
        }

        if (blank($payload['access_token'] ?? null) || blank($payload['refresh_token'] ?? null)) {
            throw new AllegroShippingApiException('Allegro nie zwrocilo tokenow OAuth po autoryzacji konta.');
        }

        $account->update([
            'api_token' => $payload['access_token'],
            'api_refresh_token' => $payload['refresh_token'],
            'is_active' => true,
            'last_tested_at' => now(),
            'last_error' => null,
        ]);

        return ['status' => 'connected'];
    }

    private function credentials(CourierAccount $account): array
    {
        $clientId = (string) $account->resolvedOrganizationId();
        $clientSecret = (string) $account->resolvedApiSecret();

        if ($clientId === '' || $clientSecret === '') {
            throw new AllegroShippingApiException('Najpierw zapisz Client ID i Client Secret aplikacji Device Allegro.');
        }

        return [$clientId, $clientSecret];
    }

    private function endpoint(CourierAccount $account, string $suffix): string
    {
        $prefix = $account->environment === 'production' ? 'production_' : 'sandbox_';

        return (string) config('services.allegro_shipping.'.$prefix.$suffix);
    }

    private function request(
        CourierAccount $account,
        string $operation,
        string $url,
        array $data,
        string $clientId,
        string $clientSecret,
        array $acceptedErrors = [],
    ): Response {
        $requestId = (string) Str::uuid();
        $startedAt = hrtime(true);

        try {
            $response = Http::withBasicAuth($clientId, $clientSecret)
                ->asForm()
                ->timeout((int) config('services.allegro_shipping.timeout', 25))
                ->post($url, $data);
            $payload = (array) $response->json();
            $accepted = $response->status() === 400 && in_array($payload['error'] ?? null, $acceptedErrors, true);
            $successful = $response->successful() || $accepted;
            $error = $successful ? null : $this->errorMessage($payload, $response->status());

            $this->log(
                $operation,
                $requestId,
                $url,
                $this->sanitizedRequest($data),
                $response->status(),
                $this->sanitizedResponse($payload),
                $startedAt,
                $successful,
                $error,
            );

            if (! $successful) {
                throw new AllegroShippingApiException($error, $response->status(), $payload);
            }

            return $response;
        } catch (AllegroShippingApiException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            $this->log($operation, $requestId, $url, $this->sanitizedRequest($data), null, null, $startedAt, false, $exception->getMessage());

            throw new AllegroShippingApiException('Nie udalo sie polaczyc z OAuth Allegro: '.$exception->getMessage());
        }
    }

    private function sanitizedRequest(array $data): array
    {
        return collect($data)->except(['device_code'])->all();
    }

    private function sanitizedResponse(array $payload): array
    {
        return collect($payload)
            ->except(['device_code', 'access_token', 'refresh_token'])
            ->when(isset($payload['access_token']), fn ($data) => $data->put('access_token_received', true))
            ->when(isset($payload['refresh_token']), fn ($data) => $data->put('refresh_token_received', true))
            ->all();
    }

    private function errorMessage(array $payload, int $status): string
    {
        return match ($payload['error'] ?? null) {
            'access_denied' => 'Odmowiono polaczenia konta z Allegro.',
            'expired_token', 'invalid_grant', 'Invalid device code' => 'Kod laczenia z Allegro wygasl lub zostal juz wykorzystany.',
            default => (string) ($payload['error_description'] ?? $payload['message'] ?? 'OAuth Allegro zwrocil blad HTTP '.$status.'.'),
        };
    }

    private function log(
        string $operation,
        string $requestId,
        string $url,
        array $request,
        ?int $status,
        ?array $response,
        int $startedAt,
        bool $successful,
        ?string $error,
    ): void {
        IntegrationApiLog::query()->create([
            'integration' => CourierAccount::PROVIDER_ALLEGRO_SHIPPING,
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
