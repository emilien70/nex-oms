<?php

namespace Modules\Shipments\Services;

use DomainException;
use Modules\Shipments\Models\Shipment;

class ShipmentCancellationService
{
    public function __construct(
        private readonly ShipmentDeletionService $deletionService,
        private readonly CourierDriverRegistry $drivers,
    ) {}

    public function request(Shipment $shipment, bool $localOnly = false): void
    {
        if ($localOnly) {
            $this->deleteFromOms($shipment);

            return;
        }

        if (! $shipment->external_id) {
            $this->deleteFromOms($shipment);

            return;
        }

        $driver = $this->drivers->forShipment($shipment);

        if (! $driver->canCancel($shipment)) {
            throw new DomainException('Integracja kurierska nie pozwala anulowac tej przesylki przez API. Mozesz anulowac ja lokalnie w NEX-OMS.');
        }

        $driver->dispatchCancel($shipment);
    }

    public function requestWithLocalFallback(Shipment $shipment): void
    {
        if (! $shipment->external_id) {
            $this->deleteFromOms($shipment);

            return;
        }

        $driver = $this->drivers->forShipment($shipment);

        if ($driver->canCancel($shipment)) {
            $driver->dispatchCancel($shipment);

            return;
        }

        $this->deleteFromOms($shipment);
    }

    private function deleteFromOms(Shipment $shipment): void
    {
        if (! $shipment->canCancelLocally()) {
            throw new DomainException('Tej przesylki nie mozna usunac z NEX-OMS.');
        }

        $this->deletionService->delete($shipment);
    }
}
