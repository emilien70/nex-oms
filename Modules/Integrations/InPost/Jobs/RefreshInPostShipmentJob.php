<?php

namespace Modules\Integrations\InPost\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\Integrations\InPost\Jobs\Concerns\UsesInPostApiMiddleware;
use Modules\Shipments\Models\Shipment;
use Modules\Shipments\Services\CourierDriverRegistry;
use Modules\Shipments\Support\ShipmentQueue;
use Throwable;

class RefreshInPostShipmentJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, UsesInPostApiMiddleware;

    public int $tries = 100;

    public int $maxExceptions = 3;

    public int $uniqueFor = 120;

    public array $backoff = [10, 30, 60];

    public bool $deleteWhenMissingModels = true;

    public function __construct(public Shipment $shipment)
    {
        $this->onQueue(ShipmentQueue::SYNC);
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

    public function failed(?Throwable $exception): void
    {
        $this->shipment->fresh()?->update([
            'error_message' => $exception?->getMessage() ?? 'Nie udalo sie odswiezyc przesylki InPost.',
        ]);
    }
}
