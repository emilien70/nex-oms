<?php

namespace App\Support;

use Illuminate\Http\Request;

final class OrderListFilterState
{
    public const PER_PAGE_OPTIONS = [20, 30, 50, 75, 100, 150, 200, 300, 500, 1000];

    public const FILTER_KEYS = [
        'number',
        'store_number',
        'ordered_from',
        'ordered_to',
        'status_from',
        'status_to',
        'source',
        'customer',
        'login',
        'email',
        'phone',
        'city',
        'postal_code',
        'shipping_method',
        'tracking_number',
        'cash_on_delivery',
        'payment',
        'payment_method',
        'total_from',
        'total_to',
        'delivery_cost_from',
        'delivery_cost_to',
        'product',
        'notes',
        'completeness',
    ];

    public const SAVED_KEYS = [
        'q',
        'status',
        ...self::FILTER_KEYS,
        'sort',
        'direction',
    ];

    private const SUMMARY_FIELDS = [
        'number' => ['Numer zamówienia OMS', 'równa się'],
        'store_number' => ['Numer w sklepie', 'zawiera'],
        'ordered_from' => ['Data złożenia', 'od', 'date'],
        'ordered_to' => ['Data złożenia', 'do', 'date'],
        'status_from' => ['Data w statusie', 'od', 'date'],
        'status_to' => ['Data w statusie', 'do', 'date'],
        'source' => ['Źródło zamówienia', 'to', ['allegro' => 'Allegro', 'prestashop' => 'PrestaShop', 'von_halsky' => 'InPost Von Halsky']],
        'completeness' => ['Kompletność', 'to', ['incomplete' => 'Niepełne', 'complete' => 'Pełne', 'review' => 'Wymagające uwagi']],
        'customer' => ['Imię, nazwisko lub firma', 'zawiera'],
        'login' => ['Login kupującego', 'zawiera'],
        'email' => ['E-mail', 'zawiera'],
        'phone' => ['Telefon', 'zawiera'],
        'city' => ['Miasto', 'zawiera'],
        'postal_code' => ['Kod pocztowy', 'zawiera'],
        'shipping_method' => ['Sposób wysyłki', 'zawiera'],
        'tracking_number' => ['Numer nadania', 'zawiera'],
        'cash_on_delivery' => ['Pobranie', 'to', ['0' => 'Nie', '1' => 'Tak']],
        'payment' => ['Płatność', 'to', ['unpaid' => 'Nieopłacone', 'partial' => 'Częściowo opłacone', 'paid' => 'Opłacone']],
        'payment_method' => ['Sposób płatności', 'zawiera'],
        'total_from' => ['Łączna kwota', 'od', 'amount'],
        'total_to' => ['Łączna kwota', 'do', 'amount'],
        'delivery_cost_from' => ['Koszt wysyłki', 'od', 'amount'],
        'delivery_cost_to' => ['Koszt wysyłki', 'do', 'amount'],
        'product' => ['Nazwa produktu', 'zawiera'],
        'notes' => ['Uwagi sprzedawcy', 'zawiera'],
    ];

    public static function filtersFromRequest(Request $request): array
    {
        return self::normalize($request->only(self::FILTER_KEYS), self::FILTER_KEYS, false);
    }

    public static function preferenceScope(?array $status, bool $trash = false): string
    {
        if ($trash) {
            return 'trash';
        }

        return $status === null ? 'all' : 'status:'.($status['id'] ?? $status['code']);
    }

    /** @return array<string, array{label: string, operator: string, value: string}> */
    public static function summary(array $filters): array
    {
        $summary = [];
        foreach (self::SUMMARY_FIELDS as $key => $field) {
            $value = $filters[$key] ?? '';
            if ($value === '') {
                continue;
            }

            [$label, $operator] = $field;
            $format = $field[2] ?? null;
            if (is_array($format)) {
                if (! array_key_exists($value, $format)) {
                    continue;
                }
                $value = $format[$value];
            } elseif ($format === 'date' && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $value, $date)) {
                $value = "$date[3].$date[2].$date[1]";
            } elseif ($format === 'amount' && preg_match('/^(-?\d+)(?:\.(\d+))?$/D', $value, $amount)) {
                $value = $amount[1].','.str_pad($amount[2] ?? '', 2, '0');
            }

            $summary[$key] = compact('label', 'operator', 'value');
        }

        return $summary;
    }

    public static function savedFromRequest(Request $request): array
    {
        return self::normalizeSaved($request->only(self::SAVED_KEYS));
    }

    public static function normalizeSaved(array $values): array
    {
        $normalized = self::normalize($values, self::SAVED_KEYS);
        unset($normalized['sort'], $normalized['direction']);

        return $normalized + OrderListSort::fromArray($values)->parameters();
    }

    public static function savedViewParameters(array $filters, ?string $status, bool $trash, string $search): array
    {
        $parameters = self::normalizeSaved($filters);
        unset($parameters['status'], $parameters['q']);

        return $parameters + match (true) {
            $search !== '' => ['q' => $search],
            $trash => ['trash' => 1],
            $status !== null => ['status' => $status],
            default => [],
        };
    }

    private static function normalize(array $values, array $keys, bool $removeEmpty = true): array
    {
        $normalized = [];

        foreach ($keys as $key) {
            if (! array_key_exists($key, $values) || ! is_scalar($values[$key])) {
                continue;
            }

            $value = is_string($values[$key]) ? trim($values[$key]) : (string) $values[$key];

            if ($removeEmpty && $value === '') {
                continue;
            }

            $normalized[$key] = $value;
        }

        return $normalized;
    }
}
