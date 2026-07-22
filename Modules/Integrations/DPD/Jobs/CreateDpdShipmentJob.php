<?php

namespace Modules\Integrations\DPD\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\Integrations\DPD\Exceptions\DpdApiException;
use Modules\Integrations\DPD\Jobs\Concerns\UsesDpdApiMiddleware;
use Modules\Shipments\Models\Shipment;
use Modules\Shipments\Services\ShipmentCreationAttemptService;
use Modules\Shipments\Services\CourierDriverRegistry;
use Throwable;

class CreateDpdShipmentJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, UsesDpdApiMiddleware;

    public int $tries = 5;

    public int $maxExceptions = 1;

    public int $uniqueFor = 300;

    public array $backoff = [15, 60, 180];

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

        if (! $shipment || in_array($shipment->status, [Shipment::STATUS_CANCELLED, Shipment::STATUS_CANCELLED_LOCALLY], true)) {
            return;
        }

        $drivers->forShipment($shipment)->create($shipment);
    }

    public function failed(?Throwable $exception): void
    {
        $shipment = $this->shipment->fresh();

        if (! $shipment) {
            return;
        }

        $knownRejection = $exception instanceof DpdApiException
            && $exception->statusCode !== null
            && $exception->statusCode >= 400
            && $exception->statusCode < 500;
        $localError = $exception instanceof \DomainException || $exception instanceof \Error;
        $outcomeUnknown = ! $knownRejection && ! $localError;
        $message = $exception?->getMessage() ?? 'Nie udalo sie utworzyc przesylki DPD.';

        if ($outcomeUnknown) {
            $message .= ' Wynik nadania jest niepewny - przed ponowieniem sprawdz panel DPD.';
        }

        app(ShipmentCreationAttemptService::class)->fail($shipment, $message, $outcomeUnknown);
    }
}
