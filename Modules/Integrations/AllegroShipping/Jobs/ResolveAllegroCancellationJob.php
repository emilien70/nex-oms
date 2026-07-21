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
use Throwable;

class ResolveAllegroCancellationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, UsesAllegroShippingApiMiddleware;

    public int $tries = 30;

    public bool $deleteWhenMissingModels = true;

    public function __construct(public Shipment $shipment, public string $commandId)
    {
        $this->onQueue('integrations');
    }

    public function handle(AllegroShippingOperations $operations): void
    {
        if (($shipment = $this->shipment->fresh()) && ! $operations->resolveCancellation($shipment, $this->commandId)) {
            $this->release(5);
        }
    }

    public function failed(?Throwable $exception): void
    {
        $shipment = $this->shipment->fresh();
        if (! $shipment) {
            return;
        }

        $message = 'Allegro nie potwierdzilo anulowania przesylki w wymaganym czasie.';
        if ($exception && $exception->getMessage() !== '') {
            $message .= ' '.$exception->getMessage();
        }

        $shipment->update(['error_message' => $message]);
        $shipment->events()->create([
            'event_type' => 'shipment_cancellation_failed',
            'status' => $shipment->status,
            'payload' => ['error_message' => $message],
            'occurred_at' => now(),
        ]);
    }
}
