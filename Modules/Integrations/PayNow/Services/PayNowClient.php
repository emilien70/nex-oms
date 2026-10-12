<?php

namespace Modules\Integrations\PayNow\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Modules\Integrations\PayNow\Enums\PayNowPaymentStatus;
use Modules\Integrations\PayNow\Exceptions\PayNowApiException;
use Modules\Integrations\PayNow\Models\PayNowAccount;
use Throwable;

class PayNowClient
{
    private const CONNECTION_TEST_PARAMETERS = [
        'amount' => 100,
        'currency' => 'PLN',
    ];

    public function __construct(
        private readonly PayNowSignatureService $signatureService,
    ) {}

    public function testConnection(PayNowAccount $account): int
    {
        if (! $account->hasCompleteCredentials()) {
            throw new PayNowApiException('Najpierw zapisz Klucz API i Klucz podpisu PayNow.');
        }

        $apiKey = (string) $account->api_key;
        $signatureKey = (string) $account->signature_key;
        $idempotencyKey = (string) Str::uuid();
        $signature = $this->signatureService->generateV3(
            $apiKey,
            $signatureKey,
            $idempotencyKey,
            parameters: self::CONNECTION_TEST_PARAMETERS,
        );

        try {
            $response = Http::acceptJson()
                ->connectTimeout(5)
                ->timeout(10)
                ->withHeaders([
                    'Api-Key' => $apiKey,
                    'Idempotency-Key' => $idempotencyKey,
                    'Signature' => $signature,
                    'User-Agent' => 'NEX-OMS PayNow Integration',
                ])
                ->get(
                    $account->environment->baseUrl().'/v3/payments/paymentmethods',
                    self::CONNECTION_TEST_PARAMETERS,
                );
        } catch (ConnectionException) {
            throw new PayNowApiException('Nie udało się połączyć z PayNow. Sprawdź połączenie sieciowe i spróbuj ponownie.');
        } catch (Throwable) {
            throw new PayNowApiException('Wystąpił nieoczekiwany błąd komunikacji z PayNow.');
        }

        if (! $response->successful()) {
            throw new PayNowApiException(
                $this->safeErrorMessage($response),
                $response->status(),
            );
        }

        $paymentMethods = $response->json();

        if (! is_array($paymentMethods)) {
            throw new PayNowApiException('PayNow zwrócił nieprawidłową odpowiedź podczas testu połączenia.');
        }

        return collect($paymentMethods)
            ->sum(fn (mixed $group): int => is_array($group) && is_array($group['paymentMethods'] ?? null)
                ? count($group['paymentMethods'])
                : 0);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{url: string, payment_id: string, status: PayNowPaymentStatus, payload: array<string, mixed>, raw_body: string}
     */
    public function createPaymentLink(PayNowAccount $account, array $payload, string $idempotencyKey): array
    {
        if (! $account->hasCompleteCredentials()) {
            throw new PayNowApiException(
                'Integracja PayNow nie posiada kompletnych danych dostępowych.',
                safeCode: 'credentials_missing',
            );
        }

        $apiKey = (string) $account->api_key;
        $signatureKey = (string) $account->signature_key;

        try {
            $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            $signature = $this->signatureService->generateV3(
                $apiKey,
                $signatureKey,
                $idempotencyKey,
                body: $body,
            );

            $response = Http::acceptJson()
                ->connectTimeout(5)
                ->timeout(15)
                ->withHeaders([
                    'Api-Key' => $apiKey,
                    'Idempotency-Key' => $idempotencyKey,
                    'Signature' => $signature,
                    'User-Agent' => 'NEX-OMS PayNow Integration',
                ])
                ->withBody($body, 'application/json')
                ->post($account->environment->baseUrl().'/v3/payments/links');
        } catch (ConnectionException) {
            throw new PayNowApiException(
                'Nie udało się połączyć z PayNow. Spróbuj ponownie.',
                safeCode: 'connection_error',
            );
        } catch (Throwable) {
            throw new PayNowApiException(
                'Wystąpił nieoczekiwany błąd komunikacji z PayNow.',
                safeCode: 'communication_error',
            );
        }

        if (! $response->successful()) {
            $errorType = $this->errorType($response);

            throw new PayNowApiException(
                $this->safePaymentLinkErrorMessage($response, $errorType),
                $response->status(),
                $errorType ?: 'provider_error',
            );
        }

        $responsePayload = $response->json();
        $url = is_array($responsePayload) ? trim((string) ($responsePayload['url'] ?? '')) : '';
        $paymentId = is_array($responsePayload) ? trim((string) ($responsePayload['paymentId'] ?? '')) : '';
        $status = is_array($responsePayload)
            ? PayNowPaymentStatus::tryFrom((string) ($responsePayload['status'] ?? ''))
            : null;

        if (! is_array($responsePayload) || ! $this->isPayNowUrl($url) || $paymentId === '' || ! $status) {
            throw new PayNowApiException(
                'PayNow zwrócił nieprawidłową odpowiedź podczas tworzenia linku.',
                $response->status(),
                'invalid_response',
            );
        }

        if ($status === PayNowPaymentStatus::Error) {
            throw new PayNowApiException(
                'PayNow nie utworzył aktywnego linku do płatności.',
                $response->status(),
                'payment_error',
            );
        }

        return [
            'url' => $url,
            'payment_id' => $paymentId,
            'status' => $status,
            'payload' => $responsePayload,
            'raw_body' => $response->body(),
        ];
    }

    /**
     * @return array{payment_id: string, status: PayNowPaymentStatus, payload: array<string, mixed>, raw_body: string}
     */
    public function getPaymentStatus(PayNowAccount $account, string $providerPaymentId): array
    {
        if (! $account->hasCompleteCredentials()) {
            throw new PayNowApiException(
                'Integracja PayNow nie posiada kompletnych danych dostępowych.',
                safeCode: 'credentials_missing',
            );
        }

        $apiKey = (string) $account->api_key;
        $signatureKey = (string) $account->signature_key;
        $idempotencyKey = (string) Str::uuid();
        $signature = $this->signatureService->generateV3(
            $apiKey,
            $signatureKey,
            $idempotencyKey,
        );

        try {
            $response = Http::acceptJson()
                ->connectTimeout(5)
                ->timeout(15)
                ->withHeaders([
                    'Api-Key' => $apiKey,
                    'Idempotency-Key' => $idempotencyKey,
                    'Signature' => $signature,
                    'User-Agent' => 'NEX-OMS PayNow Integration',
                ])
                ->get($account->environment->baseUrl().'/v3/payments/'.rawurlencode($providerPaymentId).'/status');
        } catch (ConnectionException) {
            throw new PayNowApiException(
                'Nie udało się sprawdzić statusu płatności PayNow.',
                safeCode: 'connection_error',
            );
        } catch (Throwable) {
            throw new PayNowApiException(
                'Wystąpił nieoczekiwany błąd sprawdzania płatności PayNow.',
                safeCode: 'communication_error',
            );
        }

        if (! $response->successful()) {
            throw new PayNowApiException(
                $this->safePaymentStatusErrorMessage($response),
                $response->status(),
                $this->errorType($response) ?: 'provider_error',
            );
        }

        $payload = $response->json();
        $paymentId = is_array($payload) ? trim((string) ($payload['paymentId'] ?? '')) : '';
        $status = is_array($payload)
            ? PayNowPaymentStatus::tryFrom((string) ($payload['status'] ?? ''))
            : null;

        if (! is_array($payload) || $paymentId !== $providerPaymentId || ! $status) {
            throw new PayNowApiException(
                'PayNow zwrócił nieprawidłową odpowiedź statusu płatności.',
                $response->status(),
                'invalid_response',
            );
        }

        return [
            'payment_id' => $paymentId,
            'status' => $status,
            'payload' => $payload,
            'raw_body' => $response->body(),
        ];
    }

    private function safeErrorMessage(Response $response): string
    {
        $errorType = $response->json('errors.0.errorType');

        if ($errorType === 'VERIFICATION_FAILED') {
            return 'PayNow nie mógł zweryfikować podpisu żądania. Sprawdź, czy Klucz podpisu odpowiada zapisanemu Kluczowi API i wybranemu środowisku.';
        }

        return match ($response->status()) {
            400 => 'PayNow odrzucił żądanie testowe. Sprawdź konfigurację integracji.',
            401, 403 => 'PayNow odrzucił dane dostępowe. Sprawdź Klucz API i Klucz podpisu.',
            404 => 'Endpoint PayNow nie jest dostępny dla wybranego środowiska.',
            429 => 'Limit zapytań PayNow został chwilowo przekroczony. Spróbuj ponownie później.',
            500, 502, 503, 504 => 'Usługa PayNow jest chwilowo niedostępna. Spróbuj ponownie później.',
            default => 'Test połączenia z PayNow nie powiódł się.',
        };
    }

    private function errorType(Response $response): ?string
    {
        $errorType = $response->json('errors.0.errorType');

        return is_string($errorType) && $errorType !== ''
            ? mb_substr($errorType, 0, 100)
            : null;
    }

    private function safePaymentLinkErrorMessage(Response $response, ?string $errorType): string
    {
        return match ($errorType) {
            'PAYMENT_AMOUNT_TOO_SMALL' => 'Kwota linku jest niższa niż minimalna kwota obsługiwana przez PayNow.',
            'PAYMENT_AMOUNT_TOO_LARGE' => 'Kwota linku przekracza limit obsługiwany przez PayNow.',
            'VERIFICATION_FAILED' => 'PayNow nie mógł zweryfikować podpisu żądania. Sprawdź konfigurację integracji.',
            'UNAUTHORIZED', 'FORBIDDEN' => 'PayNow odrzucił dane dostępowe. Sprawdź konfigurację integracji.',
            'VALIDATION_ERROR' => 'PayNow odrzucił dane linku do płatności.',
            'RATE_LIMIT_REACHED' => 'Limit zapytań PayNow został chwilowo przekroczony. Spróbuj ponownie później.',
            'SYSTEM_TEMPORARILY_UNAVAILABLE' => 'Usługa PayNow jest chwilowo niedostępna. Spróbuj ponownie później.',
            default => match ($response->status()) {
                401, 403 => 'PayNow odrzucił dane dostępowe. Sprawdź konfigurację integracji.',
                429 => 'Limit zapytań PayNow został chwilowo przekroczony. Spróbuj ponownie później.',
                500, 502, 503, 504 => 'Usługa PayNow jest chwilowo niedostępna. Spróbuj ponownie później.',
                default => 'Nie udało się utworzyć linku do płatności PayNow.',
            },
        };
    }

    private function safePaymentStatusErrorMessage(Response $response): string
    {
        return match ($response->status()) {
            401, 403 => 'PayNow odrzucił dane dostępowe podczas sprawdzania płatności.',
            404 => 'Płatność nie została odnaleziona w PayNow.',
            429 => 'Limit zapytań PayNow został chwilowo przekroczony.',
            500, 502, 503, 504 => 'Usługa PayNow jest chwilowo niedostępna.',
            default => 'Nie udało się sprawdzić statusu płatności PayNow.',
        };
    }

    private function isPayNowUrl(string $url): bool
    {
        $parts = parse_url($url);
        $host = strtolower((string) ($parts['host'] ?? ''));

        return ($parts['scheme'] ?? null) === 'https'
            && ($host === 'paynow.pl' || str_ends_with($host, '.paynow.pl'));
    }
}
