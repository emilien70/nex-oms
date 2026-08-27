<?php

namespace Modules\Automation\Services;

use App\Models\Order;

class AutomationConditionEvaluator
{
    public function matches(Order $order, array $eventPayload, array $conditions): bool
    {
        foreach ($conditions as $condition) {
            if (! $this->matchesCondition($order, $eventPayload, $condition)) {
                return false;
            }
        }

        return true;
    }

    private function matchesCondition(Order $order, array $eventPayload, array $condition): bool
    {
        $actual = $this->value($order, $eventPayload, (string) ($condition['field'] ?? ''));
        $expected = $condition['value'] ?? null;

        return match ($condition['operator'] ?? 'equals') {
            'not_equals' => ! $this->equals($actual, $expected),
            'contains' => str_contains(mb_strtolower((string) $actual), mb_strtolower((string) $expected)),
            'greater_or_equal' => is_numeric($actual) && is_numeric($expected) && (float) $actual >= (float) $expected,
            'less_or_equal' => is_numeric($actual) && is_numeric($expected) && (float) $actual <= (float) $expected,
            default => $this->equals($actual, $expected),
        };
    }

    private function value(Order $order, array $eventPayload, string $field): mixed
    {
        return match ($field) {
            'order_status' => $order->status,
            'source' => $order->source,
            'cash_on_delivery' => $order->cash_on_delivery ? '1' : '0',
            'payment_state' => $this->paymentState($order),
            'shipping_method' => $order->shipping_method,
            'carrier' => $eventPayload['provider'] ?? $order->shipments()->latest('id')->value('provider'),
            'shipment_status' => $eventPayload['new_status'] ?? $eventPayload['status'] ?? null,
            'total_gross' => $order->total_gross,
            default => null,
        };
    }

    private function paymentState(Order $order): string
    {
        $total = (float) $order->total_gross;
        $paid = (float) $order->paid_amount;

        if ($total > 0 && $paid >= $total) {
            return 'paid';
        }

        return $paid > 0 ? 'partial' : 'unpaid';
    }

    private function equals(mixed $actual, mixed $expected): bool
    {
        if (is_numeric($actual) && is_numeric($expected)) {
            return (float) $actual === (float) $expected;
        }

        return mb_strtolower(trim((string) $actual)) === mb_strtolower(trim((string) $expected));
    }
}
