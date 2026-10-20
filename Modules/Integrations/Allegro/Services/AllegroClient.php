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

    public function order(AllegroAccount $account, string $identifier): array
    {
        if (! $account->isOperational() || blank($account->allegro_user_id)) {
            throw new AllegroApiException('Najpierw połącz konto Allegro i wykonaj poprawny test połączenia.');
        }

        return $this->get($account, 'get_order', '/order/checkout-forms/'.rawurlencode($identifier));
    }

    public function billingEntries(AllegroAccount $account, string $identifier, string $marketplace, int $offset): array
    {
        if (! $account->isOperational() || ! $account->hasBillingScope()) {
            throw new AllegroApiException('Połącz konto Allegro z uprawnieniem do odczytu rozliczeń.');
        }

        return $this->get($account, 'get_order_fees', '/billing/billing-entries?'.http_build_query([
            'order.id' => $identifier,
            'marketplaceId' => $marketplace,
            'limit' => 100,
            'offset' => $offset,
        ], '', '&', PHP_QUERY_RFC3986));
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
            $error = $successful ? null : match ($operation) {
                'get_order' => $this->orderErrorMessage($response->status()),
                'get_order_fees' => $this->feesErrorMessage($response->status()),
                default => $this->errorMessage($payload, $response->status()),
            };
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
            ->withUserAgent((string) config('services.allegro.user_agent'))
            ->withHeaders(['Accept' => self::MEDIA_TYPE, 'Content-Type' => self::MEDIA_TYPE, 'Accept-Language' => 'pl-PL'])
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

    private function orderErrorMessage(int $status): string
    {
        return match ($status) {
            401 => 'Sesja Allegro wygasła. Połącz konto ponownie.',
            403 => 'Brak dostępu do tego zamówienia Allegro. Sprawdź konto sprzedawcy i uprawnienia integracji.',
            404 => 'Nie znaleziono zamówienia Allegro na połączonym koncie.',
            429 => 'Przekroczono limit zapytań Allegro. Spróbuj ponownie później.',
            default => 'Nie udało się pobrać zamówienia Allegro (HTTP '.$status.'). Spróbuj ponownie później.',
        };
    }

    private function feesErrorMessage(int $status): string
    {
        return match ($status) {
            401 => 'Sesja Allegro wygasła. Połącz konto ponownie.',
            403 => 'Brak dostępu do rozliczeń Allegro. Włącz odczyt rozliczeń w aplikacji Allegro i ponownie połącz integrację.',
            429 => 'Przekroczono limit zapytań Allegro. Spróbuj ponownie później.',
            default => 'Nie udało się pobrać prowizji i opłat Allegro (HTTP '.$status.'). Spróbuj ponownie później.',
        };
    }

    private function sanitizedResponse(string $operation, array $payload): array
    {
        if ($operation === 'get_order_fees') {
            return ['count' => is_array($payload['billingEntries'] ?? null) ? count($payload['billingEntries']) : null];
        }

        if ($operation === 'get_order') {
            return collect($payload)->only(['id', 'status', 'revision', 'updatedAt'])->all();
        }

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
