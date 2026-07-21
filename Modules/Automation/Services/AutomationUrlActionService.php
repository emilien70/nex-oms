<?php

namespace Modules\Automation\Services;

use App\Services\OrderVariableService;
use DomainException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Modules\Automation\Models\AutomationRun;
use Modules\Shipments\Models\IntegrationApiLog;
use Throwable;

class AutomationUrlActionService
{
    public function __construct(private readonly OrderVariableService $variables) {}

    public function execute(AutomationRun $run, array $configuration): array
    {
        $urlTemplate = $this->validatedUrl((string) ($configuration['url'] ?? ''));
        $unknownVariables = $this->variables->unknownVariables($urlTemplate);

        if ($unknownVariables !== []) {
            throw new DomainException('Nieznane zmienne w adresie URL: '.implode(', ', $unknownVariables).'.');
        }

        $url = $this->validatedUrl($this->variables->renderForUrl($urlTemplate, $run->order));
        $requestId = (string) Str::uuid();
        $startedAt = hrtime(true);

        try {
            $response = Http::acceptJson()
                ->withHeaders([
                    'X-Request-ID' => $requestId,
                    'X-NEX-OMS-Order-ID' => (string) $run->order_id,
                ])
                ->connectTimeout((int) config('services.automation_url.connect_timeout', 5))
                ->timeout((int) config('services.automation_url.timeout', 10))
                ->get($url);
        } catch (Throwable $exception) {
            $this->writeLog($run, $requestId, $url, null, null, $startedAt, false, $exception->getMessage());

            throw new DomainException('Nie udalo sie wywolac URL: '.$exception->getMessage(), previous: $exception);
        }

        $responsePayload = $this->responsePayload($response);
        $successful = $response->successful();
        $errorMessage = $successful ? null : $this->errorMessage($responsePayload, $response->status());

        $this->writeLog(
            $run,
            $requestId,
            $url,
            $response->status(),
            $responsePayload,
            $startedAt,
            $successful,
            $errorMessage,
        );

        if (! $successful) {
            throw new DomainException($errorMessage);
        }

        return [
            'method' => 'GET',
            'request_id' => $requestId,
            'response_status' => $response->status(),
        ];
    }

    private function validatedUrl(string $url): string
    {
        $url = trim($url);
        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));

        if ($url === '' || strlen($url) > 2048 || filter_var($url, FILTER_VALIDATE_URL) === false
            || ! in_array($scheme, ['http', 'https'], true) || $host === '') {
            throw new DomainException('Nieprawidlowy adres URL akcji automatycznej.');
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new DomainException('Adres URL nie moze zawierac danych logowania.');
        }

        if ($host === 'localhost' || str_ends_with($host, '.localhost') || $this->isBlockedIp($host)) {
            throw new DomainException('Adres URL nie moze wskazywac na lokalny lub prywatny adres sieciowy.');
        }

        return $url;
    }

    private function isBlockedIp(string $host): bool
    {
        if (filter_var($host, FILTER_VALIDATE_IP) === false) {
            return false;
        }

        return filter_var(
            $host,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
        ) === false;
    }

    private function responsePayload(Response $response): ?array
    {
        $json = $response->json();

        if (is_array($json)) {
            return $json;
        }

        if ($response->body() === '') {
            return null;
        }

        return [
            'content_type' => $response->header('Content-Type'),
            'body' => Str::limit($response->body(), 10000),
        ];
    }

    private function errorMessage(?array $payload, int $status): string
    {
        $message = data_get($payload, 'error') ?? data_get($payload, 'message');

        return is_string($message) && trim($message) !== ''
            ? trim($message)
            : 'URL zwrocil blad HTTP '.$status.'.';
    }

    private function writeLog(
        AutomationRun $run,
        string $requestId,
        string $url,
        ?int $responseStatus,
        ?array $responsePayload,
        int $startedAt,
        bool $successful,
        ?string $errorMessage,
    ): void {
        IntegrationApiLog::query()->create([
            'integration' => 'automation_url',
            'operation' => 'call_url',
            'order_id' => $run->order_id,
            'request_id' => $requestId,
            'method' => 'GET',
            'url' => $url,
            'request_payload' => [
                'automation_run_id' => $run->id,
                'automation_rule_id' => $run->automation_rule_id,
            ],
            'response_status' => $responseStatus,
            'response_payload' => $responsePayload,
            'duration_ms' => (int) round((hrtime(true) - $startedAt) / 1_000_000),
            'successful' => $successful,
            'error_message' => $errorMessage,
        ]);
    }
}
