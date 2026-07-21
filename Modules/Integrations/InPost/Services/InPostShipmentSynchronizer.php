<?php

namespace Modules\Integrations\InPost\Services;

use Illuminate\Support\Carbon;
use Modules\Shipments\Events\ShipmentCreated;
use Modules\Shipments\Events\ShipmentStatusChanged;
use Modules\Shipments\Models\CourierAccount;
use Modules\Shipments\Models\Shipment;
use Modules\Shipments\Services\ShipmentStatusMapper;

class InPostShipmentSynchronizer
{
    public function __construct(
        private readonly ShipmentStatusMapper $statusMapper,
    ) {}

    public function apply(Shipment $shipment, array $data, string $eventType): void
    {
        $previousExternalId = $shipment->external_id;
        $previousStatus = $shipment->status;
        $previousOmsStatus = $shipment->oms_status
            ?: $this->statusMapper->map($shipment->provider, $previousStatus);
        $previousTrackingNumber = $shipment->tracking_number;
        $status = (string) ($data['status'] ?? $shipment->status);
        $omsStatus = $this->statusMapper->map($shipment->provider, $status);
        $trackingNumber = $data['tracking_number'] ?? data_get($data, 'parcels.0.tracking_number');
        $statusOccurredAt = $this->statusOccurredAt($data);

        $shipment->update([
            'external_id' => isset($data['id']) ? (string) $data['id'] : $shipment->external_id,
            'tracking_number' => $trackingNumber ?: $shipment->tracking_number,
            'status' => $status,
            'oms_status' => $omsStatus,
            'status_changed_at' => $previousStatus !== $status ? $statusOccurredAt : $shipment->status_changed_at,
            'oms_status_changed_at' => $previousOmsStatus !== $omsStatus
                ? $statusOccurredAt
                : $shipment->oms_status_changed_at,
            'parcel_template' => data_get($data, 'parcels.0.template', $shipment->parcel_template),
            'confirmed_at' => $status === Shipment::STATUS_CONFIRMED && ! $shipment->confirmed_at
                ? now()
                : $shipment->confirmed_at,
            'last_synced_at' => now(),
            'error_message' => null,
        ]);

        $this->applyParcelData($shipment, (array) ($data['parcels'] ?? []));

        if ($previousStatus !== $shipment->status || $previousTrackingNumber !== $shipment->tracking_number) {
            $shipment->events()->create([
                'event_type' => $previousStatus !== $shipment->status
                    ? 'shipment_status_changed'
                    : 'shipment_tracking_number_assigned',
                'status' => $shipment->status,
                'payload' => [
                    'source' => $eventType,
                    'old_status' => $previousStatus,
                    'new_status' => $shipment->status,
                    'old_oms_status' => $previousOmsStatus,
                    'new_oms_status' => $shipment->oms_status,
                    'external_id' => $shipment->external_id,
                    'tracking_number' => $shipment->tracking_number,
                    'courier_updated_at' => data_get($data, 'updated_at') ?? data_get($data, 'status_changed_at'),
                ],
                'occurred_at' => $statusOccurredAt,
            ]);
        }

        if ($previousOmsStatus !== $shipment->oms_status) {
            $shipment->order?->events()->create([
                'event_type' => 'shipment_status_changed',
                'title' => 'Status przesylki zmieniony',
                'description' => 'Zmieniono status przesylki z '
                    .Shipment::omsStatusLabelFor($previousOmsStatus)
                    .' na '
                    .Shipment::omsStatusLabelFor($shipment->oms_status),
                'payload' => [
                    'shipment_id' => $shipment->id,
                    'tracking_number' => $shipment->tracking_number,
                    'old_status' => $previousOmsStatus,
                    'new_status' => $shipment->oms_status,
                    'old_provider_status' => $previousStatus,
                    'new_provider_status' => $shipment->status,
                ],
            ]);

            ShipmentStatusChanged::dispatch(
                $shipment->fresh(),
                $previousOmsStatus,
                $shipment->oms_status,
                $previousStatus,
                $shipment->status,
            );
        }

        if (! $previousTrackingNumber && $shipment->tracking_number) {
            $shipment->order?->events()->create([
                'event_type' => 'shipment_created',
                'title' => 'Przesylka InPost utworzona',
                'description' => 'Numer nadania: '.$shipment->tracking_number,
                'payload' => [
                    'shipment_id' => $shipment->id,
                    'tracking_number' => $shipment->tracking_number,
                ],
            ]);
        }

        if (! $previousExternalId && $shipment->external_id) {
            ShipmentCreated::dispatch($shipment->fresh());
        }
    }

    private function applyParcelData(Shipment $shipment, array $apiParcels): void
    {
        if ($shipment->provider !== CourierAccount::PROVIDER_INPOST_COURIER || $apiParcels === []) {
            return;
        }

        $shipment->loadMissing('parcels');

        foreach (array_values($apiParcels) as $index => $apiParcel) {
            $parcel = $shipment->parcels->get($index);

            if (! $parcel || ! is_array($apiParcel)) {
                continue;
            }

            $parcel->update([
                'external_id' => isset($apiParcel['id']) ? (string) $apiParcel['id'] : $parcel->external_id,
                'tracking_number' => $apiParcel['tracking_number'] ?? $parcel->tracking_number,
            ]);
        }
    }

    private function statusOccurredAt(array $data): Carbon
    {
        $value = data_get($data, 'status_changed_at') ?? data_get($data, 'updated_at');

        if (filled($value)) {
            try {
                return Carbon::parse($value);
            } catch (\Throwable) {
                // The courier timestamp is optional; local time remains a safe fallback.
            }
        }

        return now();
    }
}
