<?php

namespace App\Events;

use App\Models\Order;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

class OrderStatusChanged implements ShouldDispatchAfterCommit
{
    use Dispatchable;
    use SerializesModels;

    public readonly string $eventId;

    public function __construct(
        public readonly Order $order,
        public readonly string $oldStatus,
        public readonly string $newStatus,
        public readonly string $source,
    ) {
        $this->eventId = (string) Str::uuid();
    }

    public function name(): string
    {
        return 'order.status_changed';
    }

    public function payload(): array
    {
        return [
            'event_id' => $this->eventId,
            'event_name' => $this->name(),
            'order_id' => $this->order->id,
            'old_status' => $this->oldStatus,
            'new_status' => $this->newStatus,
            'source' => $this->source,
        ];
    }
}
