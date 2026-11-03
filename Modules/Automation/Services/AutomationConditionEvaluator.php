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
        if (($condition['field'] ?? '') === 'integration_source') {
            return ($condition['operator'] ?? '') === 'in' && is_array($condition['value'] ?? null)
                && app(AutomationOrderSources::class)->matches($order, $eventPayload, $condition['value']);
        }
        $actual = $this->value($order, $eventPayload, (string) ($condition['field'] ?? ''));
        $expected = $condition['value'] ?? null;

        return match ($condition['operator'] ?? 'equals') {
            'not_equals' => ! $this->equals($actual, $expected),
            'contains' => str_contains(mb_strtolower((string) $actual), mb_strtolower((string) $expected)),
            'greater_or_equal' => is_numeric($actual) && is_numeric($expected) && (float) $actual >= (float) $expected,
            'less_or_equal' => is_numeric($actual) && is_numeric($expected) && (float) $actual <= (float) $expected,
            default => is_scalar($expected) && $this->equals($actual, $expected),
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
            'billing_tax_id' => trim((string) $order->billing_tax_id) === '' ? 'empty' : 'not_empty',
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
