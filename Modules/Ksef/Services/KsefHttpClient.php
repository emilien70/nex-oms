<?php

namespace Modules\Ksef\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Modules\Ksef\Enums\KsefEnvironment;
use Modules\Ksef\Exceptions\KsefApiException;
use Modules\Ksef\ValueObjects\KsefApiResponse;

class KsefHttpClient
{
    public function get(
        KsefEnvironment $environment,
        string $path,
        ?string $bearerToken = null,
        array $query = [],
    ): KsefApiResponse {
        return $this->send($environment, 'GET', $path, null, $bearerToken, $query);
    }

    public function post(
        KsefEnvironment $environment,
        string $path,
        ?array $payload = null,
        ?string $bearerToken = null,
        array $query = [],
    ): KsefApiResponse {
        return $this->send($environment, 'POST', $path, $payload, $bearerToken, $query);
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
        ?string $bearerToken,
        array $query,
    ): KsefApiResponse {
        $request = $this->request($environment);

        if (filled($bearerToken)) {
            $request = $request->withToken($bearerToken);
        }

        $options = [];

        if ($query !== []) {
            $options['query'] = $query;
        }

        if ($payload !== null) {
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

        return $this->parseResponse($response, $this->requestSecrets($payload, $bearerToken));
    }

    private function request(KsefEnvironment $environment): PendingRequest
    {
        return Http::baseUrl($this->baseUrl($environment))
            ->acceptJson()
            ->asJson()
            ->withHeaders(['X-Error-Format' => 'problem-details'])
            ->connectTimeout((int) config('ksef.connect_timeout_seconds', 5))
            ->timeout((int) config('ksef.request_timeout_seconds', 15));
    }

    private function parseResponse(Response $response, array $requestSecrets): KsefApiResponse
    {
        $systemWarning = $this->systemWarning($response, $requestSecrets);
        $data = $response->json();

        if ($response->successful()) {
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

        $reasonCode = $data['reasonCode'] ?? data_get($data, 'status.code');

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

    private function requestSecrets(?array $payload, ?string $bearerToken): array
    {
        $secrets = array_filter([
            $bearerToken,
            $payload['encryptedToken'] ?? null,
        ], fn (mixed $secret): bool => is_string($secret) && $secret !== '');

        usort($secrets, fn (string $left, string $right): int => strlen($right) <=> strlen($left));

        return array_values(array_unique($secrets));
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
