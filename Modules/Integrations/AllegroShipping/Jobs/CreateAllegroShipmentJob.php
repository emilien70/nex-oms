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
use Modules\Shipments\Events\ShipmentCreationFailed;
use Modules\Shipments\Models\Shipment;
use Modules\Shipments\Services\CourierDriverRegistry;
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
        $this->onQueue('integrations');
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
        $status = $outcomeUnknown ? Shipment::STATUS_CREATION_UNKNOWN : Shipment::STATUS_CREATION_FAILED;
        $message = $exception?->getMessage() ?? 'Nie udalo sie utworzyc przesylki Wysylam z Allegro.';

        if ($outcomeUnknown) {
            $message .= ' Wynik nadania jest niepewny - sprawdz panel Wysylam z Allegro przed kolejna proba.';
        }

        $shipment->update(['status' => $status, 'status_changed_at' => now(), 'error_message' => $message]);
        $shipment->events()->create([
            'event_type' => 'shipment_creation_failed',
            'status' => $status,
            'payload' => ['outcome_unknown' => $outcomeUnknown, 'error_message' => $message],
            'occurred_at' => now(),
        ]);
        $shipment->order?->events()->create([
            'event_type' => 'shipment_creation_failed',
            'title' => 'Nie udalo sie utworzyc przesylki Wysylam z Allegro',
            'description' => $message,
            'payload' => ['shipment_id' => $shipment->id, 'outcome_unknown' => $outcomeUnknown],
        ]);
        ShipmentCreationFailed::dispatch($shipment->fresh(), $outcomeUnknown);
    }
}
