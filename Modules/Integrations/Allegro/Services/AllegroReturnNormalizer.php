<?php

namespace Modules\Integrations\Allegro\Services;

use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Modules\Integrations\Allegro\Exceptions\AllegroApiException;

class AllegroReturnNormalizer
{
    public function normalize(array $raw): array
    {
        $items = $raw['items'] ?? null;
        $parcels = array_key_exists('parcels', $raw) ? $raw['parcels'] : [];
        if (! is_string($raw['id'] ?? null) || ! is_string($raw['orderId'] ?? null)
            || ! Str::isUuid($raw['id']) || ! Str::isUuid($raw['orderId'])
            || ! is_array($items) || ! array_is_list($items) || count($items) < 1 || count($items) > 1000
            || ! is_array($parcels) || ! array_is_list($parcels) || count($parcels) > 100) {
            $this->invalid();
        }
        $total = BigDecimal::zero()->toScale(2);
        $currency = null;
        $normalizedItems = [];
        foreach ($items as $position => $item) {
            if (! is_array($item)) {
                $this->invalid();
            }
            $amount = data_get($item, 'price.amount');
            $code = data_get($item, 'price.currency');
            $quantity = $item['quantity'] ?? null;
            if (! is_string($amount) || ! preg_match('/^\d{1,12}(?:\.\d{1,2})?$/D', $amount)
                || ! is_string($code) || ! preg_match('/^[A-Z]{3}$/D', $code)
                || ! is_int($quantity) || $quantity < 1 || $quantity > 1000000 || ($currency && $currency !== $code)) {
                $this->invalid();
            }
            $currency = $code;
            $value = BigDecimal::of($amount)->toScale(2)->multipliedBy($quantity);
            $total = $total->plus($value);
            if ($total->isGreaterThan('9999999999999999.99')) {
                $this->invalid();
            }
            $normalizedItems[] = [
                'position' => $position, 'offer_id' => $this->text($item['offerId'] ?? null, 100, true),
                'name' => $this->text($item['name'] ?? null, 10000, true), 'quantity' => $quantity,
                'unit_price' => (string) BigDecimal::of($amount)->toScale(2), 'total_value' => (string) $value,
                'currency' => $code, 'reason' => $this->text(data_get($item, 'reason.type', 'NONE'), 100, true),
                'comment' => $this->text(data_get($item, 'reason.userComment'), 20000),
            ];
        }
        $data = [
            'external_return_id' => strtolower($raw['id']), 'external_order_id' => strtolower($raw['orderId']),
            'reference_number' => $this->text($raw['referenceNumber'] ?? null, 255, true),
            'buyer_login' => $this->text(data_get($raw, 'buyer.login'), 255),
            'buyer_email' => $this->text(data_get($raw, 'buyer.email'), 255),
            'marketplace_id' => $this->text($raw['marketplaceId'] ?? null, 50),
            'status' => $this->text($raw['status'] ?? null, 100, true),
            'submitted_at' => $this->date($raw['createdAt'] ?? null), 'total_value' => (string) $total,
            'currency' => $currency, 'items' => $normalizedItems,
            'parcels' => array_map(function ($parcel, $position): array {
                if (! is_array($parcel)) {
                    $this->invalid();
                }

                return [
                    'position' => $position, 'waybill' => $this->text($parcel['waybill'] ?? null, 255),
                    'carrier_id' => $this->text($parcel['carrierId'] ?? null, 255),
                    'transporting_waybill' => $this->text($parcel['transportingWaybill'] ?? null, 255),
                    'transporting_carrier_id' => $this->text($parcel['transportingCarrierId'] ?? null, 255),
                    'submitted_at' => isset($parcel['createdAt']) ? $this->date($parcel['createdAt']) : null,
                ];
            }, $parcels, array_keys($parcels)),
        ];
        // Only the normalized fields are retained; bank details and arbitrary source HTML are not logged or copied.
        $data['source_hash'] = hash('sha256', json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));

        return $data;
    }

    private function text(mixed $value, int $length, bool $required = false): ?string
    {
        if ($value === null && ! $required) {
            return null;
        }
        if (! is_string($value) || mb_strlen($value) > $length || ($required && trim($value) === '')) {
            $this->invalid();
        }

        return trim($value) === '' ? null : trim($value);
    }

    private function date(mixed $value): CarbonImmutable
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$/D', $value)) {
            $this->invalid();
        }
        try {
            $date = CarbonImmutable::parse($value);
            if ($date->format('Y-m-d\TH:i:s') !== substr($value, 0, 19)) {
                $this->invalid();
            }

            return $date->utc();
        } catch (\Throwable) {
            $this->invalid();
        }
    }

    private function invalid(): never
    {
        throw new AllegroApiException('Allegro zwróciło niekompletne lub niespójne dane zwrotu. Nie nadpisano zapisanych danych.', 422);
    }
}
