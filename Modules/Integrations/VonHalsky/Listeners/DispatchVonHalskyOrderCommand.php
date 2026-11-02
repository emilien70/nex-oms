<?php

namespace Modules\Integrations\VonHalsky\Listeners;

use App\Events\OrderStatusChanged;
use Modules\Integrations\VonHalsky\Services\VonHalskyOrderCommandService;

class DispatchVonHalskyOrderCommand
{
    public function __construct(private readonly VonHalskyOrderCommandService $commands) {}

    public function handle(OrderStatusChanged $event): void
    {
        if (! str_starts_with($event->source, 'von_halsky')) {
            $this->commands->enqueue($event->order->id, $event->newStatus, $event->eventId);
        }
    }
}
