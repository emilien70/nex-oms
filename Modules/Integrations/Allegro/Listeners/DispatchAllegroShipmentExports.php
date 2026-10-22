<?php

namespace Modules\Integrations\Allegro\Listeners;

use App\Events\OrderStatusChanged;
use Modules\Integrations\Allegro\Services\AllegroShipmentExportService;

class DispatchAllegroShipmentExports
{
    public function __construct(private readonly AllegroShipmentExportService $exports) {}

    public function handle(OrderStatusChanged $event): void
    {
        if (! str_starts_with($event->source, 'allegro')) {
            $this->exports->statusChanged($event->order->id);
        }
    }
}
