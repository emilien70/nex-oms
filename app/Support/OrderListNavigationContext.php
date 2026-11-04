<?php

namespace App\Support;

use Illuminate\Http\Request;

final class OrderListNavigationContext
{
    public static function fromList(Request $request, array $statuses): array
    {
        return self::normalize($request->query(), $statuses);
    }

    public static function fromOrder(Request $request, array $statuses): ?array
    {
        $context = $request->query('list');

        return is_array($context) ? self::normalize($context, $statuses) : null;
    }

    public static function orderParameters(int $orderId, array $context): array
    {
        return ['order' => $orderId, 'status' => $context['status'], 'list' => $context];
    }

    public static function listParameters(array $context): array
    {
        if ($context['status'] === 'all') {
            unset($context['status']);
        }

        // Returning to a tracking-number search must not redirect to the order again.
        return $context + ['list_return' => 1];
    }

    private static function normalize(array $values, array $statuses): array
    {
        $context = OrderListFilterState::normalizeSaved($values);
        $status = $context['status'] ?? 'all';
        $context['status'] = array_key_exists($status, $statuses) ? $status : 'all';
        if (filter_var($values['trash'] ?? null, FILTER_VALIDATE_BOOLEAN)) {
            $context['trash'] = 1;
        }

        if (($context['q'] ?? '') !== '') {
            $context['status'] = 'all';
            unset($context['trash']);
        }

        $context['page'] = self::positiveInteger($values['page'] ?? null, 1);
        $perPage = self::positiveInteger($values['per_page'] ?? null, 20);
        $context['per_page'] = in_array($perPage, OrderListFilterState::PER_PAGE_OPTIONS, true) ? $perPage : 20;

        return $context;
    }

    private static function positiveInteger(mixed $value, int $default): int
    {
        return filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 1000000]]) ?: $default;
    }
}
