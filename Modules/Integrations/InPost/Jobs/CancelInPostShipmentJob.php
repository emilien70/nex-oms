<?php

namespace Modules\Integrations\InPost\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\Integrations\InPost\Jobs\Concerns\UsesInPostApiMiddleware;
use Modules\Shipments\Models\Shipment;
use Modules\Shipments\Services\CourierDriverRegistry;
use Throwable;

class CancelInPostShipmentJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, UsesInPostApiMiddleware;

    public int $tries = 100;

    public int $maxExceptions = 1;

    public bool $deleteWhenMissingModels = true;

    public function __construct(public Shipment $shipment)
    {
        $this->onQueue('integrations');
    }

    public function handle(CourierDriverRegistry $drivers): void
    {
        $shipment = $this->shipment->fresh();

        if ($shipment) {
            $drivers->forShipment($shipment)->cancel($shipment);
        }
    }

    public function failed(?Throwable $exception): void
    {
        $this->shipment->fresh()?->update([
            'error_message' => $exception?->getMessage() ?? 'Nie udalo sie anulowac przesylki InPost.',
        ]);
    }
}
