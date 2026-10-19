<?php

namespace Modules\Integrations\Allegro\Services;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Modules\Integrations\Allegro\Exceptions\AllegroApiException;
use Modules\Integrations\Allegro\Models\AllegroAccount;
use Modules\Shipments\Models\IntegrationApiLog;
use Throwable;

class AllegroClient
{
    private const MEDIA_TYPE = 'application/vnd.allegro.public.v1+json';

    public function __construct(private readonly AllegroOAuthService $oauth) {}

    public function testConnection(AllegroAccount $account): array
    {
        if (! $account->hasRequiredScopes()) {
            throw new AllegroApiException(
                'Połączenie nie posiada wymaganych uprawnień: '.implode(', ', $account->missingScopes()).'. Połącz konto ponownie.',
            );
        }

        return [
            'profile' => $this->get($account, 'test_profile', '/me'),
            'order_stats' => $this->get($account, 'test_orders', '/order/event-stats'),
            'delivery_services' => $this->get($account, 'test_shipments', '/shipment-management/delivery-services'),
        ];
    }

    private function get(AllegroAccount $account, string $operation, string $path): array
    {
        $requestId = (string) Str::uuid();
        $url = $account->apiBaseUrl().$path;
        $startedAt = hrtime(true);

        try {
            $response = $this->perform($account, $url);

            if ($response->status() === 401) {
                $this->oauth->refresh($account);
                $response = $this->perform($account->fresh(), $url);
            }

            $payload = is_array($response->json()) ? $response->json() : [];
            $successful = $response->successful();
            $error = $successful ? null : $this->errorMessage($payload, $response->status());
            $this->log(
                $operation,
                $requestId,
                $url,
                $response->status(),
                $this->sanitizedResponse($operation, $payload),
                $startedAt,
                $successful,
                $error,
            );

            if (! $successful) {
                throw new AllegroApiException($error, $response->status(), $payload);
            }

            return $payload;
        } catch (AllegroApiException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            $this->log($operation, $requestId, $url, null, null, $startedAt, false, $exception->getMessage());

            throw new AllegroApiException('Nie udało się połączyć z API Allegro: '.$exception->getMessage());
        }
    }

    private function perform(AllegroAccount $account, string $url): Response
    {
        return Http::withToken($this->oauth->accessToken($account))
            ->withHeaders(['Accept' => self::MEDIA_TYPE, 'Content-Type' => self::MEDIA_TYPE])
            ->timeout((int) config('services.allegro.timeout', 25))
            ->get($url);
    }

    private function errorMessage(array $payload, int $status): string
    {
        $messages = collect((array) ($payload['errors'] ?? []))
            ->map(fn (mixed $error): ?string => is_array($error)
                ? ($error['userMessage'] ?? $error['message'] ?? $error['code'] ?? null)
                : null)
            ->filter();

        return $messages->isNotEmpty()
            ? $messages->unique()->implode(' ')
            : (string) ($payload['message'] ?? 'API Allegro zwróciło błąd HTTP '.$status.'.');
    }

    private function sanitizedResponse(string $operation, array $payload): array
    {
        if ($operation !== 'test_profile') {
            return $payload;
        }

        return [
            'id' => $payload['id'] ?? null,
            'login' => $payload['login'] ?? null,
            'baseMarketplace' => ['id' => data_get($payload, 'baseMarketplace.id')],
        ];
    }

    private function log(
        string $operation,
        string $requestId,
        string $url,
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
            'method' => 'GET',
            'url' => $url,
            'response_status' => $status,
            'response_payload' => $response,
            'duration_ms' => (int) round((hrtime(true) - $startedAt) / 1_000_000),
            'successful' => $successful,
            'error_message' => $error,
        ]);
    }
}
