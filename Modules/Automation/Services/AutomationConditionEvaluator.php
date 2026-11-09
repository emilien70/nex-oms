<?php

namespace Modules\Automation\Services;

use App\Models\Order;
use App\Support\PolishNip;

class AutomationConditionEvaluator
{
    public function matches(Order $order, array $eventPayload, array $conditions, ?array $gusCheck = null): bool
    {
        foreach ($conditions as $condition) {
            if (! $this->matchesCondition($order, $eventPayload, $condition, $gusCheck)) {
                return false;
            }
        }

        return true;
    }

    private function matchesCondition(Order $order, array $eventPayload, array $condition, ?array $gusCheck): bool
    {
        $field = $condition['field'] ?? '';
        if ($field === 'integration_source') {
            return ($condition['operator'] ?? '') === 'in' && is_array($condition['value'] ?? null)
                && app(AutomationOrderSources::class)->matches($order, $eventPayload, $condition['value']);
        }
        $expected = $condition['value'] ?? null;
        $operator = $condition['operator'] ?? 'equals';

        if (AutomationGusNipCheck::isCondition($condition)) {
            return AutomationGusNipCheck::matches($order, $expected, $gusCheck);
        }

        if (in_array($field, ['billing_company_name', 'shipping_company_name', 'billing_tax_id'], true)) {
            $actual = trim((string) $order->{$field});
            $isNip = $field === 'billing_tax_id';
            if ($isNip && $operator === 'equals' && in_array($expected, ['empty', 'not_empty'], true)) {
                return ($expected === 'empty') === ($actual === '');
            }

            return $this->matchesText($actual, $operator, $expected, $isNip);
        }

        $actual = $this->value($order, $eventPayload, (string) $field);

        return match ($operator) {
            'not_equals' => ! $this->equals($actual, $expected),
            'contains' => str_contains(mb_strtolower((string) $actual), mb_strtolower((string) $expected)),
            'greater_or_equal' => is_numeric($actual) && is_numeric($expected) && (float) $actual >= (float) $expected,
            'less_or_equal' => is_numeric($actual) && is_numeric($expected) && (float) $actual <= (float) $expected,
            default => is_scalar($expected) && $this->equals($actual, $expected),
        };
    }

    private function matchesText(string $actual, string $operator, mixed $expected, bool $isNip): bool
    {
        if ($operator === 'is_empty' || $operator === 'is_not_empty') {
            return ($operator === 'is_empty') === ($actual === '');
        }
        if ($actual === '' || ! is_string($expected)) {
            return false;
        }
        if ($isNip) {
            $actual = PolishNip::withoutSeparators($actual);
            $expected = PolishNip::withoutSeparators($expected);
        }
        $actual = mb_strtolower($actual);
        $expected = mb_strtolower(trim($expected));
        if ($actual === '' || $expected === '') {
            return false;
        }

        return match ($operator) {
            'contains' => str_contains($actual, $expected),
            'not_contains' => ! str_contains($actual, $expected),
            'equals' => $actual === $expected,
            'not_equals' => $actual !== $expected,
            'starts_with' => str_starts_with($actual, $expected),
            'ends_with' => str_ends_with($actual, $expected),
            default => false,
        };
    }

    private function value(Order $order, array $eventPayload, string $field): mixed
    {
        if (in_array($field, ['return_status', 'return_parcel_status'], true)) {
            $parcel = app(AutomationReturnContext::class)->parcel($order, $eventPayload);

            return $field === 'return_status' ? $parcel?->orderReturn?->local_status_id : $parcel?->statusCode();
        }

        return match ($field) {
            'order_status' => $order->status,
            'source' => $order->source,
            'cash_on_delivery' => $order->cash_on_delivery ? '1' : '0',
            'payment_state' => $eventPayload['payment_state'] ?? $this->paymentState($order),
            'payment_source' => $eventPayload['payment_source'] ?? null,
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
