<?php

namespace Modules\Integrations\InPost\Jobs;

use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\Integrations\InPost\Exceptions\InPostApiException;
use Modules\Integrations\InPost\Jobs\Concerns\UsesInPostApiMiddleware;
use Modules\Integrations\InPost\Services\InPostShipmentOperations;
use Modules\Shipments\Jobs\Concerns\TracksShipmentConfirmation;
use Modules\Shipments\Models\Shipment;
use Modules\Shipments\Services\ShipmentCreationAttemptService;
use Modules\Shipments\Support\ShipmentQueue;
use Throwable;

class ResolveInPostShipmentJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, TracksShipmentConfirmation, UsesInPostApiMiddleware;

    public int $tries = 240;

    public int $maxExceptions = 3;

    public int $uniqueFor = 960;

    public array $backoff = [10, 30, 60];

    public bool $deleteWhenMissingModels = true;

    public function __construct(public Shipment $shipment)
    {
        $this->onQueue(ShipmentQueue::ACTIONS);
        $this->confirmationStartedAt = CarbonImmutable::now()->getTimestamp();
    }

    public function uniqueId(): string
    {
        return (string) $this->shipment->id;
    }

    public function handle(InPostShipmentOperations $operations): void
    {
        $shipment = $this->shipment->fresh();
        if ($this->confirmationFinished($shipment)) {
            return;
        }

        if ($this->confirmationExpired()) {
            $this->failed(null);

            return;
        }

        try {
            $operations->refresh($shipment);
        } catch (InPostApiException $exception) {
            if ($exception->statusCode === null || in_array($exception->statusCode, [404, 408, 429], true)
                || $exception->statusCode >= 500) {
                $this->releaseConfirmation(max(10, $exception->retryAfterSeconds ?? 0));
            } else {
                $this->failed($exception);
            }

            return;
        }

        if (! $this->confirmationFinished($shipment)) {
            $this->releaseConfirmation();
        }
    }

    public function failed(?Throwable $exception): void
    {
        $shipment = $this->shipment->fresh();
        if ($this->confirmationFinished($shipment)) {
            return;
        }

        app(ShipmentCreationAttemptService::class)->fail($shipment,
            'InPost nie udostepnil numeru nadawczego. Sprawdz przesylke w panelu InPost przed kolejna proba nadania.', true);
    }
}
