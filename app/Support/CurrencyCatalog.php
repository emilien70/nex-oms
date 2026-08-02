<?php

namespace App\Support;

use App\Models\Currency;
use DomainException;

class CurrencyCatalog
{
    public const SYSTEM_CURRENCY = 'PLN';

    public const INVALID_CURRENCY_MESSAGE = 'Wybierz prawidłową walutę.';

    /** @return array<string, string> */
    public function all(): array
    {
        return Currency::query()
            ->orderByRaw('CASE WHEN code = ? THEN 0 ELSE 1 END', [self::SYSTEM_CURRENCY])
            ->orderBy('code')
            ->pluck('code', 'code')
            ->all();
    }

    /** @return list<string> */
    public function codes(): array
    {
        return array_keys($this->all());
    }

    public function find(mixed $code): ?Currency
    {
        $normalized = $this->normalize($code);

        return $normalized !== null && $this->hasValidFormat($normalized)
            ? Currency::query()->find($normalized)
            : null;
    }

    public function exists(mixed $code): bool
    {
        return $this->find($code) !== null;
    }

    public function normalize(mixed $code): ?string
    {
        if (! is_string($code)) {
            return null;
        }

        $normalized = strtoupper(trim($code));

        return $normalized !== '' ? $normalized : null;
    }

    public function hasValidFormat(mixed $code): bool
    {
        $normalized = $this->normalize($code);

        return $normalized !== null && preg_match('/^[A-Z]{3}$/', $normalized) === 1;
    }

    public function isAllowed(mixed $code, mixed $unchangedHistoricalCode = null): bool
    {
        $normalized = $this->normalize($code);

        if ($normalized === null || ! $this->hasValidFormat($normalized)) {
            return false;
        }

        if ($this->exists($normalized)) {
            return true;
        }

        $historical = $this->normalize($unchangedHistoricalCode);

        return $historical !== null && hash_equals($historical, $normalized);
    }

    public function require(mixed $code, mixed $unchangedHistoricalCode = null): string
    {
        $normalized = $this->normalize($code);

        if (! $this->isAllowed($normalized, $unchangedHistoricalCode)) {
            throw new DomainException(self::INVALID_CURRENCY_MESSAGE);
        }

        return $normalized;
    }
}
