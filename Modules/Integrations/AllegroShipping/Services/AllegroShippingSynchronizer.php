<?php

namespace Modules\Integrations\AllegroShipping\Services;

use DomainException;
use Illuminate\Support\Carbon;
use Modules\Shipments\Events\ShipmentCreated;
use Modules\Shipments\Events\ShipmentStatusChanged;
use Modules\Shipments\Models\Shipment;
use Modules\Shipments\Services\ShipmentStatusMapper;

class AllegroShippingSynchronizer
{
    public function __construct(private readonly ShipmentStatusMapper $statusMapper) {}

    public function applyCommand(Shipment $shipment, array $command): bool
    {
        $status = strtoupper((string) ($command['status'] ?? 'IN_PROGRESS'));

        if ($status === 'ERROR') {
            $message = collect((array) ($command['errors'] ?? []))
                ->map(fn (mixed $error): ?string => is_array($error) ? ($error['userMessage'] ?? $error['message'] ?? null) : null)
                ->filter()->implode(' ');
            throw new DomainException($message ?: 'Allegro odrzucilo utworzenie przesylki.');
        }

        if ($status !== 'SUCCESS' || blank($command['shipmentId'] ?? null)) {
            $shipment->update([
                'status' => 'allegro_command_pending',
                'status_changed_at' => now(),
                'error_message' => null,
            ]);

            return false;
        }

        $shipment->update(['external_id' => (string) $command['shipmentId']]);

        return true;
    }

    public function applyDetails(Shipment $shipment, array $details): void
    {
        $shipment->loadMissing(['order', 'parcels']);
        $apiPackages = collect((array) ($details['packages'] ?? []))->values();
        $tracking = data_get($apiPackages->first(), 'waybill')
            ?: data_get($apiPackages->first(), 'transportingInfo.0.carrierWaybill');
        $cancelled = filled($details['canceledDate'] ?? null);
        $previousStatus = $shipment->status;
        $wasConfirmed = filled($shipment->confirmed_at);
        $nextStatus = $cancelled ? Shipment::STATUS_CANCELLED : $this->detailsStatus($previousStatus);
        $nextOmsStatus = $this->statusMapper->map($shipment->provider, $nextStatus);

        $shipment->update([
            'external_id' => (string) ($details['id'] ?? $shipment->external_id),
            'carrier_code' => (string) ($details['carrier'] ?? data_get($apiPackages->first(), 'transportingInfo.0.carrierId') ?? ''),
            'tracking_number' => $tracking ?: $shipment->tracking_number,
            'status' => $nextStatus,
            'oms_status' => $nextOmsStatus,
            'status_changed_at' => $previousStatus !== $nextStatus ? now() : $shipment->status_changed_at,
            'confirmed_at' => $cancelled ? $shipment->confirmed_at : ($shipment->confirmed_at ?: now()),
            'cancelled_at' => $cancelled ? now() : null,
            'last_synced_at' => now(),
            'error_message' => null,
        ]);

        foreach ($shipment->parcels->values() as $index => $parcel) {
            $waybill = data_get($apiPackages->get($index), 'waybill')
                ?: data_get($apiPackages->get($index), 'transportingInfo.0.carrierWaybill');
            if ($waybill) {
                $parcel->update(['external_id' => (string) $waybill, 'tracking_number' => (string) $waybill]);
            }
        }

        if (! $wasConfirmed && ! $cancelled) {
            $shipment->events()->create([
                'event_type' => 'shipment_created',
                'status' => Shipment::STATUS_CONFIRMED,
                'payload' => ['shipment_id' => $shipment->external_id, 'tracking_number' => $tracking],
                'occurred_at' => now(),
            ]);
            $shipment->order?->events()->create([
                'event_type' => 'shipment_created',
                'title' => 'Przesylka Wysylam z Allegro utworzona',
                'description' => 'Numer nadania: '.($tracking ?: 'oczekuje'),
                'payload' => ['shipment_id' => $shipment->id, 'tracking_number' => $tracking],
            ]);
            ShipmentCreated::dispatch($shipment->fresh());
        }
    }

    public function applyTracking(Shipment $shipment, array $tracking): void
    {
        $statuses = collect((array) data_get($tracking, 'waybills.0.trackingDetails.statuses', []))
            ->filter(fn (mixed $status): bool => is_array($status) && filled($status['code'] ?? null))
            ->sortBy(fn (array $status): string => (string) ($status['occurredAt'] ?? ''))
            ->values();
        $latest = $statuses->last();

        if (! is_array($latest)) {
            $shipment->update(['last_synced_at' => now(), 'error_message' => null]);

            return;
        }

        $previousStatus = (string) $shipment->status;
        $previousOmsStatus = (string) ($shipment->oms_status ?: $this->statusMapper->map($shipment->provider, $previousStatus));
        $status = strtolower((string) $latest['code']);
        $omsStatus = $this->statusMapper->map($shipment->provider, $status);
        $occurredAt = $this->occurredAt($latest['occurredAt'] ?? null);

        $shipment->update([
            'status' => $status,
            'oms_status' => $omsStatus,
            'status_changed_at' => $previousStatus !== $status ? $occurredAt : $shipment->status_changed_at,
            'oms_status_changed_at' => $previousOmsStatus !== $omsStatus ? $occurredAt : $shipment->oms_status_changed_at,
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
                    'description' => $latest['description'] ?? null,
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

    private function detailsStatus(?string $currentStatus): string
    {
        return in_array($currentStatus, [
            Shipment::STATUS_QUEUED,
            Shipment::STATUS_CREATED,
            Shipment::STATUS_CONFIRMED,
            'allegro_command_pending',
        ], true) ? Shipment::STATUS_CONFIRMED : (string) $currentStatus;
    }

    private function occurredAt(mixed $value): Carbon
    {
        if (is_string($value) && $value !== '') {
            try {
                return Carbon::parse($value);
            } catch (\Throwable) {
                // Allegro timestamp is optional; local time is a safe fallback.
            }
        }

        return now();
    }
}
