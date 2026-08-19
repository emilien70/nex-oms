<?php

namespace Modules\Ksef\Services;

use Carbon\CarbonImmutable;
use Modules\Ksef\Enums\KsefEnvironment;
use Modules\Ksef\Exceptions\KsefApiException;
use Modules\Ksef\ValueObjects\KsefOnlineSessionOpenResult;
use Throwable;

class KsefOnlineSessionClient
{
    public function __construct(
        private readonly KsefHttpClient $http,
    ) {}

    public function publicKeyCertificates(KsefEnvironment $environment): array
    {
        return $this->http
            ->get($environment, '/security/public-key-certificates')
            ->data;
    }

    public function openSession(
        KsefEnvironment $environment,
        string $accessToken,
        array $payload,
    ): KsefOnlineSessionOpenResult {
        $data = $this->http
            ->post($environment, '/sessions/online', $payload, $accessToken)
            ->data;
        $referenceNumber = $this->requiredString($data, 'referenceNumber', 'ksef_session_response_incomplete');
        $validUntil = $this->requiredDate($data, 'validUntil', 'ksef_session_response_incomplete');

        return new KsefOnlineSessionOpenResult($referenceNumber, $validUntil);
    }

    public function sendInvoice(
        KsefEnvironment $environment,
        string $accessToken,
        string $sessionReference,
        array $payload,
    ): string {
        $data = $this->http
            ->post(
                $environment,
                '/sessions/online/'.rawurlencode($sessionReference).'/invoices',
                $payload,
                $accessToken,
            )
            ->data;

        return $this->requiredString(
            $data,
            'referenceNumber',
            'ksef_invoice_send_response_incomplete',
        );
    }

    public function closeSession(
        KsefEnvironment $environment,
        string $accessToken,
        string $sessionReference,
    ): void {
        $this->http->post(
            $environment,
            '/sessions/online/'.rawurlencode($sessionReference).'/close',
            bearerToken: $accessToken,
        );
    }

    public function invoiceStatus(
        KsefEnvironment $environment,
        string $accessToken,
        string $sessionReference,
        string $invoiceReference,
    ): array {
        return $this->http
            ->get(
                $environment,
                '/sessions/'.rawurlencode($sessionReference)
                    .'/invoices/'.rawurlencode($invoiceReference),
                $accessToken,
            )
            ->data;
    }

    private function requiredString(array $data, string $path, string $safeCode): string
    {
        $value = data_get($data, $path);

        if (! is_string($value) || trim($value) === '') {
            throw new KsefApiException(
                'KSeF zwrócił niekompletną odpowiedź sesji fakturowej.',
                $safeCode,
            );
        }

        return trim($value);
    }

    private function requiredDate(array $data, string $path, string $safeCode): CarbonImmutable
    {
        $value = $this->requiredString($data, $path, $safeCode);

        try {
            return CarbonImmutable::parse($value)->utc();
        } catch (Throwable) {
            throw new KsefApiException(
                'KSeF zwrócił nieprawidłową datę w odpowiedzi sesji fakturowej.',
                $safeCode,
            );
        }
    }
}
