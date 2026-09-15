<?php

namespace Modules\Invoices\ValueObjects;

use DateTimeImmutable;
use Modules\Invoices\Exceptions\InvoiceDomainException;

final readonly class SalesRegisterFilters
{
    private function __construct(
        public string $mode,
        public array $seriesIds,
        public array $documentIds,
        public ?string $issueFrom,
        public ?string $issueTo,
        public ?string $saleFrom,
        public ?string $saleTo,
        public string $taxIdPresence,
        public ?string $currency,
        public ?string $country,
        public bool $includeKsef,
    ) {}

    public static function forPeriod(array $input): self
    {
        $manual = array_key_exists('issue_from', $input) || array_key_exists('issue_to', $input);
        if ($manual) {
            $from = self::date($input['issue_from'] ?? null, 'issue_from');
            $to = self::date($input['issue_to'] ?? null, 'issue_to');
        } else {
            $month = $input['month'] ?? null;
            if (! is_string($month) || ! preg_match('/^\d{4}-\d{2}$/D', $month)) {
                throw self::invalid('month');
            }
            $from = self::date($month.'-01', 'month');
            $to = (new DateTimeImmutable($from))->modify('last day of this month')->format('Y-m-d');
        }
        if ($from > $to) {
            throw self::invalid('issue_to');
        }
        $saleFrom = isset($input['sale_from']) ? self::date($input['sale_from'], 'sale_from') : null;
        $saleTo = isset($input['sale_to']) ? self::date($input['sale_to'], 'sale_to') : null;
        if ($saleFrom !== null && $saleTo !== null && $saleFrom > $saleTo) {
            throw self::invalid('sale_to');
        }
        $presence = $input['tax_id_presence'] ?? 'all';
        if (! in_array($presence, ['all', 'with', 'without'], true)) {
            throw self::invalid('tax_id_presence');
        }

        return new self(
            'period', self::ids($input['series_ids'] ?? null, 'series_ids'), [], $from, $to,
            $saleFrom, $saleTo, $presence, self::code($input['currency'] ?? null, 3, 'currency'),
            self::code($input['country'] ?? null, 2, 'country'), self::includeKsef($input['include_ksef'] ?? true),
        );
    }

    public static function forDocuments(array $ids, bool $includeKsef = true): self
    {
        return new self('ids', [], self::ids($ids, 'document_ids'), null, null, null, null, 'all', null, null, $includeKsef);
    }

    private static function ids(mixed $values, string $field): array
    {
        if (! is_array($values) || ! array_is_list($values) || $values === []) {
            throw self::invalid($field);
        }
        foreach ($values as $value) {
            if ((! is_int($value) && ! is_string($value))
                || ! preg_match('/^[1-9]\d*$/D', (string) $value)
                || filter_var($value, FILTER_VALIDATE_INT) === false) {
                throw self::invalid($field);
            }
        }
        $ids = array_values(array_unique(array_map('intval', $values)));
        sort($ids, SORT_NUMERIC);

        return $ids;
    }

    private static function date(mixed $value, string $field): string
    {
        if (! is_string($value) || ! preg_match('/^[1-9]\d{3}-\d{2}-\d{2}$/D', $value)) {
            throw self::invalid($field);
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if ($date === false || $date->format('Y-m-d') !== $value) {
            throw self::invalid($field);
        }

        return $value;
    }

    private static function code(mixed $value, int $length, string $field): ?string
    {
        if ($value === null) {
            return null;
        }
        if (! is_string($value) || ! preg_match('/^[A-Z]{'.$length.'}$/D', strtoupper(trim($value)))) {
            throw self::invalid($field);
        }

        return strtoupper(trim($value));
    }

    private static function includeKsef(mixed $value): bool
    {
        if (! is_bool($value)) {
            throw self::invalid('include_ksef');
        }

        return $value;
    }

    private static function invalid(string $field): InvoiceDomainException
    {
        return new InvoiceDomainException('sales_register_filters_invalid', 'Nieprawidłowy zakres rejestru sprzedaży.', ['field' => $field]);
    }
}
