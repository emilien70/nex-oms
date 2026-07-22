<?php

namespace Modules\Integrations\AllegroShipping\Services;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Modules\Integrations\AllegroShipping\Exceptions\AllegroShippingApiException;
use Modules\Shipments\Models\CourierAccount;
use Modules\Shipments\Models\IntegrationApiLog;
use Modules\Shipments\Models\Shipment;
use Throwable;

class AllegroShippingClient
{
    private const MEDIA_TYPE = 'application/vnd.allegro.public.v1+json';

    public function testConnection(CourierAccount $account): array
    {
        return $this->json($account, 'test_connection', 'GET', '/shipment-management/delivery-services');
    }

    public function deliveryProposal(CourierAccount $account, string $orderId): array
    {
        return $this->json(
            $account,
            'delivery_proposal',
            'GET',
            '/shipment-management/delivery-proposals/'.rawurlencode($orderId),
        );
    }

    public function createCommand(CourierAccount $account, Shipment $shipment, array $payload): array
    {
        return $this->json($account, 'create_shipment', 'POST', '/shipment-management/shipments/create-commands', $payload, $shipment);
    }

    public function createCommandStatus(CourierAccount $account, Shipment $shipment): array
    {
        return $this->json(
            $account,
            'create_shipment_status',
            'GET',
            '/shipment-management/shipments/create-commands/'.rawurlencode($shipment->request_uuid),
            [],
            $shipment,
        );
    }

    public function shipment(CourierAccount $account, Shipment $shipment): array
    {
        return $this->json(
            $account,
            'get_shipment',
            'GET',
            '/shipment-management/shipments/'.rawurlencode((string) $shipment->external_id),
            [],
            $shipment,
        );
    }

    public function tracking(CourierAccount $account, Shipment $shipment): array
    {
        return $this->json(
            $account,
            'get_tracking',
            'GET',
            '/order/carriers/'.rawurlencode((string) $shipment->carrier_code)
                .'/tracking?waybill='.rawurlencode((string) $shipment->tracking_number),
            [],
            $shipment,
        );
    }

    public function cancelCommand(CourierAccount $account, Shipment $shipment, string $commandId): array
    {
        return $this->json($account, 'cancel_shipment', 'POST', '/shipment-management/shipments/cancel-commands', [
            'commandId' => $commandId,
            'input' => ['shipmentId' => $shipment->external_id],
        ], $shipment);
    }

    public function cancelCommandStatus(CourierAccount $account, Shipment $shipment, string $commandId): array
    {
        return $this->json(
            $account,
            'cancel_shipment_status',
            'GET',
            '/shipment-management/shipments/cancel-commands/'.rawurlencode($commandId),
            [],
            $shipment,
        );
    }

    public function label(CourierAccount $account, Shipment $shipment): Response
    {
        return $this->binary($account, 'get_label', '/shipment-management/label', [
            'shipmentIds' => [$shipment->external_id],
            'pageSize' => strtoupper($shipment->label_type ?: 'A6'),
            'cutLine' => false,
        ], $shipment);
    }

    private function json(
        CourierAccount $account,
        string $operation,
        string $method,
        string $path,
        array $payload = [],
        ?Shipment $shipment = null,
    ): array {
        $response = $this->send($account, $operation, $method, $path, $payload, $shipment, self::MEDIA_TYPE);
        $data = $response->json();

        return is_array($data) ? $data : [];
    }

    private function binary(
        CourierAccount $account,
        string $operation,
        string $path,
        array $payload,
        Shipment $shipment,
    ): Response {
        return $this->send($account, $operation, 'POST', $path, $payload, $shipment, 'application/octet-stream');
    }

    private function send(
        CourierAccount $account,
        string $operation,
        string $method,
        string $path,
        array $payload,
        ?Shipment $shipment,
        string $accept,
    ): Response {
        if (! $account->hasCompleteCredentials()) {
            throw new AllegroShippingApiException('Brak tokenu OAuth dla konta Allegro.');
        }

        $requestId = (string) Str::uuid();
        $url = $account->baseUrl().$path;
        $startedAt = hrtime(true);

        try {
            $response = $this->perform($account, $method, $url, $payload, $accept);

            if ($response->status() === 401 && filled($account->api_refresh_token)) {
                $this->refreshAccessToken($account);
                $response = $this->perform($account->fresh(), $method, $url, $payload, $accept);
            }

            $responsePayload = str_contains((string) $response->header('Content-Type'), 'json')
                ? (is_array($response->json()) ? $response->json() : [])
                : ['binary_bytes' => strlen($response->body())];
            $successful = $response->successful();
            $error = $successful ? null : $this->errorMessage($responsePayload, $response->status());

            $this->log($operation, $shipment, $requestId, $method, $url, $payload, $response->status(), $responsePayload, $startedAt, $successful, $error);

            if (! $successful) {
                throw new AllegroShippingApiException($error, $response->status(), $responsePayload);
            }

            return $response;
        } catch (AllegroShippingApiException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            $this->log($operation, $shipment, $requestId, $method, $url, $payload, null, null, $startedAt, false, $exception->getMessage());

            throw new AllegroShippingApiException('Nie udalo sie polaczyc z API Allegro: '.$exception->getMessage());
        }
    }

    private function perform(CourierAccount $account, string $method, string $url, array $payload, string $accept): Response
    {
        $options = $payload === [] ? [] : ['json' => $payload];

        return Http::withToken((string) $account->resolvedApiToken())
            ->withHeaders(['Accept' => $accept, 'Content-Type' => self::MEDIA_TYPE])
            ->timeout((int) config('services.allegro_shipping.timeout', 25))
            ->send(strtoupper($method), $url, $options);
    }

    private function refreshAccessToken(CourierAccount $account): void
    {
        $clientId = (string) ($account->organization_id ?: config('services.allegro_shipping.client_id'));
        $clientSecret = (string) ($account->api_secret ?: config('services.allegro_shipping.client_secret'));

        if ($clientId === '' || $clientSecret === '') {
            throw new AllegroShippingApiException('Token Allegro wygasl, a konfiguracja nie zawiera Client ID i Client Secret do jego odnowienia.', 401);
        }

        $key = $account->environment === 'production' ? 'production_auth_url' : 'sandbox_auth_url';
        $url = (string) config('services.allegro_shipping.'.$key);
        $requestId = (string) Str::uuid();
        $startedAt = hrtime(true);

        try {
            $response = Http::withBasicAuth($clientId, $clientSecret)
                ->asForm()
                ->timeout((int) config('services.allegro_shipping.timeout', 25))
                ->post($url, [
                    'grant_type' => 'refresh_token',
                    'refresh_token' => $account->api_refresh_token,
                ]);

            $successful = $response->successful() && filled($response->json('access_token'));
            $responsePayload = $successful
                ? [
                    'token_type' => $response->json('token_type'),
                    'expires_in' => $response->json('expires_in'),
                    'refresh_token_rotated' => filled($response->json('refresh_token')),
                ]
                : (is_array($response->json()) ? $response->json() : []);

            $this->log(
                'refresh_token',
                null,
                $requestId,
                'POST',
                $url,
                ['grant_type' => 'refresh_token'],
                $response->status(),
                $responsePayload,
                $startedAt,
                $successful,
                $successful ? null : 'Nie udalo sie odnowic tokenu OAuth Allegro.',
            );
        } catch (Throwable $exception) {
            $this->log(
                'refresh_token',
                null,
                $requestId,
                'POST',
                $url,
                ['grant_type' => 'refresh_token'],
                null,
                null,
                $startedAt,
                false,
                $exception->getMessage(),
            );

            throw new AllegroShippingApiException('Nie udalo sie polaczyc z OAuth Allegro: '.$exception->getMessage());
        }

        if (! $response->successful() || blank($response->json('access_token'))) {
            throw new AllegroShippingApiException('Nie udalo sie odnowic tokenu OAuth Allegro.', $response->status(), (array) $response->json());
        }

        $account->update([
            'api_token' => $response->json('access_token'),
            'api_refresh_token' => $response->json('refresh_token') ?: $account->api_refresh_token,
        ]);
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
            : (string) ($payload['message'] ?? 'API Allegro zwrocilo blad HTTP '.$status.'.');
    }

    private function log(
        string $operation,
        ?Shipment $shipment,
        string $requestId,
        string $method,
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
            'order_id' => $shipment?->order_id,
            'shipment_id' => $shipment?->id,
            'shipment_creation_attempt_id' => $shipment?->creation_attempt_id,
            'request_id' => $requestId,
            'method' => strtoupper($method),
            'url' => $url,
            'request_payload' => $request === [] ? null : $request,
            'response_status' => $status,
            'response_payload' => $response,
            'duration_ms' => (int) round((hrtime(true) - $startedAt) / 1_000_000),
            'successful' => $successful,
            'error_message' => $error,
        ]);
    }
}
