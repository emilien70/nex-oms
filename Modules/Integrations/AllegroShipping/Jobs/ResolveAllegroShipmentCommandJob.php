<?php

namespace Modules\Integrations\AllegroShipping\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\Integrations\AllegroShipping\Jobs\Concerns\UsesAllegroShippingApiMiddleware;
use Modules\Integrations\AllegroShipping\Services\AllegroShippingOperations;
use Modules\Shipments\Models\Shipment;
use Modules\Shipments\Services\ShipmentCreationAttemptService;
use Modules\Shipments\Support\ShipmentQueue;
use Throwable;

class ResolveAllegroShipmentCommandJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, UsesAllegroShippingApiMiddleware;

    public int $tries = 30;

    public bool $deleteWhenMissingModels = true;

    public function __construct(public Shipment $shipment)
    {
        $this->onQueue(ShipmentQueue::ACTIONS);
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

        app(ShipmentCreationAttemptService::class)->fail($shipment, $message, true);
    }
}
