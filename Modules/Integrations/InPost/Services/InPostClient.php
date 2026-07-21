<?php

namespace Modules\Integrations\InPost\Services;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Modules\Integrations\InPost\Exceptions\InPostApiException;
use Modules\Shipments\Models\CourierAccount;
use Modules\Shipments\Models\IntegrationApiLog;
use Modules\Shipments\Models\Shipment;
use Throwable;

class InPostClient
{
    public function testConnection(CourierAccount $account): array
    {
        return $this->request(
            $account,
            'test_connection',
            'GET',
            '/organizations/'.$account->resolvedOrganizationId(),
        )->json();
    }

    public function createShipment(CourierAccount $account, Shipment $shipment, array $payload): array
    {
        return $this->request(
            $account,
            'create_shipment',
            'POST',
            '/organizations/'.$account->resolvedOrganizationId().'/shipments',
            $payload,
            [],
            $shipment,
        )->json();
    }

    public function getShipment(CourierAccount $account, Shipment $shipment): array
    {
        return $this->request(
            $account,
            'get_shipment',
            'GET',
            '/shipments/'.$shipment->external_id,
            [],
            [],
            $shipment,
        )->json();
    }

    public function getLabel(CourierAccount $account, Shipment $shipment): Response
    {
        return $this->request(
            $account,
            'get_label',
            'GET',
            '/shipments/'.$shipment->external_id.'/label',
            [],
            [
                'format' => $shipment->label_format,
                'type' => $shipment->label_type,
            ],
            $shipment,
            true,
        );
    }

    public function cancelShipment(CourierAccount $account, Shipment $shipment): void
    {
        $this->request(
            $account,
            'cancel_shipment',
            'DELETE',
            '/shipments/'.$shipment->external_id,
            [],
            [],
            $shipment,
        );
    }

    private function request(
        CourierAccount $account,
        string $operation,
        string $method,
        string $path,
        array $payload = [],
        array $query = [],
        ?Shipment $shipment = null,
        bool $binaryResponse = false,
    ): Response {
        if (! $account->hasCompleteCredentials()) {
            throw new InPostApiException('Brak tokenu API lub Organization ID dla konta InPost.');
        }

        $requestId = (string) Str::uuid();
        $url = $account->baseUrl().'/v1'.$path;
        $startedAt = hrtime(true);
        $response = null;

        try {
            $options = [];

            if ($payload !== []) {
                $options['json'] = $payload;
            }

            if ($query !== []) {
                $options['query'] = $query;
            }

            $response = Http::withToken((string) $account->resolvedApiToken())
                ->acceptJson()
                ->withHeaders([
                    'Accept-Language' => 'pl_PL',
                    'X-Request-ID' => $requestId,
                    'X-User-Agent' => 'NEX-OMS',
                    'X-User-Agent-Version' => '0.1',
                ])
                ->timeout((int) config('services.inpost.timeout', 20))
                ->send(strtoupper($method), $url, $options);

            $responsePayload = $this->responsePayload($response, $binaryResponse);
            $errorMessage = $response->successful() ? null : $this->errorMessage($responsePayload, $response->status());

            $this->writeLog(
                $account,
                $operation,
                $shipment,
                $requestId,
                $method,
                $url,
                $payload,
                $response->status(),
                $responsePayload,
                $startedAt,
                $response->successful(),
                $errorMessage,
            );

            if (! $response->successful()) {
                throw new InPostApiException($errorMessage, $response->status(), $responsePayload);
            }

            return $response;
        } catch (InPostApiException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            $this->writeLog(
                $account,
                $operation,
                $shipment,
                $requestId,
                $method,
                $url,
                $payload,
                $response?->status(),
                null,
                $startedAt,
                false,
                $exception->getMessage(),
            );

            throw new InPostApiException('Nie udalo sie polaczyc z API InPost: '.$exception->getMessage());
        }
    }

    private function responsePayload(Response $response, bool $binaryResponse): ?array
    {
        if ($binaryResponse) {
            return [
                'content_type' => $response->header('Content-Type'),
                'bytes' => strlen($response->body()),
            ];
        }

        $payload = $response->json();

        if (is_array($payload)) {
            return $payload;
        }

        return $response->body() === '' ? null : ['body' => Str::limit($response->body(), 10000)];
    }

    private function errorMessage(?array $payload, int $status): string
    {
        $message = data_get($payload, 'message') ?? data_get($payload, 'error') ?? data_get($payload, 'details');
        $validationErrors = is_array(data_get($payload, 'details'))
            ? $this->validationErrors(data_get($payload, 'details'))
            : [];

        if (is_array($message)) {
            $message = json_encode($message, JSON_UNESCAPED_UNICODE);
        }

        if ($validationErrors !== []) {
            $summary = implode(' ', $validationErrors);

            return is_string($message) && $message !== ''
                ? rtrim($message, ". \t\n\r\0\x0B").'. '.$summary
                : $summary;
        }

        return is_string($message) && $message !== ''
            ? $message
            : 'API InPost zwrocilo blad HTTP '.$status.'.';
    }

    private function validationErrors(array $details, string $path = ''): array
    {
        $errors = [];

        foreach ($details as $key => $value) {
            $currentPath = is_int($key) ? $path : trim($path.'.'.$key, '.');

            if (is_array($value)) {
                $errors = [...$errors, ...$this->validationErrors($value, $currentPath)];

                continue;
            }

            if (! is_string($value) || trim($value) === '') {
                continue;
            }

            $field = match ($currentPath) {
                'receiver.phone' => 'Telefon odbiorcy',
                'receiver.email' => 'E-mail odbiorcy',
                'custom_attributes.target_point' => 'Paczkomat docelowy',
                'parcels.template' => 'Gabaryt przesylki',
                default => $currentPath !== '' ? $currentPath : 'Dane przesylki',
            };

            $errors[] = $field.': '.rtrim(trim($value), '.').'.';
        }

        return array_values(array_unique($errors));
    }

    private function writeLog(
        CourierAccount $account,
        string $operation,
        ?Shipment $shipment,
        string $requestId,
        string $method,
        string $url,
        array $requestPayload,
        ?int $responseStatus,
        ?array $responsePayload,
        int $startedAt,
        bool $successful,
        ?string $errorMessage,
    ): void {
        IntegrationApiLog::query()->create([
            'integration' => $shipment?->provider ?: $account->provider,
            'operation' => $operation,
            'order_id' => $shipment?->order_id,
            'shipment_id' => $shipment?->id,
            'request_id' => $requestId,
            'method' => strtoupper($method),
            'url' => $url,
            'request_payload' => $requestPayload === [] ? null : $requestPayload,
            'response_status' => $responseStatus,
            'response_payload' => $responsePayload,
            'duration_ms' => (int) round((hrtime(true) - $startedAt) / 1_000_000),
            'successful' => $successful,
            'error_message' => $errorMessage,
        ]);
    }
}
