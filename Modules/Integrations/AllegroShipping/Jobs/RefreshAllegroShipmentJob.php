<?php

namespace Modules\Integrations\AllegroShipping\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\Integrations\AllegroShipping\Jobs\Concerns\UsesAllegroShippingApiMiddleware;
use Modules\Shipments\Models\Shipment;
use Modules\Shipments\Services\CourierDriverRegistry;

class RefreshAllegroShipmentJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, UsesAllegroShippingApiMiddleware;

    public int $tries = 5;

    public int $uniqueFor = 120;

    public array $backoff = [30, 120, 300];

    public bool $deleteWhenMissingModels = true;

    public function __construct(public Shipment $shipment)
    {
        $this->onQueue('integrations');
    }

    public function uniqueId(): string
    {
        return (string) $this->shipment->id;
    }

    public function handle(CourierDriverRegistry $drivers): void
    {
        $shipment = $this->shipment->fresh();
        if ($shipment && ! $shipment->hasTerminalOmsStatus()) {
            $drivers->forShipment($shipment)->refresh($shipment);
        }
    }
}
