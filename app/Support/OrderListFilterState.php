<?php

namespace App\Support;

use Illuminate\Http\Request;

final class OrderListFilterState
{
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
    ];

    public static function filtersFromRequest(Request $request): array
    {
        return self::normalize($request->only(self::FILTER_KEYS), self::FILTER_KEYS, false);
    }

    public static function savedFromRequest(Request $request): array
    {
        return self::normalize($request->only(self::SAVED_KEYS), self::SAVED_KEYS);
    }

    public static function normalizeSaved(array $values): array
    {
        return self::normalize($values, self::SAVED_KEYS);
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
