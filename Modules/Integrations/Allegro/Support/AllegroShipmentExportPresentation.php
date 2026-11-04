<?php

namespace Modules\Integrations\Allegro\Support;

use App\Models\OrderStatusSetting;
use Illuminate\Support\Collection;
use Modules\Shipments\Models\Shipment;

class AllegroShipmentExportPresentation
{
    public static function forShipment(Shipment $shipment): ?array
    {
        if ($shipment->order?->source !== 'allegro' || blank($shipment->tracking_number)) {
            return null;
        }

        $numbers = AllegroShipmentNumbers::current($shipment);
        $exports = $shipment->allegroExports->filter(fn ($export) => isset($numbers[$export->carrier_id.'|'.$export->waybill]));
        $groups = $shipment->parcels->map(fn ($parcel) => AllegroShipmentNumbers::fromValues($shipment, $parcel->getAttributes()))
            ->filter()->values()->all();
        foreach ($numbers as $key => $pair) {
            $grouped = false;
            foreach ($groups as &$group) {
                if (in_array($pair['waybill'], array_column($group, 'waybill'), true)) {
                    $group[$key] = $pair;
                    $grouped = true;
                    break;
                }
            }
            unset($group);
            if (! $grouped) {
                $groups[] = [$key => $pair];
            }
        }

        // One confirmed alias acknowledges its package, not other packages in this shipment.
        $unconfirmed = collect($groups)->map(fn ($group) => $exports->filter(fn ($export) => isset($group[$export->carrier_id.'|'.$export->waybill])))
            ->reject(fn (Collection $group) => $group->contains('state', 'synced'));
        if ($groups !== [] && $unconfirmed->isEmpty()) {
            return self::result('success', 'Potwierdzono w Allegro');
        }

        $remaining = $unconfirmed->flatten(1);
        $failed = $remaining->where('state', 'failed')->unique('id')->values();
        if ($failed->isNotEmpty()) {
            $messages = $failed->map(fn ($export) => ($export->verification_only ? 'Błąd potwierdzenia w Allegro: ' : 'Błąd przekazania numeru do Allegro: ')
                .($export->error ?: 'Nie potwierdzono wyniku operacji.'))->unique()->implode("\n");

            return self::result('danger', $messages, $failed);
        }
        if ($remaining->contains('state', 'pending')) {
            return self::result('secondary', 'Oczekuje na potwierdzenie w Allegro');
        }
        $account = $shipment->order?->allegroOrderLink?->account;
        if ($remaining->contains('state', 'waiting')) {
            $status = OrderStatusSetting::query()->find($account?->shipment_sync_status_setting_id);

            return self::result('secondary', 'Czeka na status OMS: '.($status?->short_name ?: $status?->status ?: 'wybrany w konfiguracji'));
        }
        if ($remaining->contains('state', 'skipped')) {
            return self::result('secondary', 'Potwierdzanie przesyłki w Allegro zostało pominięte. Sprawdź ustawienia integracji.');
        }
        if ($account?->shipment_sync_mode?->value === 'disabled') {
            return self::result('secondary', 'Przekazywanie numerów do Allegro jest wyłączone.');
        }

        return self::result('secondary', 'Nie sprawdzono przypisania wszystkich paczek w Allegro.');
    }

    private static function result(string $tone, string $message, ?Collection $retryExports = null): array
    {
        return ['tone' => $tone, 'message' => $message, 'retry_exports' => $retryExports ?? collect()];
    }
}
