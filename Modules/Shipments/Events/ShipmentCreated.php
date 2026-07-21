<?php

namespace Modules\Shipments\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;
use Modules\Shipments\Models\Shipment;

class ShipmentCreated implements ShouldDispatchAfterCommit
{
    use Dispatchable;
    use SerializesModels;

    public readonly string $eventId;

    public function __construct(public readonly Shipment $shipment)
    {
        $this->eventId = (string) Str::uuid();
    }

    public function name(): string
    {
        return 'shipment.created';
    }

    public function payload(): array
    {
        return [
            'event_id' => $this->eventId,
            'event_name' => $this->name(),
            'shipment_id' => $this->shipment->id,
            'order_id' => $this->shipment->order_id,
            'provider' => $this->shipment->provider,
            'tracking_number' => $this->shipment->tracking_number,
            'status' => $this->shipment->oms_status,
            'provider_status' => $this->shipment->status,
        ];
    }
}
