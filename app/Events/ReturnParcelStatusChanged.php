<?php

namespace App\Events;

use App\Models\OrderReturnParcel;
use App\Models\OrderReturnParcelEvent;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ReturnParcelStatusChanged implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(public readonly OrderReturnParcel $parcel, public readonly OrderReturnParcelEvent $event, public readonly ?string $oldStatus) {}

    public function name(): string
    {
        return 'return.parcel_status_changed';
    }

    public function payload(): array
    {
        return ['event_id' => $this->event->event_id, 'event_name' => $this->name(),
            'order_id' => $this->parcel->orderReturn->order_id, 'order_return_id' => $this->parcel->order_return_id,
            'return_parcel_id' => $this->parcel->id, 'return_parcel_event_id' => $this->event->id,
            'tracking_identity' => $this->parcel->identity(), 'old_status' => $this->oldStatus,
            'new_status' => $this->parcel->tracking_status, 'shipment_direction' => 'return',
            'provider' => $this->parcel->trackingCarrier() === 'INPOST' ? 'inpost_lockers' : 'allegro_shipping'];
    }
}
