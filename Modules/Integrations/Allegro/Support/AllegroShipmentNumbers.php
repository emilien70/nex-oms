<?php

namespace Modules\Integrations\Allegro\Support;

use Modules\Shipments\Models\CourierAccount;
use Modules\Shipments\Models\Shipment;

class AllegroShipmentNumbers
{
    public static function key(array $pair): string
    {
        return $pair['carrier_id'].'|'.$pair['waybill'];
    }

    public static function normalize(array $pairs): array
    {
        $result = [];
        foreach ($pairs as $pair) {
            if (! is_array($pair) || ! is_string($pair['carrier_id'] ?? null) || ! is_string($pair['waybill'] ?? null)) {
                continue;
            }
            $pair = ['carrier_id' => strtoupper(trim($pair['carrier_id'])), 'waybill' => trim($pair['waybill'])];
            if ($pair['carrier_id'] !== '' && strlen($pair['carrier_id']) <= 50 && $pair['waybill'] !== '' && strlen($pair['waybill']) <= 255) {
                $result[self::key($pair)] = $pair;
            }
        }

        return $result;
    }

    public static function fromValues(Shipment $shipment, array $values): array
    {
        $aliases = $values['tracking_numbers'] ?? [];
        if (is_string($aliases)) {
            $aliases = json_decode($aliases, true);
        }
        if ($shipment->provider === CourierAccount::PROVIDER_ALLEGRO_SHIPPING && is_array($aliases) && $aliases !== []) {
            return self::normalize($aliases) + self::normalize([[
                'carrier_id' => $values['carrier_code'] ?? $shipment->carrier_code,
                'waybill' => $values['tracking_number'] ?? null,
            ]]);
        }
        $carrier = match ($shipment->provider) {
            CourierAccount::PROVIDER_INPOST_LOCKERS, CourierAccount::PROVIDER_INPOST_COURIER => 'INPOST',
            CourierAccount::PROVIDER_DPD => 'DPD',
            CourierAccount::PROVIDER_ALLEGRO_SHIPPING => $values['carrier_code'] ?? $shipment->carrier_code,
            default => null,
        };

        return self::normalize([['carrier_id' => $carrier, 'waybill' => $values['tracking_number'] ?? null]]);
    }

    public static function current(Shipment $shipment): array
    {
        $result = [];
        if (! $shipment->relationLoaded('parcels')) {
            $shipment->loadMissing('parcels');
        }
        foreach ($shipment->parcels as $parcel) {
            $result += self::fromValues($shipment, $parcel->getAttributes());
        }

        return $result + self::fromValues($shipment, $shipment->getAttributes());
    }

    public static function fromAllegroPackage(array $package, ?string $legacyCarrier): array
    {
        $pairs = [['carrier_id' => $legacyCarrier, 'waybill' => $package['waybill'] ?? null]];
        foreach ((array) ($package['transportingInfo'] ?? []) as $info) {
            if (is_array($info)) {
                $pairs[] = ['carrier_id' => $info['carrierId'] ?? null, 'waybill' => $info['carrierWaybill'] ?? null];
            }
        }

        return self::normalize($pairs);
    }
}
