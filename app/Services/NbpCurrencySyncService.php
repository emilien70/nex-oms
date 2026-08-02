<?php

namespace App\Services;

use App\Exceptions\CurrencySyncException;
use App\Models\Currency;
use App\Support\CurrencyCatalog;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Throwable;

class NbpCurrencySyncService
{
    public function __construct(private readonly CurrencyCatalog $currencies) {}

    public function sync(): int
    {
        $tableA = $this->fetchTable('A');
        $tableB = $this->fetchTable('B');
        $rows = $this->mergeTables($tableA, $tableB);

        try {
            DB::transaction(function () use ($rows): void {
                Currency::query()->insertOrIgnore([
                    'code' => CurrencyCatalog::SYSTEM_CURRENCY,
                    'name' => CurrencyCatalog::SYSTEM_CURRENCY,
                    'nbp_table' => null,
                ]);

                if ($rows !== []) {
                    Currency::query()->upsert(
                        array_values($rows),
                        ['code'],
                        ['name', 'nbp_table'],
                    );
                }
            });
        } catch (Throwable $exception) {
            throw new CurrencySyncException(
                'Nie udało się zapisać katalogu walut NBP.',
                previous: $exception,
            );
        }

        return Currency::query()->count();
    }

    /** @return array<string, array{code: string, name: string, nbp_table: string}> */
    private function fetchTable(string $table): array
    {
        $baseUrl = rtrim((string) config('nbp.base_url'), '/');
        if (! str_starts_with($baseUrl, 'https://')) {
            throw new CurrencySyncException('Adres usługi NBP musi używać protokołu HTTPS.');
        }

        try {
            $response = $this->httpClient()->get($baseUrl.'/'.$table.'/', [
                'format' => 'json',
            ]);
            $response->throw();
            $payload = $response->json();
        } catch (Throwable $exception) {
            throw new CurrencySyncException(
                "Nie udało się pobrać tabeli {$table} NBP.",
                previous: $exception,
            );
        }

        if (! is_array($payload)
            || count($payload) !== 1
            || ! is_array($payload[0] ?? null)
            || ! is_array($payload[0]['rates'] ?? null)) {
            throw new CurrencySyncException("Tabela {$table} NBP ma nieprawidłową strukturę odpowiedzi.");
        }

        $rows = [];
        foreach ($payload[0]['rates'] as $rate) {
            if (! is_array($rate)) {
                throw new CurrencySyncException("Tabela {$table} NBP zawiera nieprawidłowy rekord waluty.");
            }

            $code = $this->currencies->normalize($rate['code'] ?? null);
            $name = is_string($rate['currency'] ?? null) ? trim($rate['currency']) : '';

            if ($code === null || ! $this->currencies->hasValidFormat($code) || $name === '') {
                throw new CurrencySyncException("Tabela {$table} NBP zawiera nieprawidłowy rekord waluty.");
            }

            if ($code === CurrencyCatalog::SYSTEM_CURRENCY) {
                continue;
            }

            if (array_key_exists($code, $rows)) {
                throw new CurrencySyncException("Tabela {$table} NBP zawiera zduplikowany kod waluty {$code}.");
            }

            $rows[$code] = [
                'code' => $code,
                'name' => $name,
                'nbp_table' => $table,
            ];
        }

        return $rows;
    }

    /**
     * @param  array<string, array{code: string, name: string, nbp_table: string}>  $tableA
     * @param  array<string, array{code: string, name: string, nbp_table: string}>  $tableB
     * @return array<string, array{code: string, name: string, nbp_table: string}>
     */
    private function mergeTables(array $tableA, array $tableB): array
    {
        $conflicts = array_intersect(array_keys($tableA), array_keys($tableB));
        if ($conflicts !== []) {
            sort($conflicts, SORT_STRING);

            throw new CurrencySyncException(
                'Ten sam kod waluty występuje w tabelach A i B NBP: '.implode(', ', $conflicts).'.',
            );
        }

        return $tableA + $tableB;
    }

    private function httpClient(): PendingRequest
    {
        return Http::acceptJson()
            ->connectTimeout((int) config('nbp.connect_timeout', 5))
            ->timeout((int) config('nbp.timeout', 15))
            ->retry(
                max(1, (int) config('nbp.retries', 2)),
                max(0, (int) config('nbp.retry_delay_ms', 250)),
            );
    }
}
