<?php

namespace Modules\Ksef\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Modules\Ksef\Enums\KsefEnvironment;
use Modules\Ksef\Exceptions\KsefApiException;
use Modules\Ksef\ValueObjects\KsefApiResponse;
use Modules\Ksef\ValueObjects\KsefRawApiResponse;

class KsefHttpClient
{
    public function get(
        KsefEnvironment $environment,
        string $path,
        ?string $bearerToken = null,
        array $query = [],
    ): KsefApiResponse {
        return $this->send($environment, 'GET', $path, null, null, $bearerToken, $query);
    }

    public function getRaw(
        KsefEnvironment $environment,
        string $path,
        ?string $bearerToken = null,
        array $query = [],
    ): KsefRawApiResponse {
        return $this->send(
            $environment,
            'GET',
            $path,
            null,
            null,
            $bearerToken,
            $query,
            rawResponse: true,
        );
    }

    public function post(
        KsefEnvironment $environment,
        string $path,
        ?array $payload = null,
        ?string $bearerToken = null,
        array $query = [],
    ): KsefApiResponse {
        return $this->send($environment, 'POST', $path, $payload, null, $bearerToken, $query);
    }

    public function postXml(
        KsefEnvironment $environment,
        string $path,
        string $xml,
        ?string $bearerToken = null,
        array $query = [],
    ): KsefApiResponse {
        return $this->send($environment, 'POST', $path, null, $xml, $bearerToken, $query);
    }

    public function baseUrl(KsefEnvironment $environment): string
    {
        $baseUrl = config('ksef.base_urls.'.$environment->value);

        if (! is_string($baseUrl) || trim($baseUrl) === '') {
            throw new KsefApiException(
                'Brak konfiguracji adresu API KSeF dla wybranego środowiska.',
                'base_url_missing',
            );
        }

        return rtrim($baseUrl, '/');
    }

    private function send(
        KsefEnvironment $environment,
        string $method,
        string $path,
        ?array $payload,
        ?string $rawBody,
        ?string $bearerToken,
        array $query,
        bool $rawResponse = false,
    ): KsefApiResponse|KsefRawApiResponse {
        $request = $this->request($environment, $rawResponse);

        if (filled($bearerToken)) {
            $request = $request->withToken($bearerToken);
        }

        $options = [];

        if ($query !== []) {
            $options['query'] = $query;
        }

        if ($rawBody !== null) {
            $request = $request->withBody($rawBody, 'application/xml');
        } elseif ($payload !== null) {
            $options['json'] = $payload;
        }

        try {
            $response = $request->send($method, '/'.ltrim($path, '/'), $options);
        } catch (ConnectionException) {
            throw new KsefApiException(
                'Nie udało się połączyć z KSeF. Spróbuj ponownie później.',
                'network_error',
            );
        }

        $requestSecrets = $this->requestSecrets($payload, $bearerToken, $rawBody);

        return $rawResponse
            ? $this->parseRawResponse($response, $requestSecrets)
            : $this->parseResponse($response, $requestSecrets);
    }

    private function request(KsefEnvironment $environment, bool $rawResponse = false): PendingRequest
    {
        $request = Http::baseUrl($this->baseUrl($environment))
            ->withHeaders(['X-Error-Format' => 'problem-details'])
            ->connectTimeout((int) config('ksef.connect_timeout_seconds', 5))
            ->timeout((int) config('ksef.request_timeout_seconds', 15));

        return $rawResponse
            ? $request->accept('application/xml')
            : $request->acceptJson();
    }

    private function parseResponse(Response $response, array $requestSecrets): KsefApiResponse
    {
        $systemWarning = $this->systemWarning($response, $requestSecrets);
        $data = $response->json();

        if ($response->successful()) {
            if ($response->status() === 204) {
                return new KsefApiResponse([], $systemWarning);
            }

            if (! is_array($data)) {
                throw new KsefApiException(
                    'KSeF zwrócił odpowiedź w nieprawidłowym formacie.',
                    'malformed_response',
                    $response->status(),
                    systemWarning: $systemWarning,
                );
            }

            return new KsefApiResponse($data, $systemWarning);
        }

        $this->throwResponseException($response, $systemWarning, $data);
    }

    private function parseRawResponse(Response $response, array $requestSecrets): KsefRawApiResponse
    {
        $systemWarning = $this->systemWarning($response, $requestSecrets);

        if (! $response->successful()) {
            $this->throwResponseException($response, $systemWarning, $response->json());
        }

        $body = $response->body();
        if ($body === '') {
            throw new KsefApiException(
                'KSeF zwrócił pustą odpowiedź UPO.',
                'malformed_response',
                $response->status(),
                systemWarning: $systemWarning,
            );
        }

        $contentHash = trim((string) $response->header('x-ms-meta-hash'));

        return new KsefRawApiResponse(
            $body,
            $contentHash === '' ? null : $contentHash,
            $systemWarning,
        );
    }

    private function throwResponseException(
        Response $response,
        ?string $systemWarning,
        mixed $data,
    ): never {
        $retryAfter = $this->retryAfterSeconds($response);
        $reasonCode = $this->reasonCode($data);
        $status = $response->status();

        if ($status === 429) {
            $message = $retryAfter !== null
                ? "Limit zapytań KSeF został przekroczony. Spróbuj ponownie za {$retryAfter} s."
                : 'Limit zapytań KSeF został przekroczony. Spróbuj ponownie później.';

            throw new KsefApiException(
                $message,
                'rate_limited',
                $status,
                $reasonCode,
                $retryAfter,
                $systemWarning,
            );
        }

        $message = match (true) {
            $status === 400 => 'KSeF odrzucił nieprawidłowe żądanie.',
            $status === 401 => 'KSeF odrzucił uwierzytelnienie.',
            $status === 403 => 'KSeF odmówił dostępu do wskazanego kontekstu.',
            $status === 410 => 'Dane uwierzytelnienia KSeF wygasły lub zostały unieważnione.',
            $status >= 500 => 'Usługa KSeF jest obecnie niedostępna. Spróbuj ponownie później.',
            default => 'KSeF zwrócił błąd podczas komunikacji.',
        };

        throw new KsefApiException(
            $message,
            'http_'.$status,
            $status,
            $reasonCode,
            $retryAfter,
            $systemWarning,
        );
    }

    private function reasonCode(mixed $data): ?string
    {
        if (! is_array($data)) {
            return null;
        }

        $reasonCode = $data['reasonCode']
            ?? data_get($data, 'status.code')
            ?? data_get($data, 'exception.exceptionDetailList.0.exceptionCode');

        if (! is_int($reasonCode) && ! is_string($reasonCode)) {
            return null;
        }

        $reasonCode = (string) $reasonCode;

        return preg_match('/^[A-Za-z0-9_.:-]{1,100}$/', $reasonCode) === 1
            ? $reasonCode
            : null;
    }

    private function retryAfterSeconds(Response $response): ?int
    {
        $header = trim((string) $response->header('Retry-After'));

        if ($header === '' || preg_match('/^\d+$/', $header) !== 1) {
            return null;
        }

        return (int) $header;
    }

    private function requestSecrets(
        ?array $payload,
        ?string $bearerToken,
        ?string $rawBody = null,
    ): array {
        $secrets = array_filter([
            $bearerToken,
            $payload['encryptedToken'] ?? null,
            $payload['encryptedSymmetricKey'] ?? null,
            data_get($payload, 'encryption.encryptedSymmetricKey'),
            $payload['encryptedInvoiceContent'] ?? null,
            $rawBody,
            $this->xmlSignatureValue($rawBody),
        ], fn (mixed $secret): bool => is_string($secret) && $secret !== '');

        usort($secrets, fn (string $left, string $right): int => strlen($right) <=> strlen($left));

        return array_values(array_unique($secrets));
    }

    private function xmlSignatureValue(?string $xml): ?string
    {
        if ($xml === null
            || preg_match('/<(?:(?:[A-Za-z_][\w.-]*):)?SignatureValue\b[^>]*>([^<]+)<\//', $xml, $matches) !== 1) {
            return null;
        }

        $value = trim($matches[1]);

        return $value === '' ? null : $value;
    }

    private function systemWarning(Response $response, array $requestSecrets): ?string
    {
        $warning = trim((string) $response->header('X-System-Warning'));

        if ($requestSecrets !== []) {
            $warning = str_replace($requestSecrets, '[ukryto]', $warning);
        }

        return $warning === '' ? null : mb_substr($warning, 0, 2000);
    }
}
