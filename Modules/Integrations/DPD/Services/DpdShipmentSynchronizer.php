<?php

namespace Modules\Integrations\DPD\Services;

use Illuminate\Support\Carbon;
use Modules\Shipments\Events\ShipmentCreated;
use Modules\Shipments\Events\ShipmentStatusChanged;
use Modules\Shipments\Models\Shipment;
use Modules\Shipments\Services\ShipmentStatusMapper;

class DpdShipmentSynchronizer
{
    public function __construct(private readonly ShipmentStatusMapper $statusMapper) {}

    public function applyCreated(Shipment $shipment, array $data): void
    {
        $shipment->loadMissing(['order', 'parcels']);
        $sessionId = data_get($data, 'sessionId');
        $apiParcels = collect(data_get($data, 'packages.0.parcels', []))->values();
        $trackingNumber = data_get($apiParcels->first(), 'waybill');

        if (blank($sessionId) || blank($trackingNumber)) {
            throw new \DomainException('DPD nie zwrocilo numeru sesji lub numeru nadawczego.');
        }

        $shipment->update([
            'external_id' => (string) $sessionId,
            'tracking_number' => (string) $trackingNumber,
            'status' => '30103',
            'oms_status' => Shipment::OMS_STATUS_CREATED,
            'status_changed_at' => now(),
            'oms_status_changed_at' => now(),
            'confirmed_at' => now(),
            'last_synced_at' => now(),
            'error_message' => null,
        ]);

        foreach ($shipment->parcels->values() as $index => $parcel) {
            $waybill = data_get($apiParcels->get($index), 'waybill');
            if ($waybill) {
                $parcel->update([
                    'external_id' => (string) $waybill,
                    'tracking_number' => (string) $waybill,
                ]);
            }
        }

        $shipment->events()->create([
            'event_type' => 'shipment_created',
            'status' => '30103',
            'payload' => [
                'session_id' => (string) $sessionId,
                'tracking_number' => (string) $trackingNumber,
                'trace_id' => data_get($data, 'traceId'),
            ],
            'occurred_at' => now(),
        ]);

        $shipment->order?->events()->create([
            'event_type' => 'shipment_created',
            'title' => 'Przesylka DPD utworzona',
            'description' => 'Numer nadania: '.$trackingNumber,
            'payload' => ['shipment_id' => $shipment->id, 'tracking_number' => $trackingNumber],
        ]);

        ShipmentCreated::dispatch($shipment->fresh());
    }

    public function applyEvent(Shipment $shipment, ?array $event): void
    {
        $previousStatus = $shipment->status;
        $previousOmsStatus = $shipment->oms_status
            ?: $this->statusMapper->map($shipment->provider, $previousStatus);
        $status = (string) ($event['business_code'] ?? $previousStatus);
        $omsStatus = $this->statusMapper->map($shipment->provider, $status);
        $occurredAt = $this->eventTime($event['event_time'] ?? null);

        $shipment->update([
            'status' => $status,
            'oms_status' => $omsStatus,
            'status_changed_at' => $previousStatus !== $status ? $occurredAt : $shipment->status_changed_at,
            'oms_status_changed_at' => $previousOmsStatus !== $omsStatus
                ? $occurredAt
                : $shipment->oms_status_changed_at,
            'last_synced_at' => now(),
            'error_message' => null,
        ]);

        if ($previousStatus !== $status) {
            $shipment->events()->create([
                'event_type' => 'shipment_status_changed',
                'status' => $status,
                'payload' => [
                    'old_status' => $previousStatus,
                    'new_status' => $status,
                    'old_oms_status' => $previousOmsStatus,
                    'new_oms_status' => $omsStatus,
                    'description' => $event['description'] ?? null,
                    'depot' => $event['depot'] ?? null,
                ],
                'occurred_at' => $occurredAt,
            ]);
        }

        if ($previousOmsStatus !== $omsStatus) {
            $shipment->order?->events()->create([
                'event_type' => 'shipment_status_changed',
                'title' => 'Status przesylki zmieniony',
                'description' => 'Zmieniono status przesylki z '
                    .Shipment::omsStatusLabelFor($previousOmsStatus).' na '.Shipment::omsStatusLabelFor($omsStatus),
                'payload' => [
                    'shipment_id' => $shipment->id,
                    'tracking_number' => $shipment->tracking_number,
                    'old_status' => $previousOmsStatus,
                    'new_status' => $omsStatus,
                    'old_provider_status' => $previousStatus,
                    'new_provider_status' => $status,
                    'provider_description' => $event['description'] ?? null,
                ],
            ]);

            ShipmentStatusChanged::dispatch(
                $shipment->fresh(),
                $previousOmsStatus,
                $omsStatus,
                $previousStatus,
                $status,
            );
        }
    }

    private function eventTime(?string $value): Carbon
    {
        if (filled($value)) {
            try {
                return Carbon::parse($value);
            } catch (\Throwable) {
                // DPD event time is optional; local time is a safe fallback.
            }
        }

        return now();
    }
}
