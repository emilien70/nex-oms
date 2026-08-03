<?php

namespace Modules\Invoices\Services;

use DateTimeImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Modules\Invoices\Exceptions\InvoiceDomainException;
use Modules\Invoices\ValueObjects\NbpExchangeRate;
use SimpleXMLElement;
use Throwable;

class NbpExchangeRateClient
{
    public function fetch(string $currencyCode, string $tableType, string $referenceDate): NbpExchangeRate
    {
        $currency = strtoupper(trim($currencyCode));
        $table = strtoupper(trim($tableType));
        $reference = $this->parseDate($referenceDate);

        if (preg_match('/^[A-Z]{3}$/', $currency) !== 1 || ! in_array($table, ['A', 'B'], true)) {
            throw $this->invalidResponse($currency);
        }

        $lookupDays = max(1, min(93, (int) config('nbp.historical_lookup_days', 93)));
        $endDate = $reference->modify('-1 day');
        $startDate = $endDate->modify('-'.($lookupDays - 1).' days');
        $response = $this->request(
            $currency,
            $table,
            $startDate->format('Y-m-d'),
            $endDate->format('Y-m-d'),
        );

        if ($response->status() === 404) {
            throw $this->notFound($currency);
        }

        if (! $response->successful()) {
            Log::warning('NBP zwrócił błąd podczas pobierania historycznego kursu.', [
                'currency' => $currency,
                'status' => $response->status(),
            ]);

            throw $this->unavailable($currency);
        }

        return $this->parseResponse(
            $response->body(),
            $currency,
            $table,
            $reference,
            $startDate,
            $endDate,
        );
    }

    private function request(
        string $currency,
        string $table,
        string $startDate,
        string $endDate,
    ): Response {
        $baseUrl = rtrim((string) config('nbp.rates_base_url'), '/');
        if (! str_starts_with($baseUrl, 'https://')) {
            throw $this->unavailable($currency);
        }

        $url = implode('/', [$baseUrl, $table, $currency, $startDate, $endDate]).'/';
        $retries = max(0, (int) config('nbp.retries', 2));
        $delay = max(0, (int) config('nbp.retry_delay_ms', 250));

        for ($attempt = 0; $attempt <= $retries; $attempt++) {
            try {
                $response = Http::accept('application/xml')
                    ->connectTimeout((int) config('nbp.connect_timeout', 5))
                    ->timeout((int) config('nbp.timeout', 15))
                    ->get($url, ['format' => 'xml']);
            } catch (ConnectionException $exception) {
                if ($attempt < $retries) {
                    $this->pause($delay);

                    continue;
                }

                $this->logFailure($currency, 'connection', $exception);
                throw $this->unavailable($currency, $exception);
            } catch (Throwable $exception) {
                $this->logFailure($currency, 'request', $exception);
                throw $this->unavailable($currency, $exception);
            }

            if ($response->serverError() && $attempt < $retries) {
                $this->pause($delay);

                continue;
            }

            return $response;
        }

        throw $this->unavailable($currency);
    }

    private function parseResponse(
        string $body,
        string $currency,
        string $table,
        DateTimeImmutable $reference,
        DateTimeImmutable $startDate,
        DateTimeImmutable $endDate,
    ): NbpExchangeRate {
        $xml = $this->loadXml($body, $currency);

        if (strtoupper(trim((string) ($xml->Code ?? ''))) !== $currency
            || strtoupper(trim((string) ($xml->Table ?? ''))) !== $table
            || ! isset($xml->Rates)) {
            throw $this->invalidResponse($currency);
        }

        $candidates = [];
        foreach ($xml->Rates->Rate as $rate) {
            $tableNumber = trim((string) ($rate->No ?? ''));
            $effectiveDateText = trim((string) ($rate->EffectiveDate ?? ''));
            $mid = trim((string) ($rate->Mid ?? ''));
            $effectiveDate = $this->tryParseDate($effectiveDateText);

            if ($tableNumber === '' || $effectiveDate === null || ! $this->isPositiveDecimal($mid)) {
                throw $this->invalidResponse($currency);
            }

            if ($effectiveDate >= $startDate
                && $effectiveDate <= $endDate
                && $effectiveDate < $reference) {
                $candidates[] = [
                    'table_number' => $tableNumber,
                    'effective_date' => $effectiveDate,
                    'rate' => $mid,
                ];
            }
        }

        if ($candidates === []) {
            throw $this->notFound($currency);
        }

        usort(
            $candidates,
            static fn (array $left, array $right): int => $right['effective_date'] <=> $left['effective_date'],
        );
        $selected = $candidates[0];

        return new NbpExchangeRate(
            source: 'NBP',
            currencyCode: $currency,
            tableType: $table,
            tableNumber: $selected['table_number'],
            effectiveDate: $selected['effective_date']->format('Y-m-d'),
            referenceDate: $reference->format('Y-m-d'),
            rate: $selected['rate'],
        );
    }

    private function loadXml(string $body, string $currency): SimpleXMLElement
    {
        if (trim($body) === '') {
            throw $this->invalidResponse($currency);
        }

        $previous = libxml_use_internal_errors(true);

        try {
            $xml = simplexml_load_string($body, SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        if (! $xml instanceof SimpleXMLElement) {
            throw $this->invalidResponse($currency);
        }

        return $xml;
    }

    private function parseDate(string $value): DateTimeImmutable
    {
        $date = $this->tryParseDate($value);

        if ($date === null) {
            throw new InvoiceDomainException(
                'invoice_exchange_rate_reference_date_missing',
                'Nie można ustalić daty właściwej dla kursu NBP.',
            );
        }

        return $date;
    }

    private function tryParseDate(string $value): ?DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        $errors = DateTimeImmutable::getLastErrors();

        if ($date === false
            || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
            || $date->format('Y-m-d') !== $value) {
            return null;
        }

        return $date;
    }

    private function isPositiveDecimal(string $value): bool
    {
        if (preg_match('/^(?:0|[1-9]\d*)(?:\.\d+)?$/', $value) !== 1) {
            return false;
        }

        return preg_match('/[1-9]/', $value) === 1;
    }

    private function pause(int $milliseconds): void
    {
        if ($milliseconds > 0) {
            usleep($milliseconds * 1000);
        }
    }

    private function logFailure(string $currency, string $reason, Throwable $exception): void
    {
        Log::warning('Nie udało się pobrać historycznego kursu NBP.', [
            'currency' => $currency,
            'reason' => $reason,
            'exception' => $exception::class,
        ]);
    }

    private function unavailable(string $currency, ?Throwable $previous = null): InvoiceDomainException
    {
        return new InvoiceDomainException(
            'invoice_exchange_rate_unavailable',
            "Nie można wystawić Faktury. Nie udało się pobrać właściwego kursu NBP dla waluty {$currency}.",
            [],
            $previous,
        );
    }

    private function invalidResponse(string $currency): InvoiceDomainException
    {
        return new InvoiceDomainException(
            'invoice_exchange_rate_invalid_response',
            "Nie można wystawić Faktury. Odpowiedź NBP dla waluty {$currency} jest nieprawidłowa.",
        );
    }

    private function notFound(string $currency): InvoiceDomainException
    {
        return new InvoiceDomainException(
            'invoice_exchange_rate_not_found',
            "Nie można wystawić Faktury. Nie znaleziono właściwego kursu NBP dla waluty {$currency}.",
        );
    }
}
