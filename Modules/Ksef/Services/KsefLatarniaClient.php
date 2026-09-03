<?php

namespace Modules\Ksef\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use JsonException;
use Modules\Ksef\Enums\KsefLatarniaEnvironment;
use Modules\Ksef\Exceptions\KsefApiException;

final class KsefLatarniaClient
{
    public function __construct(
        private readonly KsefLatarniaEndpointResolver $endpoints,
    ) {}

    public function status(KsefLatarniaEnvironment $environment): object
    {
        $payload = $this->get($this->endpoints->statusUrl($environment));

        if (! is_object($payload)) {
            $this->invalidResponse();
        }

        return $payload;
    }

    /** @return list<object> */
    public function messages(KsefLatarniaEnvironment $environment): array
    {
        $payload = $this->get($this->endpoints->messagesUrl($environment));

        if (! is_array($payload) || ! array_is_list($payload)) {
            $this->invalidResponse();
        }

        return $payload;
    }

    private function get(string $url): mixed
    {
        try {
            $response = Http::acceptJson()
                ->connectTimeout((int) config('ksef.connect_timeout_seconds', 5))
                ->timeout((int) config('ksef.request_timeout_seconds', 15))
                ->get($url);
        } catch (ConnectionException) {
            throw new KsefApiException(
                'Nie udało się połączyć z Latarnią KSeF.',
                'ksef_latarnia_network_error',
            );
        }

        if (! $response->successful()) {
            $this->httpError($response);
        }

        try {
            return json_decode($response->body(), false, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $this->invalidResponse();
        }
    }

    private function httpError(Response $response): never
    {
        throw new KsefApiException(
            'Latarnia KSeF zwróciła błąd podczas komunikacji.',
            'ksef_latarnia_http_error',
            $response->status(),
        );
    }

    private function invalidResponse(): never
    {
        throw new KsefApiException(
            'Latarnia KSeF zwróciła odpowiedź w nieprawidłowym formacie.',
            'ksef_latarnia_response_invalid',
        );
    }
}
