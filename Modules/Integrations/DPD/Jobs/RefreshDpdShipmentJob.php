<?php

namespace Modules\Integrations\DPD\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\Integrations\DPD\Jobs\Concerns\UsesDpdApiMiddleware;
use Modules\Shipments\Models\Shipment;
use Modules\Shipments\Services\CourierDriverRegistry;
use Throwable;

class RefreshDpdShipmentJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, UsesDpdApiMiddleware;

    public int $tries = 5;

    public int $maxExceptions = 3;

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

    public function failed(?Throwable $exception): void
    {
        $this->shipment->fresh()?->update([
            'error_message' => $exception?->getMessage() ?? 'Nie udalo sie odswiezyc przesylki DPD.',
        ]);
    }
}
