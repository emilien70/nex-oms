<?php

namespace Modules\Integrations\AllegroShipping\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\Integrations\AllegroShipping\Exceptions\AllegroShippingApiException;
use Modules\Integrations\AllegroShipping\Jobs\Concerns\UsesAllegroShippingApiMiddleware;
use Modules\Shipments\Models\Shipment;
use Modules\Shipments\Services\CourierDriverRegistry;
use Modules\Shipments\Services\ShipmentCreationAttemptService;
use Modules\Shipments\Support\ShipmentQueue;
use Throwable;

class CreateAllegroShipmentJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, UsesAllegroShippingApiMiddleware;

    public int $tries = 4;

    public int $maxExceptions = 1;

    public int $uniqueFor = 300;

    public array $backoff = [15, 60, 180];

    public bool $deleteWhenMissingModels = true;

    public function __construct(public Shipment $shipment)
    {
        $this->onQueue(ShipmentQueue::ACTIONS);
    }

    public function uniqueId(): string
    {
        return (string) $this->shipment->id;
    }

    public function handle(CourierDriverRegistry $drivers): void
    {
        $shipment = $this->shipment->fresh();
        if ($shipment && blank($shipment->external_id)) {
            $drivers->forShipment($shipment)->create($shipment);
        }
    }

    public function failed(?Throwable $exception): void
    {
        $shipment = $this->shipment->fresh();
        if (! $shipment) {
            return;
        }

        $knownRejection = $exception instanceof AllegroShippingApiException
            && $exception->statusCode !== null
            && $exception->statusCode >= 400
            && $exception->statusCode < 500;
        $localError = $exception instanceof \DomainException || $exception instanceof \Error;
        $outcomeUnknown = ! $knownRejection && ! $localError;
        $message = $exception?->getMessage() ?? 'Nie udalo sie utworzyc przesylki Wysylam z Allegro.';

        if ($outcomeUnknown) {
            $message .= ' Wynik nadania jest niepewny - sprawdz panel Wysylam z Allegro przed kolejna proba.';
        }

        app(ShipmentCreationAttemptService::class)->fail($shipment, $message, $outcomeUnknown);
    }
}
