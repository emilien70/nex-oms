<?php

namespace Modules\Shipments\Services;

use DomainException;
use Illuminate\Support\Facades\DB;
use Modules\Shipments\Events\ShipmentRetryQueued;
use Modules\Shipments\Models\Shipment;

class ShipmentRetryService
{
    public function __construct(private readonly CourierDriverRegistry $drivers) {}

    public function retryCreation(Shipment $shipment): void
    {
        if (! $shipment->canRetryCreation()) {
            if ($shipment->requiresCreationVerification()) {
                throw new DomainException('Nie mozna bezpiecznie ponowic nadania. Sprawdz w panelu InPost, czy paczka nie zostala juz utworzona.');
            }

            throw new DomainException('Ta przesylka nie oczekuje na ponowienie nadania.');
        }

        $driver = $this->drivers->forShipment($shipment);

        DB::transaction(function () use ($shipment, $driver): void {
            $previousStatus = $shipment->status;

            $shipment->update([
                'status' => Shipment::STATUS_QUEUED,
                'status_changed_at' => now(),
                'error_message' => null,
            ]);

            $shipment->events()->create([
                'event_type' => 'shipment_retry_queued',
                'status' => Shipment::STATUS_QUEUED,
                'payload' => [
                    'old_status' => $previousStatus,
                    'new_status' => Shipment::STATUS_QUEUED,
                    'request_uuid' => $shipment->request_uuid,
                ],
                'occurred_at' => now(),
            ]);

            $shipment->order?->events()->create([
                'event_type' => 'shipment_retry_queued',
                'title' => 'Ponowiono nadanie przesylki',
                'description' => 'Przesylka zostala ponownie dodana do kolejki integracji kurierskiej',
                'payload' => ['shipment_id' => $shipment->id],
            ]);

            ShipmentRetryQueued::dispatch($shipment->fresh());
            $driver->dispatchCreate($shipment);
        });
    }
}
