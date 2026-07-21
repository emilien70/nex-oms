<?php

namespace Modules\Integrations\AllegroShipping\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\Integrations\AllegroShipping\Jobs\Concerns\UsesAllegroShippingApiMiddleware;
use Modules\Integrations\AllegroShipping\Services\AllegroShippingOperations;
use Modules\Shipments\Events\ShipmentCreationFailed;
use Modules\Shipments\Models\Shipment;
use Throwable;

class ResolveAllegroShipmentCommandJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, UsesAllegroShippingApiMiddleware;

    public int $tries = 30;

    public bool $deleteWhenMissingModels = true;

    public function __construct(public Shipment $shipment)
    {
        $this->onQueue('integrations');
    }

    public function handle(AllegroShippingOperations $operations): void
    {
        $shipment = $this->shipment->fresh();
        if ($shipment && blank($shipment->external_id) && ! $operations->resolveCreation($shipment)) {
            $this->release(5);
        }
    }

    public function failed(?Throwable $exception): void
    {
        $shipment = $this->shipment->fresh();
        if (! $shipment || filled($shipment->external_id)) {
            return;
        }

        $message = 'Allegro nie potwierdzilo wyniku tworzenia przesylki w wymaganym czasie. Sprawdz panel Wysylam z Allegro przed kolejna proba.';
        if ($exception && $exception->getMessage() !== '') {
            $message .= ' '.$exception->getMessage();
        }

        $shipment->update([
            'status' => Shipment::STATUS_CREATION_UNKNOWN,
            'status_changed_at' => now(),
            'error_message' => $message,
        ]);
        $shipment->events()->create([
            'event_type' => 'shipment_creation_failed',
            'status' => Shipment::STATUS_CREATION_UNKNOWN,
            'payload' => ['outcome_unknown' => true, 'error_message' => $message],
            'occurred_at' => now(),
        ]);
        $shipment->order?->events()->create([
            'event_type' => 'shipment_creation_failed',
            'title' => 'Wynik nadania Wysylam z Allegro jest niepewny',
            'description' => $message,
            'payload' => ['shipment_id' => $shipment->id, 'outcome_unknown' => true],
        ]);
        ShipmentCreationFailed::dispatch($shipment->fresh(), true);
    }
}
