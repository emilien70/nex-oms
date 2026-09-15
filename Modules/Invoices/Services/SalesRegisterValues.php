<?php

namespace Modules\Invoices\Services;

use DateTimeImmutable;
use Modules\Invoices\Exceptions\InvoiceDomainException;

/** Pure validation and aggregation of persisted report values. */
final class SalesRegisterValues
{
    public function __construct(
        private readonly InvoiceDecimalCalculator $decimal,
        private readonly InvoiceTaxIdentityNormalizer $taxIdentity,
    ) {}

    public function money(mixed $value): ?string
    {
        // SQLite PDO can return numeric columns as native scalars; arithmetic starts only after conversion to text.
        if (! is_string($value) && ! is_int($value) && ! is_float($value)) {
            return null;
        }
        $text = (string) $value;
        if (! preg_match('/^-?\d+(?:\.\d{1,2}0*)?$/D', $text)) {
            return null;
        }

        return $this->decimal->normalize($text, 2);
    }

    public function totals(array $data, string $prefix = ''): ?array
    {
        $totals = [];
        foreach (['net', 'vat', 'gross'] as $key) {
            $totals[$key] = $this->money($data[$prefix.$key] ?? null);
            if ($totals[$key] === null) {
                return null;
            }
        }

        return $this->decimal->add($totals['net'], $totals['vat']) === $totals['gross'] ? $totals : null;
    }

    public function groups(mixed $data): ?array
    {
        if (! is_array($data) || ! array_is_list($data)) {
            return null;
        }
        $groups = [];
        foreach ($data as $group) {
            if (! is_array($group) || ($totals = $this->totals($group)) === null) {
                return null;
            }
            $identity = $this->identity($group);
            if ($identity === null || isset($groups[$identity['key']])) {
                return null;
            }
            $groups[$identity['key']] = $identity + $totals;
        }

        return $this->sortGroups($groups);
    }

    public function identity(array $data): ?array
    {
        $code = $data['vat_code'] ?? null;
        $rate = $data['vat_rate'] ?? null;
        if (($code !== null && ! is_string($code))
            || ((($code === null || trim($code) === '') && $rate !== null) && ! is_string($rate) && ! is_int($rate))) {
            return null;
        }
        try {
            $identity = $this->taxIdentity->normalize($rate, $code);
        } catch (InvoiceDomainException) {
            return null;
        }
        $key = $this->taxIdentity->key($identity);
        if ($key === null) {
            return null;
        }

        return ['key' => $key, 'label' => $identity['vat_code'] ?? rtrim(rtrim($identity['vat_rate'], '0'), '.').'%'] + $identity;
    }

    public function sortGroups(array $groups): array
    {
        uasort($groups, function (array $a, array $b): int {
            if ($a['vat_rate'] !== null && $b['vat_rate'] !== null) {
                return $this->decimal->compare($b['vat_rate'], $a['vat_rate']);
            }
            if (($a['vat_rate'] === null) !== ($b['vat_rate'] === null)) {
                return $a['vat_rate'] === null ? 1 : -1;
            }

            return strcmp($a['key'], $b['key']);
        });

        return array_values($groups);
    }

    public function sum(array $amounts): array
    {
        $sum = $this->zero();
        foreach ($amounts as $amount) {
            foreach ($sum as $key => $unused) {
                $sum[$key] = $this->decimal->add($sum[$key], $amount[$key]);
            }
        }

        return $sum;
    }

    public function zero(): array
    {
        return ['net' => '0.00', 'vat' => '0.00', 'gross' => '0.00'];
    }

    public function date(mixed $value): ?string
    {
        if (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2} 00:00:00$/D', $value)) {
            $value = substr($value, 0, 10);
        }
        if (! is_string($value) || ! preg_match('/^[1-9]\d{3}-\d{2}-\d{2}$/D', $value)) {
            return null;
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $date !== false && $date->format('Y-m-d') === $value ? $value : null;
    }

    public function text(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
