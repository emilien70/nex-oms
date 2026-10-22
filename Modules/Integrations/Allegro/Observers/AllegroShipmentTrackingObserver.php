<?php

namespace Modules\Integrations\Allegro\Observers;

use Modules\Integrations\Allegro\Services\AllegroShipmentExportService;
use Modules\Integrations\Allegro\Support\AllegroShipmentNumbers;
use Modules\Shipments\Models\Shipment;
use Modules\Shipments\Models\ShipmentParcel;

class AllegroShipmentTrackingObserver
{
    public function __construct(private readonly AllegroShipmentExportService $exports) {}

    public function saved(Shipment|ShipmentParcel $model): void
    {
        $created = $model->getRawOriginal('id') === null;
        if (! $model->wasChanged(['tracking_number', 'tracking_numbers', 'carrier_code']) && ! $created) {
            return;
        }
        $shipment = $model instanceof Shipment ? $model : $model->shipment()->first();
        if (! $shipment) {
            return;
        }
        $current = AllegroShipmentNumbers::fromValues($shipment, $model->getAttributes());
        $previous = $created ? [] : AllegroShipmentNumbers::fromValues($shipment, $model->getRawOriginal());
        $new = array_diff_key($current, $previous);
        if ($new !== []) {
            $this->exports->observeNumbers($shipment->id, $new, $current);
        }
    }
}
