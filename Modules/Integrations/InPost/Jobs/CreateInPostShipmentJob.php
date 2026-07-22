<?php

namespace Modules\Integrations\InPost\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\Integrations\InPost\Exceptions\InPostApiException;
use Modules\Integrations\InPost\Jobs\Concerns\UsesInPostApiMiddleware;
use Modules\Shipments\Models\Shipment;
use Modules\Shipments\Services\CourierDriverRegistry;
use Modules\Shipments\Services\ShipmentCreationAttemptService;
use Modules\Shipments\Support\ShipmentQueue;
use Throwable;

class CreateInPostShipmentJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, UsesInPostApiMiddleware;

    public int $tries = 100;

    public int $maxExceptions = 1;

    public int $uniqueFor = 300;

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

        if (! $shipment || in_array($shipment->status, [Shipment::STATUS_CANCELLED, Shipment::STATUS_CANCELLED_LOCALLY, 'canceled'], true)) {
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

        $isKnownApiRejection = $exception instanceof InPostApiException
            && $exception->statusCode !== null
            && $exception->statusCode >= 400
            && $exception->statusCode < 500;
        $isLocalValidationError = $exception instanceof \DomainException;
        $isLocalExecutionError = $exception instanceof \Error;
        $outcomeUnknown = ! $isKnownApiRejection && ! $isLocalValidationError && ! $isLocalExecutionError;
        $message = $exception?->getMessage() ?? 'Nie udalo sie utworzyc przesylki InPost.';

        if ($outcomeUnknown) {
            $message .= ' Wynik nadania jest niepewny - przed ponowieniem sprawdz panel InPost.';
        }

        app(ShipmentCreationAttemptService::class)->fail($shipment, $message, $outcomeUnknown);
    }
}
