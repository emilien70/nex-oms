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
use Modules\Shipments\Events\ShipmentCreationFailed;
use Modules\Shipments\Models\Shipment;
use Modules\Shipments\Services\CourierDriverRegistry;
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
        $this->onQueue('integrations');
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
        $status = $outcomeUnknown
            ? Shipment::STATUS_CREATION_UNKNOWN
            : Shipment::STATUS_CREATION_FAILED;
        $message = $exception?->getMessage() ?? 'Nie udalo sie utworzyc przesylki InPost.';

        if ($outcomeUnknown) {
            $message .= ' Wynik nadania jest niepewny - przed ponowieniem sprawdz panel InPost.';
        }

        $shipment->update([
            'status' => $status,
            'status_changed_at' => now(),
            'error_message' => $message,
        ]);

        $shipment->events()->create([
            'event_type' => 'shipment_creation_failed',
            'status' => $status,
            'payload' => [
                'outcome_unknown' => $outcomeUnknown,
                'error_message' => $message,
            ],
            'occurred_at' => now(),
        ]);

        $shipment->order?->events()->create([
            'event_type' => 'shipment_creation_failed',
            'title' => 'Nie udalo sie utworzyc przesylki',
            'description' => $message,
            'payload' => [
                'shipment_id' => $shipment->id,
                'outcome_unknown' => $outcomeUnknown,
            ],
        ]);

        ShipmentCreationFailed::dispatch($shipment->fresh(), $outcomeUnknown);
    }
}
