<?php

namespace Modules\Integrations\DPD\Services;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Modules\Integrations\DPD\Exceptions\DpdApiException;
use Modules\Shipments\Models\CourierAccount;
use Modules\Shipments\Models\IntegrationApiLog;
use Modules\Shipments\Models\Shipment;
use Throwable;

class DpdClient
{
    public function testConnection(CourierAccount $account, string $postalCode): array
    {
        return $this->request($account, 'test_connection', 'GET', '/public/routing/v1/postalCode', [], [
            'countryCode' => 'PL',
            'zipCode' => preg_replace('/\W+/', '', $postalCode) ?: $postalCode,
        ]);
    }

    public function createShipment(CourierAccount $account, Shipment $shipment, array $payload): array
    {
        return $this->request(
            $account,
            'create_shipment',
            'POST',
            '/public/shipment/v1/generatePackagesNumbers',
            $payload,
            [],
            $shipment,
        );
    }

    public function getLabel(CourierAccount $account, Shipment $shipment, array $payload): array
    {
        return $this->request(
            $account,
            'get_label',
            'POST',
            '/public/shipment/v1/generateSpedLabels',
            $payload,
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
    ): array {
        if (! $account->hasCompleteCredentials()) {
            throw new DpdApiException('Brak loginu, hasla, Master FID lub kanalu InfoServices dla konta DPD.');
        }

        $requestId = (string) Str::uuid();
        $url = $account->baseUrl().$path;
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

            $response = Http::withBasicAuth(
                (string) $account->resolvedApiLogin(),
                (string) $account->resolvedApiToken(),
            )
                ->acceptJson()
                ->withHeaders([
                    'x-dpd-fid' => (string) $account->resolvedOrganizationId(),
                    'X-Request-ID' => $requestId,
                    'X-User-Agent' => 'NEX-OMS',
                ])
                ->timeout((int) config('services.dpd.timeout', 25))
                ->send(strtoupper($method), $url, $options);

            $responsePayload = $this->responsePayload($response);
            $successful = $response->successful() && ! $this->isApiError($responsePayload);
            $errorMessage = $successful ? null : $this->errorMessage($responsePayload, $response->status());

            $this->writeLog(
                $account,
                $operation,
                $shipment,
                $requestId,
                $method,
                $url,
                $payload,
                $response->status(),
                $this->payloadForLog($responsePayload),
                $startedAt,
                $successful,
                $errorMessage,
            );

            if (! $successful) {
                throw new DpdApiException($errorMessage, $response->status(), $responsePayload);
            }

            return $responsePayload;
        } catch (DpdApiException $exception) {
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

            throw new DpdApiException('Nie udalo sie polaczyc z API DPD: '.$exception->getMessage());
        }
    }

    private function responsePayload(Response $response): array
    {
        $payload = $response->json();

        if (is_array($payload)) {
            return $payload;
        }

        return $response->body() === '' ? [] : ['body' => Str::limit($response->body(), 10000)];
    }

    private function isApiError(array $payload): bool
    {
        $status = strtoupper((string) ($payload['status'] ?? ''));

        return $status !== '' && ! in_array($status, ['OK', 'WARNING'], true);
    }

    private function errorMessage(array $payload, int $status): string
    {
        $messages = collect([
            data_get($payload, 'message'),
            data_get($payload, 'description'),
            data_get($payload, 'status'),
        ])->filter(fn (mixed $value): bool => is_string($value) && trim($value) !== '')->all();

        foreach ((array) data_get($payload, 'packages', []) as $package) {
            foreach ((array) data_get($package, 'validationInfo', []) as $error) {
                $messages[] = data_get($error, 'description') ?: data_get($error, 'info');
            }
            foreach ((array) data_get($package, 'parcels', []) as $parcel) {
                foreach ((array) data_get($parcel, 'validationInfo', []) as $error) {
                    $messages[] = data_get($error, 'description') ?: data_get($error, 'info');
                }
            }
        }

        $message = collect($messages)->filter()->unique()->implode(' ');

        return $message !== '' ? $message : 'API DPD zwrocilo blad HTTP '.$status.'.';
    }

    private function payloadForLog(array $payload): array
    {
        if (! isset($payload['documentData'])) {
            return $payload;
        }

        $document = (string) $payload['documentData'];
        $payload['documentData'] = '[base64 document: '.strlen($document).' chars]';

        return $payload;
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
            'integration' => CourierAccount::PROVIDER_DPD,
            'operation' => $operation,
            'order_id' => $shipment?->order_id,
            'shipment_id' => $shipment?->id,
            'shipment_creation_attempt_id' => $shipment?->creation_attempt_id,
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
