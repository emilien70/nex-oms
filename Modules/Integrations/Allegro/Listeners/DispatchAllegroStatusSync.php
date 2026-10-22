<?php

namespace Modules\Integrations\Allegro\Listeners;

use App\Events\OrderStatusChanged;
use Modules\Integrations\Allegro\Services\AllegroOutboundStatusSyncService;

class DispatchAllegroStatusSync
{
    public function __construct(private readonly AllegroOutboundStatusSyncService $sync) {}

    public function handle(OrderStatusChanged $event): void
    {
        if (! str_starts_with($event->source, 'allegro')) {
            $this->sync->enqueue($event->order->id, $event->newStatus, $event->eventId);
        }
    }
}
