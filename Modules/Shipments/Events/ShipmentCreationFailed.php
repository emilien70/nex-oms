<?php

namespace Modules\Shipments\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;
use Modules\Shipments\Models\Shipment;
use Modules\Shipments\Models\ShipmentCreationAttempt;

class ShipmentCreationFailed implements ShouldDispatchAfterCommit
{
    use Dispatchable;
    use SerializesModels;

    public readonly string $eventId;

    public function __construct(
        public readonly ShipmentCreationAttempt $attempt,
        public readonly bool $outcomeUnknown,
    ) {
        $this->eventId = (string) Str::uuid();
    }

    public function name(): string
    {
        return 'shipment.creation_failed';
    }

    public function payload(): array
    {
        return [
            'event_id' => $this->eventId,
            'event_name' => $this->name(),
            'shipment_attempt_id' => $this->attempt->id,
            'order_id' => $this->attempt->order_id,
            'provider' => $this->attempt->provider,
            'status' => Shipment::OMS_STATUS_PROBLEM,
            'provider_status' => $this->attempt->status,
            'outcome_unknown' => $this->outcomeUnknown,
            'error_message' => $this->attempt->error_message,
        ];
    }
}
