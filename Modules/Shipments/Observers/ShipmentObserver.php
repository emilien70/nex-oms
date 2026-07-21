<?php

namespace Modules\Shipments\Observers;

use Modules\Shipments\Models\Shipment;
use Modules\Shipments\Services\ShipmentStatusMapper;

class ShipmentObserver
{
    public function __construct(private readonly ShipmentStatusMapper $statusMapper) {}

    public function saving(Shipment $shipment): void
    {
        if (! $shipment->isDirty('status')) {
            return;
        }

        $omsStatus = $this->statusMapper->map($shipment->provider, $shipment->status);

        if ($shipment->oms_status === $omsStatus) {
            return;
        }

        $shipment->oms_status = $omsStatus;

        if (! $shipment->isDirty('oms_status_changed_at')) {
            $shipment->oms_status_changed_at = now();
        }
    }
}
