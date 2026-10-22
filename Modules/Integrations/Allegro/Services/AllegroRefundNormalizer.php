<?php

namespace Modules\Integrations\Allegro\Services;

use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Modules\Integrations\Allegro\Exceptions\AllegroApiException;

class AllegroRefundNormalizer
{
    public function normalize(array $raw): array
    {
        $currency = data_get($raw, 'totalValue.currency');
        if (! is_string($currency) || ! preg_match('/^[A-Z]{3}$/D', $currency)) {
            $this->invalid();
        }
        $total = $this->money($raw['totalValue'] ?? null, $currency);
        $delivery = array_key_exists('delivery', $raw) && $raw['delivery'] !== null ? $this->money(data_get($raw, 'delivery.value'), $currency) : '0.00';
        $other = BigDecimal::zero()->toScale(2);
        $components = [];
        $items = [];
        $ids = [];
        foreach (['overpaid', 'additionalServices'] as $key) {
            $value = isset($raw[$key]) ? $this->money(data_get($raw, $key.'.value'), $currency) : '0.00';
            $components[$key] = $value;
            $other = $other->plus($value);
        }
        foreach (['surcharges' => ['id', 'value'], 'deposits' => ['lineItemId', 'totalValue']] as $key => [$idKey, $valueKey]) {
            $components[$key] = [];
            $componentIds = [];
            foreach ($this->rows($raw[$key] ?? []) as $row) {
                $id = $this->uuid($row[$idKey] ?? null);
                if (isset($componentIds[$id])) {
                    $this->invalid();
                } $componentIds[$id] = true;
                $value = $this->money($row[$valueKey] ?? null, $currency);
                $components[$key][] = ['id' => $id, 'value' => $value];
                if ($key === 'deposits') {
                    $other = $other->plus($value);
                }
            }
        }
        $explicit = BigDecimal::zero()->toScale(2);
        $allExplicit = true;
        foreach ($this->rows($raw['lineItems'] ?? []) as $row) {
            $id = $this->uuid($row['id'] ?? null);
            if (isset($ids[$id]) || ! in_array($row['type'] ?? null, ['AMOUNT', 'QUANTITY'], true)) {
                $this->invalid();
            }
            $ids[$id] = true;
            if ($row['type'] === 'AMOUNT') {
                $value = $this->money($row['value'] ?? null, $currency);
                $explicit = $explicit->plus($value);
                $items[] = ['id' => $id, 'type' => 'AMOUNT', 'value' => $value];
            } else {
                $quantity = $row['quantity'] ?? null;
                if ((! is_int($quantity) && ! is_float($quantity)) || $quantity <= 0 || $quantity > 1000000 || isset($row['value'])) {
                    $this->invalid();
                }
                $items[] = ['id' => $id, 'type' => 'QUANTITY', 'quantity' => (string) $quantity];
                $allExplicit = false;
            }
        }
        $remainder = BigDecimal::of($total)->minus($delivery)->minus($other);
        if ($remainder->isNegative() || $explicit->isGreaterThan($remainder)) {
            $this->invalid();
        }
        $knownKeys = ['id', 'payment', 'order', 'reason', 'status', 'createdAt', 'totalValue', 'lineItems', 'delivery', 'overpaid', 'surcharges', 'additionalServices', 'deposits', 'sellerComment', 'commandId'];
        // A refund involving surcharge payments does not prove an independent product subtotal.
        $complete = array_diff(array_keys($raw), $knownKeys) === [] && $components['surcharges'] === [] && ($items !== [] || $remainder->isZero());
        if ($items !== [] && $allExplicit && ! $explicit->isEqualTo($remainder)) {
            $complete = false;
        }
        $created = $raw['createdAt'] ?? null;
        if (! is_string($created) || ! preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$/D', $created)) {
            $this->invalid();
        }
        try {
            $date = CarbonImmutable::parse($created);
        } catch (\Throwable) {
            $this->invalid();
        }
        if ($date->format('Y-m-d\TH:i:s') !== substr($created, 0, 19)) {
            $this->invalid();
        }
        $components['items'] = $items;
        $data = [
            'external_refund_id' => $this->uuid($raw['id'] ?? null), 'external_payment_id' => $this->uuid(data_get($raw, 'payment.id')),
            'external_order_id' => data_get($raw, 'order.id') !== null ? $this->uuid(data_get($raw, 'order.id')) : null,
            'status' => $this->text($raw['status'] ?? null, 100), 'reason' => $this->text($raw['reason'] ?? null, 100),
            'currency' => $currency, 'total_value' => $total, 'delivery_value' => $delivery, 'other_value' => (string) $other,
            'products_value' => $complete ? (string) $remainder : null, 'components' => $components,
            'seller_comment' => isset($raw['sellerComment']) ? $this->text($raw['sellerComment'], 250, false) : null,
            'requested_at' => $date->utc(),
        ];
        $data['source_hash'] = hash('sha256', json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));

        return $data;
    }

    private function money(mixed $value, string $currency): string
    {
        if (! is_array($value) || ($value['currency'] ?? null) !== $currency || ! is_string($value['amount'] ?? null)
            || ! preg_match('/^\d{1,12}(?:\.\d{1,2})?$/D', $value['amount'])) {
            $this->invalid();
        }

        return (string) BigDecimal::of($value['amount'])->toScale(2);
    }

    private function rows(mixed $rows): array
    {
        if (! is_array($rows) || ! array_is_list($rows) || count($rows) > 1000) {
            $this->invalid();
        }
        foreach ($rows as $row) {
            if (! is_array($row)) {
                $this->invalid();
            }
        }

        return $rows;
    }

    private function uuid(mixed $id): string
    {
        if (! is_string($id) || ! Str::isUuid($id)) {
            $this->invalid();
        }

        return strtolower($id);
    }

    private function text(mixed $value, int $max, bool $required = true): ?string
    {
        if (! is_string($value) || mb_strlen($value) > $max || ($required && trim($value) === '')) {
            $this->invalid();
        }

        return trim($value) ?: null;
    }

    private function invalid(): never
    {
        throw new AllegroApiException('Allegro zwróciło niespójne dane refundacji. Zachowano poprzednie dane.', 422);
    }
}
