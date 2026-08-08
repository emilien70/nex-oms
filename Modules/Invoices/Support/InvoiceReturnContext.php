<?php

namespace Modules\Invoices\Support;

use App\Models\Order;
use DateTimeImmutable;
use Illuminate\Http\Request;

final class InvoiceReturnContext
{
    public const INVOICES = 'invoices';

    public const PROFORMAS = 'proformas';

    public const CORRECTIONS = 'corrections';

    public const ORDER = 'order';

    private const LIST_ROUTES = [
        self::INVOICES => 'invoices.index',
        self::PROFORMAS => 'invoices.proformas.index',
        self::CORRECTIONS => 'invoices.corrections.index',
    ];

    private const ALLOWED_SORTS = ['number', 'order', 'issue_date', 'buyer', 'gross'];

    private const ALLOWED_DIRECTIONS = ['asc', 'desc'];

    private const ALLOWED_PER_PAGE = [25, 50, 75, 100, 150, 200, 300, 500, 1000];

    private const TEXT_LIMITS = [
        'full_number' => 120,
        'buyer' => 160,
        'company' => 160,
        'tax_id' => 30,
        'source' => 50,
    ];

    /** @param array<string, int|string> $query */
    private function __construct(
        private readonly string $returnTo,
        private readonly array $query,
        private readonly bool $explicit,
    ) {}

    public static function fromRequest(Request $request, string $default = self::ORDER): self
    {
        $returnTo = $request->input('return_to');
        $explicit = is_string($returnTo)
            && in_array($returnTo, [...array_keys(self::LIST_ROUTES), self::ORDER], true);

        if (! $explicit) {
            $returnTo = $default;
        }

        $returnQuery = $request->input('return_query');
        $query = is_string($returnQuery) && strlen($returnQuery) <= 4096
            ? self::parseQuery($returnQuery)
            : [];

        return new self($returnTo, $returnTo === self::ORDER ? [] : $query, $explicit);
    }

    public static function forList(Request $request, string $returnTo): self
    {
        if (! array_key_exists($returnTo, self::LIST_ROUTES)) {
            $returnTo = self::INVOICES;
        }

        return new self($returnTo, self::sanitize($request->query()), true);
    }

    public function returnTo(): string
    {
        return $this->returnTo;
    }

    public function query(): string
    {
        return http_build_query($this->query, '', '&', PHP_QUERY_RFC3986);
    }

    /** @return array{return_to: string, return_query?: string} */
    public function parameters(): array
    {
        if (! $this->explicit) {
            return [];
        }

        $parameters = ['return_to' => $this->returnTo];

        if ($this->query !== []) {
            $parameters['return_query'] = $this->query();
        }

        return $parameters;
    }

    public function url(Order|int $order): string
    {
        if ($this->returnTo === self::ORDER) {
            return route('orders.show', $order);
        }

        return route(self::LIST_ROUTES[$this->returnTo], $this->query);
    }

    public function isList(): bool
    {
        return array_key_exists($this->returnTo, self::LIST_ROUTES);
    }

    /** @return array<string, int|string> */
    private static function parseQuery(string $query): array
    {
        parse_str($query, $parameters);

        return is_array($parameters) ? self::sanitize($parameters) : [];
    }

    /**
     * @param  array<string, mixed>  $parameters
     * @return array<string, int|string>
     */
    private static function sanitize(array $parameters): array
    {
        $clean = [];

        foreach (['series_id', 'number', 'order_id', 'page'] as $key) {
            $value = self::positiveInteger($parameters[$key] ?? null);
            if ($value !== null) {
                $clean[$key] = $value;
            }
        }

        $month = self::integerBetween($parameters['month'] ?? null, 1, 12);
        if ($month !== null) {
            $clean['month'] = $month;
        }

        $year = self::integerBetween($parameters['year'] ?? null, 2000, 2100);
        if ($year !== null) {
            $clean['year'] = $year;
        }

        foreach (self::TEXT_LIMITS as $key => $limit) {
            $value = self::limitedText($parameters[$key] ?? null, $limit);
            if ($value !== null) {
                $clean[$key] = $value;
            }
        }

        foreach (['total_from', 'total_to'] as $key) {
            $value = self::decimal($parameters[$key] ?? null);
            if ($value !== null) {
                $clean[$key] = $value;
            }
        }

        foreach (['issue_from', 'issue_to', 'sale_from', 'sale_to'] as $key) {
            $value = self::date($parameters[$key] ?? null);
            if ($value !== null) {
                $clean[$key] = $value;
            }
        }

        self::removeInvalidDateRange($clean, 'issue_from', 'issue_to');
        self::removeInvalidDateRange($clean, 'sale_from', 'sale_to');

        $currency = self::limitedText($parameters['currency'] ?? null, 3);
        if ($currency !== null) {
            $currency = strtoupper($currency);
            if (preg_match('/^[A-Z]{3}$/', $currency) === 1) {
                $clean['currency'] = $currency;
            }
        }

        $sort = self::scalarString($parameters['sort'] ?? null);
        if (in_array($sort, self::ALLOWED_SORTS, true)) {
            $clean['sort'] = $sort;
        }

        $direction = self::scalarString($parameters['direction'] ?? null);
        if (in_array($direction, self::ALLOWED_DIRECTIONS, true)) {
            $clean['direction'] = $direction;
        }

        $perPage = self::positiveInteger($parameters['per_page'] ?? null);
        if ($perPage !== null && in_array($perPage, self::ALLOWED_PER_PAGE, true)) {
            $clean['per_page'] = $perPage;
        }

        return $clean;
    }

    private static function scalarString(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private static function limitedText(mixed $value, int $limit): ?string
    {
        $value = self::scalarString($value);

        return $value !== null && mb_strlen($value) <= $limit ? $value : null;
    }

    private static function positiveInteger(mixed $value): ?int
    {
        $value = self::scalarString($value);
        if ($value === null || preg_match('/^[1-9]\d*$/', $value) !== 1) {
            return null;
        }

        $integer = filter_var($value, FILTER_VALIDATE_INT);

        return is_int($integer) && $integer > 0 ? $integer : null;
    }

    private static function integerBetween(mixed $value, int $minimum, int $maximum): ?int
    {
        $integer = self::positiveInteger($value);

        return $integer !== null && $integer >= $minimum && $integer <= $maximum ? $integer : null;
    }

    private static function decimal(mixed $value): ?string
    {
        $value = self::scalarString($value);

        return $value !== null && preg_match('/^-?\d+(?:\.\d{1,2})?$/', $value) === 1 ? $value : null;
    }

    private static function date(mixed $value): ?string
    {
        $value = self::scalarString($value);
        if ($value === null) {
            return null;
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $date !== false && $date->format('Y-m-d') === $value ? $value : null;
    }

    /** @param array<string, int|string> $parameters */
    private static function removeInvalidDateRange(array &$parameters, string $from, string $to): void
    {
        if (isset($parameters[$from], $parameters[$to]) && $parameters[$to] < $parameters[$from]) {
            unset($parameters[$to]);
        }
    }
}
